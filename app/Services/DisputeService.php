<?php

namespace App\Services;

use App\Enums\DisputeCaseType;
use App\Enums\DisputeOutcome;
use App\Enums\DisputeStatus;
use App\Enums\ProtectedPaymentStatus;
use App\Enums\SettlementStatus;
use App\Models\DisputeCase;
use App\Models\ProtectedPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DisputeService
{
    public function __construct(
        protected LedgerService $ledgerService,
        protected ProtectedPaymentService $protectedPaymentService
    ) {}

    /**
     * Open a dispute case and atomically freeze the affected protected allocation (FR-014, FR-032).
     *
     * @param array<string, mixed> $data
     */
    public function openDispute(ProtectedPayment $payment, User $actor, array $data): DisputeCase
    {
        if ($actor->id !== $payment->buyer_id && $actor->id !== $payment->merchant_id) {
            throw new AccessDeniedHttpException('Only the buyer or merchant of this transaction may open a dispute.');
        }

        $allowedStatuses = [
            ProtectedPaymentStatus::FundedProtected,
            ProtectedPaymentStatus::FulfillmentSubmitted,
            ProtectedPaymentStatus::PartiallyReleased,
            ProtectedPaymentStatus::PartiallyRefunded,
        ];

        if (!in_array($payment->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'protected_payment_id' => ["Cannot open a dispute when Protected Payment is in state '{$payment->status->value}'."],
            ]);
        }

        $eligibleMinor = $payment->eligibleForDecisionMinor();
        if ($eligibleMinor <= 0) {
            throw ValidationException::withMessages([
                'disputed_amount_minor' => ['No unrestricted protected balance remains available to freeze.'],
            ]);
        }

        $disputedAmountMinor = (int) ($data['disputed_amount_minor'] ?? $eligibleMinor);
        if ($disputedAmountMinor <= 0 || $disputedAmountMinor > $eligibleMinor) {
            throw ValidationException::withMessages([
                'disputed_amount_minor' => ["Disputed amount ({$disputedAmountMinor}) must be between 1 and {$eligibleMinor} minor units."],
            ]);
        }

        $caseType = DisputeCaseType::from(strtoupper((string) ($data['case_type'] ?? 'NON_DELIVERY')));
        $slaHours = (int) config('protected_payment.dispute_sla_hours', 72);

        return DB::transaction(function () use (
            $payment,
            $actor,
            $data,
            $disputedAmountMinor,
            $caseType,
            $slaHours
        ): DisputeCase {
            $payment = ProtectedPayment::lockForUpdate()->findOrFail($payment->id);

            $dispute = DisputeCase::create([
                'case_id' => 'dsp_' . Str::lower(Str::ulid()),
                'case_reference' => 'DSP-' . strtoupper(Str::random(10)),
                'correlation_id' => $payment->correlation_id,
                'protected_payment_id' => $payment->id,
                'opened_by_user_id' => $actor->id,
                'case_type' => $caseType,
                'currency' => $payment->currency,
                'disputed_amount_minor' => $disputedAmountMinor,
                'reason' => trim((string) $data['reason']),
                'status' => 'OPEN_FROZEN',
                'primary_provider_code' => $payment->custody_provider_code,
                'sla_deadline_at' => now()->addHours($slaHours),
            ]);

            // Atomically freeze D on the ProtectedPayment (FR-014) and block settlement
            $payment->update([
                'disputed_amount_minor' => (int) $payment->disputed_amount_minor + $disputedAmountMinor,
                'status' => ProtectedPaymentStatus::Disputed,
                'dispute_status' => DisputeStatus::OpenFrozen,
                'settlement_status' => SettlementStatus::Blocked,
                'state_version' => (int) $payment->state_version + 1,
            ]);

            // Post balanced DISPUTE_FREEZE journal in M08
            $this->ledgerService->postDisputeFreeze(
                payment: $payment,
                dispute: $dispute,
                amountMinor: $disputedAmountMinor,
                actor: $actor
            );

            if (!$payment->fresh()->verifyConservationInvariant()) {
                throw new \DomainException('Rule 36 conservation of value invariant failed after dispute freeze.');
            }

            AuditLogService::log('dispute_case_opened_and_frozen', $actor, [
                'protected_payment_id' => $payment->protected_payment_id,
                'case_id' => $dispute->case_id,
                'case_reference' => $dispute->case_reference,
                'case_type' => $caseType->value,
                'disputed_amount_minor' => $disputedAmountMinor,
                'correlation_id' => $payment->correlation_id,
            ]);

            return $dispute->load(['protectedPayment', 'openedBy']);
        });
    }

    /**
     * Resolve an open dispute case and execute the authorized release/refund outcome (P-07, FR-013, FR-014, FR-032).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function resolveDispute(
        DisputeCase $dispute,
        User $analyst,
        array $data,
        string $idempotencyKey
    ): array {
        if (!$analyst->isAdmin()) {
            throw new AccessDeniedHttpException('P-07 authority boundary: Only an authorized dispute analyst/admin may decide a dispute.');
        }

        // Conflict-of-interest check (SASDS 3.2.3 P-07, 3.6.10.D)
        $payment = $dispute->protectedPayment;
        if ($analyst->id === $payment->buyer_id || $analyst->id === $payment->merchant_id || $analyst->id === $dispute->opened_by_user_id) {
            throw new AccessDeniedHttpException('Conflict-of-interest violation: The decision authority cannot be a party to the transaction or the user who opened the dispute.');
        }

        if ($dispute->status === 'EXECUTED') {
            return [
                'idempotent_replay' => true,
                'dispute' => $dispute->load(['protectedPayment', 'openedBy', 'decidedBy']),
                'agreement' => $payment->fresh(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones']),
            ];
        }

        $outcome = DisputeOutcome::from(strtoupper((string) $data['decision_outcome']));
        $disputedAmount = (int) $dispute->disputed_amount_minor;

        [$releaseMinor, $refundMinor, $disputeStatusEnum] = match ($outcome) {
            DisputeOutcome::FullReleaseMerchant => [$disputedAmount, 0, DisputeStatus::ResolvedMerchant],
            DisputeOutcome::FullRefundBuyer => [0, $disputedAmount, DisputeStatus::ResolvedBuyer],
            DisputeOutcome::PartialSplit => [
                (int) ($data['release_to_merchant_minor'] ?? 0),
                (int) ($data['refund_to_buyer_minor'] ?? 0),
                DisputeStatus::ResolvedSplit,
            ],
        };

        if (($releaseMinor + $refundMinor) !== $disputedAmount) {
            throw ValidationException::withMessages([
                'decision_outcome' => ["Sum of release_to_merchant_minor ({$releaseMinor}) and refund_to_buyer_minor ({$refundMinor}) must equal disputed_amount_minor ({$disputedAmount})."],
            ]);
        }

        if ($outcome === DisputeOutcome::PartialSplit && ($releaseMinor <= 0 || $refundMinor <= 0)) {
            throw ValidationException::withMessages([
                'decision_outcome' => ['For PARTIAL_SPLIT, both release_to_merchant_minor and refund_to_buyer_minor must be greater than zero.'],
            ]);
        }

        return DB::transaction(function () use (
            $dispute,
            $payment,
            $analyst,
            $data,
            $outcome,
            $releaseMinor,
            $refundMinor,
            $disputeStatusEnum,
            $idempotencyKey
        ): array {
            $journals = [];

            if ($releaseMinor > 0) {
                $relResult = $this->protectedPaymentService->executeAuthorizedReleaseInternal(
                    payment: $payment,
                    releasePrincipalMinor: $releaseMinor,
                    idempotencyKey: "dsp_rel_{$dispute->case_id}_{$idempotencyKey}",
                    actor: $analyst,
                    milestone: null,
                    fromDisputeFrozen: true
                );
                $journals[] = $relResult['journal'];
            }

            if ($refundMinor > 0) {
                $refResult = $this->protectedPaymentService->executeAuthorizedRefundInternal(
                    payment: $payment->fresh(),
                    refundPrincipalMinor: $refundMinor,
                    idempotencyKey: "dsp_ref_{$dispute->case_id}_{$idempotencyKey}",
                    actor: $analyst,
                    reason: "Dispute {$dispute->case_reference} resolution ({$outcome->value})",
                    fromDisputeFrozen: true
                );
                $journals[] = $refResult['journal'];
            }

            $appealHours = (int) config('protected_payment.dispute_appeal_hours', 168);

            $dispute->update([
                'status' => 'EXECUTED',
                'decision_outcome' => $outcome,
                'release_to_merchant_minor' => $releaseMinor,
                'refund_to_buyer_minor' => $refundMinor,
                'decision_reason_code' => trim((string) $data['decision_reason_code']),
                'decision_notes' => trim((string) $data['decision_notes']),
                'decided_by_user_id' => $analyst->id,
                'decided_at' => now(),
                'appeal_deadline_at' => now()->addHours($appealHours),
            ]);

            $freshPayment = $payment->fresh();
            $freshPayment->update([
                'dispute_status' => $disputeStatusEnum,
                'settlement_status' => (int) $freshPayment->released_amount_minor > 0 && (int) $freshPayment->disputed_amount_minor === 0
                    ? SettlementStatus::Eligible
                    : SettlementStatus::NotEligible,
            ]);

            if (!$freshPayment->fresh()->verifyConservationInvariant()) {
                throw new \DomainException('Rule 36 conservation of value invariant failed after dispute resolution.');
            }

            AuditLogService::log('dispute_case_resolved_and_executed', $analyst, [
                'case_id' => $dispute->case_id,
                'case_reference' => $dispute->case_reference,
                'protected_payment_id' => $freshPayment->protected_payment_id,
                'decision_outcome' => $outcome->value,
                'release_to_merchant_minor' => $releaseMinor,
                'refund_to_buyer_minor' => $refundMinor,
                'decision_reason_code' => $dispute->decision_reason_code,
                'correlation_id' => $freshPayment->correlation_id,
            ]);

            return [
                'idempotent_replay' => false,
                'dispute' => $dispute->fresh(['protectedPayment', 'openedBy', 'decidedBy']),
                'agreement' => $freshPayment->fresh(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones']),
                'journals' => $journals,
            ];
        });
    }
}
