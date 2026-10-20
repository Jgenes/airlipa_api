<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MerchantPayoutAccount;
use App\Models\PayoutInstruction;
use App\Models\ProtectedPayment;
use App\Services\PayoutSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayoutSettlementController extends Controller
{
    public function __construct(
        private readonly PayoutSettlementService $payoutService
    ) {}

    /**
     * GET /api/v1/payout-accounts
     */
    public function indexAccounts(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $accounts = MerchantPayoutAccount::where('merchant_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->map(fn (MerchantPayoutAccount $acc) => [
                'payout_account_id' => $acc->payout_account_id,
                'account_type' => $acc->account_type,
                'provider_code' => $acc->provider_code,
                'account_name' => $acc->account_name,
                'account_number_masked' => $acc->maskedAccountNumber(),
                'currency' => $acc->currency,
                'is_default' => $acc->is_default,
                'verification_status' => $acc->verification_status,
                'verified_at' => $acc->verified_at?->toIso8601String(),
            ]);

        return ApiResponse::success(
            message: 'Merchant payout accounts retrieved successfully.',
            data: ['payout_accounts' => $accounts]
        );
    }

    /**
     * POST /api/v1/payout-accounts
     * Register a verified merchant payout destination with step-up password authentication (BPA-DATA-03).
     */
    public function storeAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_type' => ['nullable', 'string', 'in:MNO_WALLET,BANK_ACCOUNT'],
            'provider_code' => ['required', 'string', 'max:32'],
            'account_name' => ['required', 'string', 'max:140'],
            'account_number' => ['required', 'string', 'min:6', 'max:64'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_default' => ['nullable', 'boolean'],
            'step_up_password' => ['required', 'string'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $account = $this->payoutService->registerPayoutAccount($user, $validated);

        return ApiResponse::success(
            message: 'Merchant payout account verified and registered successfully.',
            data: [
                'payout_account_id' => $account->payout_account_id,
                'account_type' => $account->account_type,
                'provider_code' => $account->provider_code,
                'account_name' => $account->account_name,
                'account_number_masked' => $account->maskedAccountNumber(),
                'currency' => $account->currency,
                'is_default' => $account->is_default,
                'verification_status' => $account->verification_status,
            ],
            nextStep: 'settle_payout',
            status: 201
        );
    }

    /**
     * POST /api/v1/protected-payments/{protectedPaymentId}/settle-payout
     * Execute merchant payout settlement for released, undisputed funds (M14, FR-016).
     */
    public function settlePayout(Request $request, string $protectedPaymentId): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'payout_account_id' => ['nullable', 'string', 'max:64'],
            'step_up_password' => ['nullable', 'string'],
            'handover_otp' => ['nullable', 'string', 'size:6'],
        ]);

        $idempotencyKey = (string) ($request->header('Idempotency-Key') ?: ($validated['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            return ApiResponse::error(
                'An Idempotency-Key header or idempotency_key field is required for payout settlement.',
                ['idempotency_key' => ['The idempotency key is required.']],
                null,
                422
            );
        }

        /** @var \App\Models\User $user */
        $user = $request->user();
        $payment = ProtectedPayment::where('protected_payment_id', $protectedPaymentId)->firstOrFail();

        $result = $this->payoutService->settleMerchantPayout(
            payment: $payment,
            actor: $user,
            idempotencyKey: $idempotencyKey,
            payoutAccountId: $validated['payout_account_id'] ?? null,
            data: $validated
        );

        $instruction = $result['instruction'];

        return ApiResponse::success(
            message: $result['idempotent_replay']
                ? 'Idempotent replay: returning existing payout instruction.'
                : (($result['retry_scheduled'] ?? false)
                    ? 'Provider did not settle the payout; it has been queued for automatic retry with backoff.'
                    : 'Merchant settlement payout executed and recorded in the sub-ledger.'),
            data: [
                'idempotent_replay' => $result['idempotent_replay'],
                'payout_instruction' => [
                    'payout_instruction_id' => $instruction->payout_instruction_id,
                    'batch_reference' => $result['batch']?->batch_reference,
                    'protected_payment_id' => $payment->protected_payment_id,
                    'provider_code' => $instruction->provider_code,
                    'currency' => $instruction->currency,
                    'gross_released_minor' => (int) $instruction->gross_released_minor,
                    'merchant_fee_minor' => (int) $instruction->merchant_fee_minor,
                    'merchant_tax_minor' => (int) $instruction->merchant_tax_minor,
                    'net_payout_minor' => (int) $instruction->net_payout_minor,
                    'provider_payout_reference' => $instruction->provider_payout_reference,
                    'status' => $instruction->status,
                    'ledger_journal_id' => $instruction->ledgerJournal?->journal_id,
                    'executed_at' => $instruction->executed_at?->toIso8601String(),
                ],
            ],
            nextStep: 'completed',
            status: $result['idempotent_replay'] ? 200 : 201
        );
    }

    /**
     * GET /api/v1/payouts
     */
    public function indexPayouts(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $payouts = PayoutInstruction::where('merchant_id', $user->id)
            ->with(['protectedPayment', 'payoutAccount', 'settlementBatch'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (PayoutInstruction $i) => [
                'payout_instruction_id' => $i->payout_instruction_id,
                'batch_reference' => $i->settlementBatch?->batch_reference,
                'protected_payment_id' => $i->protectedPayment?->protected_payment_id,
                'provider_code' => $i->provider_code,
                'currency' => $i->currency,
                'gross_released_minor' => (int) $i->gross_released_minor,
                'merchant_fee_minor' => (int) $i->merchant_fee_minor,
                'merchant_tax_minor' => (int) $i->merchant_tax_minor,
                'net_payout_minor' => (int) $i->net_payout_minor,
                'provider_payout_reference' => $i->provider_payout_reference,
                'status' => $i->status,
                'executed_at' => $i->executed_at?->toIso8601String(),
            ]);

        return ApiResponse::success(
            message: 'Merchant payout settlements retrieved successfully.',
            data: ['payouts' => $payouts]
        );
    }
}
