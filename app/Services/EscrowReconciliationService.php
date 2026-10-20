<?php

namespace App\Services;

use App\Models\ContractSubledgerAccount;
use App\Models\DisputeHold;
use App\Models\FeeRecord;
use App\Models\LedgerAccount;
use App\Models\LedgerPosting;
use App\Models\PayoutInstruction;
use App\Models\ReconciliationException;
use App\Models\ReconciliationRun;
use App\Models\RefundRequest;
use App\Models\SuspenseItem;
use App\Models\User;

/**
 * Escrow reconciliation dashboard + three-way invariant (Module 11).
 *
 * Reconciles, in integer minor units:
 *   (1) subledger reality  - contract buckets + pending payouts + pending refunds + fees owed
 *   (2) GL escrow control  - 2100 + 2300 + 2500 + 2600 + 1300 liability balances
 *   (3) partner statement  - the pooled Selcom account as the provider reports it
 *
 * Any breach above the configured tolerance freezes payouts immediately and
 * raises a CRITICAL reconciliation exception; releasing the freeze is a
 * deliberate, audited admin action.
 */
class EscrowReconciliationService
{
    public function __construct(
        protected LedgerService $ledgerService,
        protected SubledgerService $subledgerService,
        protected PayoutFreezeService $freezeService
    ) {}

    /**
     * The invariant computation itself (no side effects).
     *
     * @return array<string, mixed>
     */
    public function threeWayInvariant(string $currency = 'TZS'): array
    {
        $currency = strtoupper($currency);

        $contracts = ContractSubledgerAccount::where('currency', $currency)->get();

        // Leg 1 - subledger reality: money owed to counterparties per contract.
        $subledgerHeld = (int) $contracts->sum(fn (ContractSubledgerAccount $a): int =>
            (int) $a->available_minor + (int) $a->held_milestones_minor + (int) $a->frozen_minor
            + (int) $a->pending_release_minor + (int) $a->pending_refund_minor);

        // Leg 2 - GL: protected funds + dispute-frozen funds must equal the
        // subledger held total, because a dispute only reclassifies between them.
        $glProtected = $this->netCredit($currency, [
            LedgerService::ACC_PROTECTED_CUSTOMER_LIABILITY,
            LedgerService::ACC_DISPUTE_FROZEN_LIABILITY,
        ]);

        // Leg 3 - cash: the clearing account carries every movement of the
        // pooled Selcom account, so it must equal the provider statement.
        $glClearingNetDebit = -$this->netCredit($currency, [LedgerService::ACC_PROVIDER_CLEARING]);

        $glCustomerAndOwnFunds = $this->netCredit($currency, [
            LedgerService::ACC_PROTECTED_CUSTOMER_LIABILITY,
            LedgerService::ACC_MERCHANT_PAYABLE_AVAILABLE,
            LedgerService::ACC_DEFERRED_PLATFORM_FEE,
            LedgerService::ACC_TAX_PAYABLE,
            LedgerService::ACC_REFUND_PAYABLE,
            LedgerService::ACC_DISPUTE_FROZEN_LIABILITY,
            LedgerService::ACC_SUSPENSE_UNRECONCILED,
            LedgerService::ACC_EARNED_PLATFORM_REVENUE,
        ]);

        $merchantPayable = $this->netCredit($currency, [LedgerService::ACC_MERCHANT_PAYABLE_AVAILABLE]);
        $feesOwed = $this->netCredit($currency, [LedgerService::ACC_DEFERRED_PLATFORM_FEE]);
        $taxOwed = $this->netCredit($currency, [LedgerService::ACC_TAX_PAYABLE]);
        $suspense = $this->netCredit($currency, [LedgerService::ACC_SUSPENSE_UNRECONCILED]);

        $pendingPayouts = (int) PayoutInstruction::where('currency', $currency)
            ->whereIn('status', ['PENDING', 'BLOCKED'])
            ->sum('net_payout_minor');

        $pendingRefunds = (int) RefundRequest::where('currency', $currency)
            ->where('status', 'REQUESTED')
            ->sum('total_refund_minor');

        $statement = \App\Models\PartnerSettlementStatement::where('provider_code', 'SELCOM_TZ')
            ->where('currency', $currency)
            ->latest('statement_date')
            ->first();

        $providerBalance = $statement ? (int) $statement->closing_balance_minor : null;

        $subledgerVsGl = $subledgerHeld - $glProtected;
        $glVsProvider = $providerBalance === null ? null : $glClearingNetDebit - $providerBalance;
        $glInternal = $glClearingNetDebit - $glCustomerAndOwnFunds;

        $tolerance = (int) config('protected_payment.reconciliation_tolerance_minor', 0);

        return [
            'currency' => $currency,
            'generated_at' => now()->toIso8601String(),
            'subledger' => [
                'held_minor' => $subledgerHeld,
                'released_to_date_minor' => (int) $contracts->sum('released_minor'),
                'refunded_to_date_minor' => (int) $contracts->sum('refunded_minor'),
                'pending_payouts_minor' => $pendingPayouts,
                'pending_refunds_minor' => $pendingRefunds,
                'contracts_count' => $contracts->count(),
            ],
            'gl' => [
                'protected_plus_dispute_minor' => $glProtected,
                'merchant_payable_minor' => $merchantPayable,
                'platform_fees_owed_minor' => $feesOwed,
                'tax_owed_minor' => $taxOwed,
                'suspense_minor' => $suspense,
                'clearing_net_debit_minor' => $glClearingNetDebit,
                'customer_and_own_funds_minor' => $glCustomerAndOwnFunds,
            ],
            'provider' => [
                'provider_code' => 'SELCOM_TZ',
                'statement_date' => $statement?->statement_date?->toDateString(),
                'closing_balance_minor' => $providerBalance,
            ],
            'variance' => [
                'subledger_vs_gl_minor' => $subledgerVsGl,
                'gl_vs_provider_minor' => $glVsProvider,
                'gl_internal_minor' => $glInternal,
                'tolerance_minor' => $tolerance,
            ],
            'healthy' => abs($subledgerVsGl) <= $tolerance
                && abs($glInternal) <= $tolerance
                && ($glVsProvider === null || abs($glVsProvider) <= $tolerance),
        ];
    }

    /**
     * Run the invariant check and freeze payouts on breach.
     *
     * @return array<string, mixed>
     */
    public function runInvariantCheck(User $actor): array
    {
        $result = $this->threeWayInvariant();
        $portfolio = $this->subledgerService->scanPortfolioInvariant();

        $violations = [];

        if (!$result['healthy']) {
            $violations[] = 'Three-way escrow invariant breached: subledger vs GL variance '
                . $result['variance']['subledger_vs_gl_minor'] . ' minor units.';
        }

        if ($portfolio['violations'] !== []) {
            $violations[] = count($portfolio['violations']) . ' contract subledger(s) violate money conservation.';
        }

        if ($violations === []) {
            return [
                'healthy' => true,
                'freeze_active' => $this->freezeService->isFrozen(),
                'invariant' => $result,
                'portfolio' => $portfolio,
            ];
        }

        $exception = ReconciliationException::create([
            'exception_id' => 'rex_' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ulid()),
            'correlation_id' => 'corr_recon_' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)),
            'provider_code' => 'SELCOM_TZ',
            'category' => 'ESCROW_INVARIANT_BREACH',
            'severity' => 'CRITICAL',
            'status' => 'OPEN',
            'details' => implode(' ', $violations),
            'metadata' => [
                'invariant' => $result,
                'portfolio_violations' => $portfolio['violations'],
            ],
            'sla_due_at' => now()->addHours(4),
        ]);

        $freeze = $this->freezeService->activate($actor, $violations[0], [
            'exception_id' => $exception->exception_id,
            'variance' => $result['variance'],
        ]);

        return [
            'healthy' => false,
            'freeze_active' => (bool) $freeze->is_active,
            'exception' => [
                'exception_id' => $exception->exception_id,
                'category' => $exception->category,
                'severity' => $exception->severity,
            ],
            'invariant' => $result,
            'portfolio' => $portfolio,
            'violations' => $violations,
        ];
    }

    /**
     * Full dashboard payload for the admin console.
     *
     * @return array<string, mixed>
     */
    public function dashboard(string $currency = 'TZS'): array
    {
        $invariant = $this->threeWayInvariant($currency);
        $portfolio = $this->subledgerService->scanPortfolioInvariant();
        $trialBalance = $this->ledgerService->getTrialBalance($currency);

        return [
            'invariant' => $invariant,
            'portfolio' => $portfolio,
            'payout_freeze' => [
                'is_active' => $this->freezeService->isFrozen(),
                'state' => $this->freezeService->state()->only([
                    'scope', 'is_active', 'reason', 'activated_at', 'released_at',
                ]),
            ],
            'exceptions' => [
                'open_count' => ReconciliationException::where('status', 'OPEN')->count(),
                'critical_open_count' => ReconciliationException::where('status', 'OPEN')->where('severity', 'CRITICAL')->count(),
                'open_by_category' => ReconciliationException::where('status', 'OPEN')
                    ->selectRaw('category, count(*) as total')
                    ->groupBy('category')
                    ->pluck('total', 'category')
                    ->all(),
                'latest' => ReconciliationException::where('status', 'OPEN')
                    ->latest()
                    ->limit(10)
                    ->get(['exception_id', 'category', 'severity', 'details', 'sla_due_at'])
                    ->toArray(),
            ],
            'suspense' => [
                'unmatched_items' => SuspenseItem::where('status', 'UNMATCHED')->count(),
                'unmatched_total_minor' => (int) SuspenseItem::where('status', 'UNMATCHED')->sum('amount_minor'),
            ],
            'reconciliation_runs' => [
                'total' => ReconciliationRun::where('currency', strtoupper($currency))->count(),
                'completed_with_exceptions' => ReconciliationRun::where('currency', strtoupper($currency))
                    ->where('exception_lines', '>', 0)->count(),
                'latest' => ReconciliationRun::where('currency', strtoupper($currency))
                    ->latest()->limit(5)
                    ->get(['run_reference', 'provider_code', 'statement_date', 'matched_lines', 'exception_lines', 'variance_minor', 'status'])
                    ->toArray(),
            ],
            'disputes' => [
                'active_holds' => DisputeHold::where('status', 'ACTIVE')->count(),
                'frozen_total_minor' => (int) DisputeHold::where('status', 'ACTIVE')->sum('amount_minor'),
            ],
            'fees' => [
                'deferred_records' => FeeRecord::where('status', 'DEFERRED')->count(),
                'deferred_total_minor' => (int) FeeRecord::where('status', 'DEFERRED')->sum('platform_fee_minor'),
                'earned_total_minor' => (int) FeeRecord::where('status', 'EARNED')->sum('platform_fee_minor'),
            ],
            'trial_balance_balanced' => (int) ($trialBalance['totals']['total_debits_minor'] ?? 0)
                === (int) ($trialBalance['totals']['total_credits_minor'] ?? 0),
            'trial_balance' => $trialBalance,
        ];
    }

    /**
     * @param array<int, string> $accountCodes
     */
    protected function netCredit(string $currency, array $accountCodes): int
    {
        $accounts = LedgerAccount::whereIn('account_code', $accountCodes)->where('currency', $currency)->get();

        return (int) $accounts->sum(function (LedgerAccount $account): int {
            $debits = (int) LedgerPosting::where('ledger_account_id', $account->id)->where('direction', 'DEBIT')->sum('amount_minor');
            $credits = (int) LedgerPosting::where('ledger_account_id', $account->id)->where('direction', 'CREDIT')->sum('amount_minor');

            return $credits - $debits;
        });
    }
}
