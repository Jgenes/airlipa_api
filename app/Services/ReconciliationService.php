<?php

namespace App\Services;

use App\Enums\PaymentIntentStatus;
use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use App\Models\LedgerPosting;
use App\Models\PaymentAttempt;
use App\Models\ReconciliationException;
use App\Models\ReconciliationRun;
use App\Models\ReconciliationStatementLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconciliationService
{
    public function __construct(
        private readonly LedgerService $ledgerService
    ) {}

    /**
     * Execute a 3-way reconciliation run (Provider Statement vs Orchestration vs Sub-Ledger) (M09, FR-020, FR-034).
     *
     * @param  array<int, array<string, mixed>>  $statementLines
     */
    public function executeRun(
        string $providerCode,
        string $statementDate,
        array $statementLines,
        User $actor,
        string $currency = 'TZS'
    ): ReconciliationRun {
        $this->ledgerService->ensureChartOfAccountsExists($currency);

        return DB::transaction(function () use ($providerCode, $statementDate, $statementLines, $actor, $currency) {
            $providerCode = strtoupper($providerCode);
            $currency = strtoupper($currency);

            $run = ReconciliationRun::create([
                'run_reference' => 'RECON-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6)),
                'provider_code' => $providerCode,
                'statement_date' => $statementDate,
                'currency' => $currency,
                'total_lines' => count($statementLines),
                'matched_lines' => 0,
                'exception_lines' => 0,
                'statement_total_minor' => 0,
                'ledger_clearing_balance_minor' => 0,
                'protected_obligation_balance_minor' => 0,
                'variance_minor' => 0,
                'status' => 'COMPLETED',
                'executed_by_user_id' => $actor->id,
            ]);

            $matchedCount = 0;
            $exceptionCount = 0;
            $statementTotalMinor = 0;

            foreach ($statementLines as $lineData) {
                $providerRef = (string) $lineData['provider_reference'];
                $attemptRef = isset($lineData['attempt_reference']) ? (string) $lineData['attempt_reference'] : null;
                $direction = strtoupper((string) ($lineData['direction'] ?? 'INFLOW'));
                $amountMinor = (int) $lineData['amount_minor'];
                $statementTotalMinor += ($direction === 'INFLOW' ? $amountMinor : -$amountMinor);

                $attempt = PaymentAttempt::with('paymentIntent')
                    ->where('provider_code', $providerCode)
                    ->where(function ($query) use ($providerRef, $attemptRef) {
                        $query->where('provider_reference', $providerRef);
                        if ($attemptRef) {
                            $query->orWhere('attempt_id', $attemptRef);
                        }
                    })
                    ->first();

                $journal = LedgerJournal::where('provider_reference', $providerRef)->first();

                $matchStatus = 'MATCHED';
                $exceptionCode = null;

                if (! $attempt) {
                    $matchStatus = 'ORPHAN_PROVIDER_LINE';
                } elseif ((int) $attempt->amount_minor !== $amountMinor || $attempt->normalized_status !== 'SUCCEEDED') {
                    $matchStatus = 'AMOUNT_MISMATCH';
                } elseif (! $journal) {
                    $matchStatus = 'MISSING_IN_LEDGER';
                }

                if ($matchStatus !== 'MATCHED') {
                    $exceptionCount++;
                    $exceptionCode = 'rex_' . Str::lower(Str::ulid());

                    ReconciliationException::create([
                        'exception_id' => $exceptionCode,
                        'correlation_id' => $attempt?->correlation_id ?? ('cor_' . Str::lower(Str::ulid())),
                        'protected_payment_id' => $attempt?->paymentIntent?->protected_payment_id,
                        'payment_intent_id' => $attempt?->payment_intent_id,
                        'provider_code' => $providerCode,
                        'provider_reference' => $providerRef,
                        'category' => $matchStatus,
                        'severity' => 'HIGH',
                        'status' => 'OPEN',
                        'details' => "Reconciliation run {$run->run_reference} detected {$matchStatus} for provider ref {$providerRef}.",
                        'metadata' => [
                            'expected_amount_minor' => $attempt ? (int) $attempt->amount_minor : 0,
                            'actual_amount_minor' => $amountMinor,
                            'currency' => $currency,
                            'run_reference' => $run->run_reference,
                        ],
                        'sla_due_at' => now()->addHours(24),
                    ]);
                } else {
                    $matchedCount++;
                }

                ReconciliationStatementLine::create([
                    'reconciliation_run_id' => $run->id,
                    'provider_code' => $providerCode,
                    'provider_reference' => $providerRef,
                    'attempt_reference' => $attemptRef ?? $attempt?->attempt_id,
                    'direction' => $direction,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'match_status' => $matchStatus,
                    'exception_code' => $exceptionCode,
                ]);
            }

            // Compute current sub-ledger balances
            $clearingAccount = LedgerAccount::where('account_code', LedgerService::ACC_PROVIDER_CLEARING)->first();
            $protectedAccount = LedgerAccount::where('account_code', LedgerService::ACC_PROTECTED_CUSTOMER_LIABILITY)->first();

            $clearingBalanceMinor = 0;
            if ($clearingAccount) {
                $debits = (int) LedgerPosting::where('ledger_account_id', $clearingAccount->id)->where('direction', 'DEBIT')->sum('amount_minor');
                $credits = (int) LedgerPosting::where('ledger_account_id', $clearingAccount->id)->where('direction', 'CREDIT')->sum('amount_minor');
                $clearingBalanceMinor = $debits - $credits;
            }

            $protectedBalanceMinor = 0;
            if ($protectedAccount) {
                $credits = (int) LedgerPosting::where('ledger_account_id', $protectedAccount->id)->where('direction', 'CREDIT')->sum('amount_minor');
                $debits = (int) LedgerPosting::where('ledger_account_id', $protectedAccount->id)->where('direction', 'DEBIT')->sum('amount_minor');
                $protectedBalanceMinor = $credits - $debits;
            }

            $run->update([
                'matched_lines' => $matchedCount,
                'exception_lines' => $exceptionCount,
                'statement_total_minor' => $statementTotalMinor,
                'ledger_clearing_balance_minor' => $clearingBalanceMinor,
                'protected_obligation_balance_minor' => $protectedBalanceMinor,
                'variance_minor' => $statementTotalMinor - $clearingBalanceMinor,
                'status' => $exceptionCount > 0 ? 'COMPLETED_WITH_EXCEPTIONS' : 'COMPLETED',
            ]);

            AuditLogService::log('reconciliation.run.executed', $actor, [
                'run_reference' => $run->run_reference,
                'provider_code' => $providerCode,
                'matched_lines' => $matchedCount,
                'exception_lines' => $exceptionCount,
            ]);

            return $run->load('statementLines');
        });
    }

    /**
     * Resolve an open ReconciliationException with audit trail (M09).
     */
    public function resolveException(ReconciliationException $exception, User $resolver, string $resolutionNotes): ReconciliationException
    {
        $metadata = is_array($exception->metadata) ? $exception->metadata : [];
        $metadata['resolution_notes'] = $resolutionNotes;
        $metadata['resolved_by_user_id'] = $resolver->id;

        $exception->update([
            'status' => 'RESOLVED',
            'metadata' => $metadata,
            'resolved_at' => now(),
        ]);

        AuditLogService::log('reconciliation.exception.resolved', $resolver, [
            'exception_id' => $exception->exception_id,
            'resolution_notes' => $resolutionNotes,
        ]);

        return $exception->fresh();
    }
}
