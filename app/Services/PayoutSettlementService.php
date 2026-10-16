<?php

namespace App\Services;

use App\Enums\DisputeStatus;
use App\Enums\SettlementStatus;
use App\Models\MerchantPayoutAccount;
use App\Models\PayoutInstruction;
use App\Models\ProtectedPayment;
use App\Models\SettlementBatch;
use App\Models\User;
use App\Services\PaymentProviders\PaymentProviderAdapterInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PayoutSettlementService
{
    public function __construct(
        private readonly ProviderRegistryService $providerRegistry,
        private readonly PaymentProviderAdapterInterface $providerAdapter,
        private readonly LedgerService $ledgerService,
        private readonly NotificationOutboxService $outboxService
    ) {}

    /**
     * Register a verified merchant payout account with step-up password authentication (M14, BPA-DATA-03).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function registerPayoutAccount(User $merchant, array $attributes): MerchantPayoutAccount
    {
        if (! $merchant->isVerifiedForTrading()) {
            throw new AccessDeniedHttpException('Only verified merchants (KYC/KYB complete) can register payout accounts.');
        }

        if (! Hash::check((string) ($attributes['step_up_password'] ?? ''), $merchant->password)) {
            throw new AccessDeniedHttpException('Step-up authentication failed: invalid account password.');
        }

        $providerCode = strtoupper((string) $attributes['provider_code']);
        $this->providerRegistry->assertCanPayout($providerCode);

        $accountNumber = preg_replace('/\s+/', '', (string) $attributes['account_number']);
        $accountNumberHash = hash('sha256', $providerCode . ':' . $accountNumber);

        return DB::transaction(function () use ($merchant, $attributes, $providerCode, $accountNumber, $accountNumberHash) {
            $hasExistingDefault = MerchantPayoutAccount::where('merchant_id', $merchant->id)
                ->where('is_default', true)
                ->exists();

            $isDefault = (bool) ($attributes['is_default'] ?? (! $hasExistingDefault));

            if ($isDefault) {
                MerchantPayoutAccount::where('merchant_id', $merchant->id)
                    ->update(['is_default' => false]);
            }

            $account = MerchantPayoutAccount::create([
                'payout_account_id' => (string) Str::uuid(),
                'merchant_id' => $merchant->id,
                'account_type' => strtoupper((string) ($attributes['account_type'] ?? 'MNO_WALLET')),
                'provider_code' => $providerCode,
                'account_name' => $attributes['account_name'],
                'account_number' => $accountNumber,
                'account_number_hash' => $accountNumberHash,
                'currency' => strtoupper((string) ($attributes['currency'] ?? 'TZS')),
                'is_default' => $isDefault,
                'verification_status' => 'VERIFIED',
                'verified_at' => now(),
                'cooling_off_until' => null,
            ]);

            AuditLogService::log('payout_account.registered', $merchant, [
                'payout_account_id' => $account->payout_account_id,
                'provider_code' => $providerCode,
                'account_type' => $account->account_type,
                'is_default' => $isDefault,
            ]);

            return $account;
        });
    }

    /**
     * Execute merchant payout settlement for a released ProtectedPayment (M14, FR-016, FR-017, FR-018).
     * Strictly enforces that disputed or unreleased funds can never be settled.
     *
     * @return array{idempotent_replay: bool, instruction: PayoutInstruction, batch: SettlementBatch}
     */
    public function settleMerchantPayout(
        ProtectedPayment $payment,
        User $actor,
        string $idempotencyKey,
        ?string $payoutAccountId = null
    ): array {
        if ($actor->id !== $payment->merchant_id && ! $actor->isAdmin()) {
            throw new AccessDeniedHttpException('Only the merchant or an authorized administrator can trigger payout settlement.');
        }

        // Check idempotency first
        $existing = PayoutInstruction::where('merchant_id', $payment->merchant_id)
            ->where('idempotency_key', $idempotencyKey)
            ->with(['settlementBatch', 'payoutAccount', 'ledgerJournal'])
            ->first();

        if ($existing) {
            return [
                'idempotent_replay' => true,
                'instruction' => $existing,
                'batch' => $existing->settlementBatch,
            ];
        }

        return DB::transaction(function () use ($payment, $actor, $idempotencyKey, $payoutAccountId) {
            $lockedPayment = ProtectedPayment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->dispute_status === DisputeStatus::Open) {
                throw new ConflictHttpException('Payout settlement is blocked while a dispute is open on this Protected Payment (FR-014).');
            }

            if ((int) $lockedPayment->released_amount_minor <= 0) {
                throw new ConflictHttpException('No released principal is eligible for merchant payout settlement yet.');
            }

            // Determine how much has already been settled via previous PayoutInstructions
            $alreadySettledGross = (int) PayoutInstruction::where('protected_payment_id', $lockedPayment->id)
                ->where('status', 'SETTLED')
                ->sum('gross_released_minor');

            $unsettledReleasedMinor = (int) $lockedPayment->released_amount_minor - $alreadySettledGross;

            if ($unsettledReleasedMinor <= 0) {
                throw new ConflictHttpException('All released funds for this Protected Payment have already been settled to the merchant.');
            }

            // Resolve merchant payout account
            $payoutAccountQuery = MerchantPayoutAccount::where('merchant_id', $lockedPayment->merchant_id)
                ->where('verification_status', 'VERIFIED');

            if ($payoutAccountId !== null) {
                $payoutAccount = (clone $payoutAccountQuery)->where('payout_account_id', $payoutAccountId)->first();
            } else {
                $payoutAccount = (clone $payoutAccountQuery)->where('is_default', true)->first()
                    ?? $payoutAccountQuery->first();
            }

            if (! $payoutAccount) {
                throw new UnprocessableEntityHttpException('Merchant does not have a verified payout account registered. Please register a payout account first.');
            }

            $capability = $this->providerRegistry->assertCanPayout($payoutAccount->provider_code);

            // Compute proportional merchant fee & VAT for the released slice
            $principalTotal = max(1, (int) $lockedPayment->principal_amount_minor);
            $merchantFeeSlice = (int) intdiv((int) $lockedPayment->merchant_fee_minor * $unsettledReleasedMinor, $principalTotal);
            $merchantTaxSlice = (int) intdiv((int) $lockedPayment->merchant_tax_minor * $unsettledReleasedMinor, $principalTotal);
            $netPayoutMinor = max(0, $unsettledReleasedMinor - $merchantFeeSlice - $merchantTaxSlice);

            $providerResult = $this->providerAdapter->executeRelease(
                payment: $lockedPayment,
                capability: $capability,
                amountMinor: $netPayoutMinor,
                idempotencyKey: "settle_{$lockedPayment->protected_payment_id}_{$idempotencyKey}"
            );
            $providerReference = $providerResult['provider_release_reference'];

            $batch = SettlementBatch::create([
                'batch_reference' => 'BATCH-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6)),
                'provider_code' => $payoutAccount->provider_code,
                'currency' => $lockedPayment->currency,
                'instructions_count' => 1,
                'total_amount_minor' => $netPayoutMinor,
                'status' => 'COMPLETED',
                'triggered_by_user_id' => $actor->id,
                'settled_at' => now(),
            ]);

            $journal = $this->ledgerService->postMerchantPayoutSettlement(
                payment: $lockedPayment,
                netPayoutMinor: $netPayoutMinor,
                providerReference: $providerReference,
                idempotencyKey: $idempotencyKey,
                actor: $actor
            );

            $instruction = PayoutInstruction::create([
                'payout_instruction_id' => 'po_' . Str::lower(Str::random(20)),
                'settlement_batch_id' => $batch->id,
                'protected_payment_id' => $lockedPayment->id,
                'merchant_id' => $lockedPayment->merchant_id,
                'payout_account_id' => $payoutAccount->id,
                'provider_code' => $payoutAccount->provider_code,
                'currency' => $lockedPayment->currency,
                'gross_released_minor' => $unsettledReleasedMinor,
                'merchant_fee_minor' => $merchantFeeSlice,
                'merchant_tax_minor' => $merchantTaxSlice,
                'net_payout_minor' => $netPayoutMinor,
                'idempotency_key' => $idempotencyKey,
                'provider_payout_reference' => $providerReference,
                'status' => 'SETTLED',
                'ledger_journal_id' => $journal->id,
                'executed_at' => now(),
            ]);

            $lockedPayment->update([
                'settlement_status' => SettlementStatus::Settled,
            ]);

            $this->outboxService->publishEvent(
                aggregateType: 'PAYOUT_INSTRUCTION',
                aggregateId: $instruction->payout_instruction_id,
                eventType: 'PAYOUT_SETTLED',
                payload: [
                    'payout_instruction_id' => $instruction->payout_instruction_id,
                    'protected_payment_id' => $lockedPayment->protected_payment_id,
                    'net_payout_minor' => $netPayoutMinor,
                    'currency' => $lockedPayment->currency,
                    'provider_code' => $payoutAccount->provider_code,
                    'provider_payout_reference' => $providerReference,
                ],
                recipientUser: $lockedPayment->merchant,
                titleEn: 'Merchant Settlement Payout Completed',
                messageEn: "Your settlement payout of {$netPayoutMinor} minor units ({$lockedPayment->currency}) for {$lockedPayment->reference} has been disbursed via {$payoutAccount->provider_code}.",
                titleSw: 'Malipo ya Muuzaji Yamekamilika',
                messageSw: "Malipo yako ya {$netPayoutMinor} ({$lockedPayment->currency}) kwa ajili ya {$lockedPayment->reference} yametumwa kupitia {$payoutAccount->provider_code}."
            );

            AuditLogService::log('payout.settled', $actor, [
                'payout_instruction_id' => $instruction->payout_instruction_id,
                'protected_payment_id' => $lockedPayment->protected_payment_id,
                'net_payout_minor' => $netPayoutMinor,
                'provider_code' => $payoutAccount->provider_code,
            ]);

            return [
                'idempotent_replay' => false,
                'instruction' => $instruction->load(['settlementBatch', 'payoutAccount', 'ledgerJournal']),
                'batch' => $batch,
            ];
        });
    }
}
