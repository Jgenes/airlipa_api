<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\FeeRule;
use App\Models\ReconciliationException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class GovernanceService
{
    public function __construct(
        private readonly ReconciliationService $reconciliationService,
        private readonly ProviderRegistryService $providerRegistry
    ) {}

    /**
     * Submit a Maker-Checker Dual-Control Approval Request (M17, P-05, FR-036).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createApprovalRequest(User $maker, array $attributes): ApprovalRequest
    {
        if (! $maker->isAdmin()) {
            throw new AccessDeniedHttpException('Only authorized administrators can initiate a dual-control governance request.');
        }

        $approval = ApprovalRequest::create([
            'approval_code' => 'APR-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6)),
            'action_type' => strtoupper((string) $attributes['action_type']),
            'target_type' => strtoupper((string) $attributes['target_type']),
            'target_identifier' => (string) $attributes['target_identifier'],
            'proposed_payload' => (array) $attributes['proposed_payload'],
            'reason' => (string) $attributes['reason'],
            'status' => 'PENDING',
            'maker_user_id' => $maker->id,
        ]);

        AuditLogService::log('governance.approval.requested', $maker, [
            'approval_code' => $approval->approval_code,
            'action_type' => $approval->action_type,
            'target_identifier' => $approval->target_identifier,
        ]);

        return $approval;
    }

    /**
     * Approve or reject a Pending Dual-Control Request.
     * Enforces Segregation of Duties: Maker cannot be Checker (P-05).
     */
    public function decideApprovalRequest(
        ApprovalRequest $approval,
        User $checker,
        string $decision,
        string $notes
    ): ApprovalRequest {
        if (! $checker->isAdmin()) {
            throw new AccessDeniedHttpException('Only authorized administrators can decide a dual-control governance request.');
        }

        if ($approval->maker_user_id === $checker->id) {
            throw new AccessDeniedHttpException('Segregation of Duties violation (P-05): The maker who initiated an approval request cannot approve or reject their own request.');
        }

        if ($approval->status !== 'PENDING') {
            throw new ConflictHttpException("Approval request {$approval->approval_code} has already been {$approval->status}.");
        }

        return DB::transaction(function () use ($approval, $checker, $decision, $notes) {
            $normalizedDecision = strtoupper($decision) === 'APPROVE' ? 'APPROVED' : 'REJECTED';

            if ($normalizedDecision === 'APPROVED') {
                $this->executeApprovedAction($approval, $checker);
            }

            $approval->update([
                'status' => $normalizedDecision,
                'checker_user_id' => $checker->id,
                'checker_notes' => $notes,
                'decided_at' => now(),
            ]);

            AuditLogService::log('governance.approval.decided', $checker, [
                'approval_code' => $approval->approval_code,
                'status' => $normalizedDecision,
                'maker_user_id' => $approval->maker_user_id,
            ]);

            return $approval->fresh();
        });
    }

    private function executeApprovedAction(ApprovalRequest $approval, User $checker): void
    {
        $payload = $approval->proposed_payload;

        if ($approval->action_type === 'FEE_RULE_UPSERT') {
            FeeRule::updateOrCreate(
                [
                    'fee_rule_id' => $payload['fee_rule_id'] ?? ('fr_' . Str::lower(Str::random(12))),
                ],
                [
                    'pricing_version' => $payload['pricing_version'] ?? 'TZ-R2-2026-10-16',
                    'fee_code' => $payload['fee_code'] ?? 'BUYER_PROTECTION_FEE_STANDARD',
                    'fee_class' => $payload['fee_class'] ?? 'PROTECTION_SERVICE_FEE',
                    'transaction_type' => $payload['transaction_type'] ?? 'PROTECTED_PAYMENT',
                    'payer' => $payload['payer'] ?? 'BUYER',
                    'beneficiary' => $payload['beneficiary'] ?? 'AIRLIPA',
                    'calculation_method' => $payload['calculation_method'] ?? 'PERCENTAGE',
                    'flat_amount_minor' => (int) ($payload['flat_amount_minor'] ?? 0),
                    'rate_bps' => (int) ($payload['rate_bps'] ?? 150),
                    'min_fee_minor' => (int) ($payload['min_fee_minor'] ?? 50000),
                    'max_fee_minor' => isset($payload['max_fee_minor']) ? (int) $payload['max_fee_minor'] : 50000000,
                    'currency' => $payload['currency'] ?? 'TZS',
                    'tax_code' => $payload['tax_code'] ?? 'TZ_VAT_18',
                    'tax_rate_bps' => (int) ($payload['tax_rate_bps'] ?? 1800),
                    'refund_policy' => $payload['refund_policy'] ?? 'REFUNDABLE_BEFORE_FULFILLMENT',
                    'recognition_policy' => $payload['recognition_policy'] ?? 'DEFERRED_UNTIL_FINALITY',
                    'approval_reference' => $approval->approval_code,
                    'disclosure_en' => $payload['disclosure_en'] ?? 'Approved fee rule update.',
                    'disclosure_sw' => $payload['disclosure_sw'] ?? 'Kanuni ya ada iliyoidhinishwa.',
                    'effective_from' => now(),
                    'status' => 'ACTIVE',
                ]
            );
        } elseif ($approval->action_type === 'RECONCILIATION_EXCEPTION_RESOLVE') {
            $exception = ReconciliationException::where('exception_id', $approval->target_identifier)->firstOrFail();
            $this->reconciliationService->resolveException($exception, $checker, $approval->reason);
        } elseif ($approval->action_type === 'PROVIDER_STATUS_CHANGE') {
            $this->providerRegistry->updateCapabilityStatus(
                providerCode: $approval->target_identifier,
                isActive: (bool) ($payload['is_active'] ?? false),
                admin: $checker,
                reason: $approval->reason
            );
        }
    }
}
