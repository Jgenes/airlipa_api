<?php

namespace App\Services;

use App\Enums\RefundRequestStatus;
use App\Enums\RefundScope;
use App\Models\EscrowContract;
use App\Models\ProtectedPayment;
use App\Models\ProtectedPaymentMilestone;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Refund orchestration (Module 6).
 *
 * Every refund is recorded as a RefundRequest before money moves, defaulting to
 * the ORIGINAL method (the buyer's original Selcom rail), and scoped either to
 * the whole agreement, a partial amount, or a single milestone. The actual
 * provider refund + GL journal stay in ProtectedPaymentService so the Rule 36
 * conservation invariant is enforced in one place.
 */
class RefundService
{
    public function __construct(
        protected ProtectedPaymentService $protectedPaymentService,
        protected SubledgerService $subledgerService
    ) {}

    /**
     * Request and execute a refund (full, partial or milestone-scoped).
     *
     * @param array<string, mixed> $data
     * @return array{refund_request: RefundRequest, result: array<string, mixed>, idempotent_replay: bool}
     */
    public function requestRefund(ProtectedPayment $payment, User $actor, array $data, string $idempotencyKey): array
    {
        $existing = RefundRequest::where('protected_payment_id', $payment->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return [
                'refund_request' => $existing,
                'result' => [
                    'idempotent_replay' => true,
                    'agreement' => $payment->fresh(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones']),
                ],
                'idempotent_replay' => true,
            ];
        }

        $milestone = null;
        $scope = RefundScope::Partial;

        if (!empty($data['milestone_id'])) {
            $milestone = ProtectedPaymentMilestone::where('protected_payment_id', $payment->id)
                ->where('milestone_id', $data['milestone_id'])
                ->first();

            if (!$milestone) {
                throw ValidationException::withMessages([
                    'milestone_id' => ['The selected milestone does not belong to this agreement.'],
                ]);
            }

            if ($milestone->remainingAmountMinor() <= 0) {
                throw ValidationException::withMessages([
                    'milestone_id' => ['This milestone has nothing left to refund.'],
                ]);
            }

            $scope = RefundScope::Milestone;
        }

        $eligibleMinor = $payment->eligibleForDecisionMinor();
        $amountMinor = (int) ($data['amount_minor'] ?? ($milestone ? $milestone->remainingAmountMinor() : $eligibleMinor));

        if ($scope !== RefundScope::Milestone && $amountMinor >= $eligibleMinor) {
            $scope = RefundScope::Full;
        }

        return DB::transaction(function () use ($payment, $actor, $data, $idempotencyKey, $scope, $milestone, $amountMinor): array {
            $refund = RefundRequest::create([
                'refund_id' => 'rfd_' . Str::lower(Str::ulid()),
                'refund_reference' => 'REF-' . strtoupper(Str::random(10)),
                'protected_payment_id' => $payment->id,
                'escrow_contract_id' => $payment->escrow_contract_id,
                'milestone_id' => $milestone?->milestone_id,
                'requested_by_user_id' => $actor->id,
                'currency' => $payment->currency,
                'scope' => $scope,
                'principal_minor' => $amountMinor,
                'total_refund_minor' => $amountMinor,
                'destination_method' => strtoupper((string) ($data['destination_method'] ?? 'ORIGINAL_METHOD')),
                'status' => RefundRequestStatus::Requested,
                'provider_code' => $payment->custody_provider_code,
                'idempotency_key' => $idempotencyKey,
                'reason' => trim((string) ($data['reason'] ?? 'Customer refund')),
            ]);

            try {
                $result = $this->protectedPaymentService->refundProtectedPayment(
                    payment: $payment,
                    actor: $actor,
                    data: array_merge($data, ['milestone_id' => $milestone?->milestone_id]),
                    idempotencyKey: $idempotencyKey
                );
            } catch (\Throwable $exception) {
                $refund->update([
                    'status' => RefundRequestStatus::Failed,
                    'failure_reason' => substr($exception->getMessage(), 0, 490),
                ]);

                throw $exception;
            }

            $refund->update([
                'status' => RefundRequestStatus::Succeeded,
                'provider_refund_reference' => $result['journal']->provider_reference ?? null,
                'ledger_journal_id' => $result['journal']->id,
                'fee_refund_minor' => (int) ($result['refund_breakdown']['refund_buyer_fee_minor'] ?? 0),
                'tax_refund_minor' => (int) ($result['refund_breakdown']['refund_buyer_tax_minor'] ?? 0),
                'total_refund_minor' => (int) ($result['refund_breakdown']['total_buyer_refund_minor'] ?? $amountMinor),
                'refunded_at' => now(),
            ]);

            AuditLogService::log('refund.request_succeeded', $actor, [
                'refund_id' => $refund->refund_id,
                'refund_reference' => $refund->refund_reference,
                'protected_payment_id' => $payment->protected_payment_id,
                'scope' => $scope->value,
                'principal_minor' => $amountMinor,
                'milestone_id' => $milestone?->milestone_id,
            ]);

            return [
                'refund_request' => $refund->fresh(['protectedPayment', 'requestedBy']),
                'result' => $result,
                'idempotent_replay' => (bool) ($result['idempotent_replay'] ?? false),
            ];
        });
    }

    /**
     * Refund ledger history for an agreement.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, RefundRequest>
     */
    public function historyFor(ProtectedPayment $payment)
    {
        return RefundRequest::where('protected_payment_id', $payment->id)
            ->with('requestedBy')
            ->latest()
            ->get();
    }
}
