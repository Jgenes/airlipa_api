<?php

namespace App\Services;

use App\Models\DisputeCase;
use App\Models\PayoutInstruction;
use App\Models\ProtectedPayment;
use App\Models\ReconciliationException;
use App\Models\ReconciliationRun;
use App\Models\User;

class ReportingService
{
    public function __construct(
        private readonly LedgerService $ledgerService
    ) {}

    /**
     * Generate a user/merchant statement with fee, VAT, and net obligation breakdown (M16, FR-021).
     *
     * @return array<string, mixed>
     */
    public function generateUserStatement(User $user, string $currency = 'TZS'): array
    {
        $currency = strtoupper($currency);

        $agreements = ProtectedPayment::where(function ($query) use ($user) {
            $query->where('merchant_id', $user->id)
                ->orWhere('buyer_id', $user->id);
        })
            ->where('currency', $currency)
            ->orderByDesc('id')
            ->get();

        $payouts = PayoutInstruction::where('merchant_id', $user->id)
            ->where('currency', $currency)
            ->orderByDesc('id')
            ->get();

        $lines = $agreements->map(function (ProtectedPayment $p) use ($user) {
            return [
                'protected_payment_id' => $p->protected_payment_id,
                'reference' => $p->reference,
                'role' => $p->merchant_id === $user->id ? 'MERCHANT' : 'BUYER',
                'title' => $p->title,
                'status' => $p->status->value,
                'settlement_status' => $p->settlement_status->value,
                'principal_amount_minor' => (int) $p->principal_amount_minor,
                'buyer_fee_minor' => (int) $p->buyer_fee_minor,
                'buyer_tax_minor' => (int) $p->buyer_tax_minor,
                'merchant_fee_minor' => (int) $p->merchant_fee_minor,
                'merchant_tax_minor' => (int) $p->merchant_tax_minor,
                'funded_amount_minor' => (int) $p->funded_amount_minor,
                'protected_balance_minor' => (int) $p->protected_balance_minor,
                'released_amount_minor' => (int) $p->released_amount_minor,
                'refunded_amount_minor' => (int) $p->refunded_amount_minor,
                'created_at' => $p->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'statement_id' => 'STMT-' . now()->format('YmdHis') . '-' . $user->id,
            'generated_at' => now()->toIso8601String(),
            'user_id' => $user->id,
            'currency' => $currency,
            'summary' => $this->ledgerService->getObligationsSummary($user, $currency),
            'transactions' => $lines,
            'payout_settlements' => $payouts->map(fn (PayoutInstruction $i) => [
                'payout_instruction_id' => $i->payout_instruction_id,
                'provider_code' => $i->provider_code,
                'gross_released_minor' => (int) $i->gross_released_minor,
                'merchant_fee_minor' => (int) $i->merchant_fee_minor,
                'merchant_tax_minor' => (int) $i->merchant_tax_minor,
                'net_payout_minor' => (int) $i->net_payout_minor,
                'provider_payout_reference' => $i->provider_payout_reference,
                'status' => $i->status,
                'executed_at' => $i->executed_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Generate Regulatory & Partner Safeguarding Proof Pack (M16, FR-022).
     *
     * @return array<string, mixed>
     */
    public function generateSafeguardingProofPack(string $currency = 'TZS'): array
    {
        $currency = strtoupper($currency);
        $trialBalance = $this->ledgerService->getTrialBalance($currency);
        $auditChain = AuditLogService::verifyHashChain();

        $allPayments = ProtectedPayment::where('currency', $currency)->get();
        $allConservationValid = $allPayments->every(fn (ProtectedPayment $p) => $p->verifyConservationInvariant());

        return [
            'report_type' => 'SAFEGUARDING_AND_COMPLIANCE_PROOF_PACK',
            'generated_at' => now()->toIso8601String(),
            'currency' => $currency,
            'custody_model' => 'LICENSED_PARTNER_BACKED_SAFEGUARDING',
            'conservation_invariants' => [
                'evaluated_agreements_count' => $allPayments->count(),
                'all_conservation_equations_hold' => $allConservationValid,
                'total_funded_principal_minor' => (int) $allPayments->sum('funded_amount_minor'),
                'total_protected_balance_minor' => (int) $allPayments->sum('protected_balance_minor'),
                'total_disputed_frozen_minor' => (int) $allPayments->sum('disputed_amount_minor'),
                'total_released_principal_minor' => (int) $allPayments->sum('released_amount_minor'),
                'total_refunded_principal_minor' => (int) $allPayments->sum('refunded_amount_minor'),
            ],
            'trial_balance' => $trialBalance,
            'reconciliation_health' => [
                'recent_runs_count' => ReconciliationRun::where('currency', $currency)->count(),
                'open_exceptions_count' => ReconciliationException::where('currency', $currency)->where('status', 'OPEN')->count(),
                'resolved_exceptions_count' => ReconciliationException::where('currency', $currency)->where('status', 'RESOLVED')->count(),
            ],
            'dispute_governance' => [
                'open_disputes_count' => DisputeCase::whereIn('status', ['OPEN', 'UNDER_REVIEW'])->count(),
                'resolved_disputes_count' => DisputeCase::whereNotIn('status', ['OPEN', 'UNDER_REVIEW'])->count(),
            ],
            'audit_trail_integrity' => $auditChain,
        ];
    }
}
