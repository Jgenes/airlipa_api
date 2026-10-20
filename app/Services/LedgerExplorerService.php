<?php

namespace App\Services;

use App\Models\ContractSubledgerAccount;
use App\Models\EscrowContract;
use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use App\Models\LedgerPosting;
use App\Models\ProtectedPayment;
use App\Models\SubledgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * Admin ledger explorer (Module 10).
 *
 * Drill-down path: control account -> journals -> postings -> the business
 * object behind the posting (contract / protected payment), plus the matching
 * subledger movement so an auditor can walk from a trial-balance figure down to
 * a single contract bucket in a few clicks.
 */
class LedgerExplorerService
{
    /**
     * All accounts with live balances, ordered by code.
     *
     * @return array<int, array<string, mixed>>
     */
    public function accounts(string $currency = 'TZS'): array
    {
        $currency = strtoupper($currency);

        return LedgerAccount::where('currency', $currency)
            ->orderBy('account_code')
            ->get()
            ->map(function (LedgerAccount $account): array {
                $debits = (int) LedgerPosting::where('ledger_account_id', $account->id)->where('direction', 'DEBIT')->sum('amount_minor');
                $credits = (int) LedgerPosting::where('ledger_account_id', $account->id)->where('direction', 'CREDIT')->sum('amount_minor');

                return [
                    'account_code' => $account->account_code,
                    'account_name' => $account->account_name,
                    'account_class' => $account->account_class,
                    'normal_side' => $account->normal_side,
                    'total_debits_minor' => $debits,
                    'total_credits_minor' => $credits,
                    'net_balance_minor' => $account->normal_side === 'DEBIT' ? $debits - $credits : $credits - $debits,
                    'postings_count' => LedgerPosting::where('ledger_account_id', $account->id)->count(),
                ];
            })
            ->all();
    }

    /**
     * Drill into one account: journals touching it, newest first.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function accountDetail(string $accountCode, array $filters = []): array
    {
        $account = LedgerAccount::where('account_code', $accountCode)->firstOrFail();

        $query = LedgerPosting::where('ledger_account_id', $account->id)
            ->with(['journal'])
            ->orderByDesc('id');

        if (!empty($filters['from'])) {
            $query->where('posted_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->where('posted_at', '<=', $filters['to']);
        }

        $limit = min(200, max(1, (int) ($filters['limit'] ?? 50)));
        $postings = $query->limit($limit)->get();

        return [
            'account' => [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'account_class' => $account->account_class,
                'normal_side' => $account->normal_side,
                'description' => $account->description,
            ],
            'postings' => $postings->map(fn (LedgerPosting $posting): array => [
                'posting_id' => $posting->posting_id,
                'journal_id' => $posting->journal?->journal_id,
                'event_type' => $posting->journal?->event_type,
                'direction' => $posting->direction,
                'amount_minor' => (int) $posting->amount_minor,
                'narration' => $posting->narration,
                'party_user_id' => $posting->party_user_id,
                'posted_at' => $posting->posted_at?->toIso8601String(),
                'protected_payment_id' => $posting->journal?->protected_payment_id,
            ])->all(),
            'returned' => $postings->count(),
        ];
    }

    /**
     * Journal detail with every line and the linked business objects.
     *
     * @return array<string, mixed>
     */
    public function journalDetail(string $journalId): array
    {
        $journal = LedgerJournal::with(['postings.account'])
            ->where('journal_id', $journalId)
            ->orWhere('id', is_numeric($journalId) ? (int) $journalId : 0)
            ->firstOrFail();

        $contract = null;

        if ($journal->protected_payment_id) {
            $payment = ProtectedPayment::with('escrowContract')->find($journal->protected_payment_id);

            if ($payment?->escrowContract) {
                $contract = $this->contractSnapshot($payment->escrowContract);
            }
        }

        return [
            'journal_id' => $journal->journal_id,
            'event_type' => $journal->event_type,
            'posting_rule_code' => $journal->posting_rule_code,
            'idempotency_key' => $journal->idempotency_key,
            'currency' => $journal->currency,
            'total_debit_minor' => (int) $journal->total_debit_minor,
            'total_credit_minor' => (int) $journal->total_credit_minor,
            'balanced' => (int) $journal->total_debit_minor === (int) $journal->total_credit_minor,
            'provider_reference' => $journal->provider_reference,
            'description' => $journal->description,
            'posted_at' => $journal->posted_at?->toIso8601String(),
            'reversal_of_journal_id' => $journal->reversal_of_journal_id,
            'lines' => $journal->postings->map(fn (LedgerPosting $posting): array => [
                'account_code' => $posting->account?->account_code,
                'account_name' => $posting->account?->account_name,
                'direction' => $posting->direction,
                'amount_minor' => (int) $posting->amount_minor,
                'party_user_id' => $posting->party_user_id,
                'narration' => $posting->narration,
            ])->all(),
            'linked_contract' => $contract,
        ];
    }

    /**
     * Contract drill-down: subledger buckets, entries and journals.
     *
     * @return array<string, mixed>
     */
    public function contractDetail(EscrowContract $contract): array
    {
        $account = ContractSubledgerAccount::where('escrow_contract_id', $contract->id)->first();

        $entries = $account
            ? SubledgerEntry::where('subledger_type', 'CONTRACT')
                ->where('subledger_id', $account->id)
                ->orderByDesc('id')
                ->limit(200)
                ->get()
            : collect();

        $journals = LedgerJournal::whereHas('postings', function ($query) use ($contract): void {
            $query->whereIn('party_user_id', [$contract->initiator_id, $contract->counterparty_user_id]);
        })->orderByDesc('id')->limit(50)->get(['journal_id', 'event_type', 'total_debit_minor', 'currency', 'posted_at']);

        return [
            'contract' => $this->contractSnapshot($contract),
            'subledger' => $account ? [
                'account_id' => $account->account_id,
                'deposited_minor' => (int) $account->deposited_minor,
                'available_minor' => (int) $account->available_minor,
                'held_milestones_minor' => (int) $account->held_milestones_minor,
                'frozen_minor' => (int) $account->frozen_minor,
                'pending_release_minor' => (int) $account->pending_release_minor,
                'pending_refund_minor' => (int) $account->pending_refund_minor,
                'released_minor' => (int) $account->released_minor,
                'refunded_minor' => (int) $account->refunded_minor,
                'conservation_difference_minor' => $account->conservationDifference(),
            ] : null,
            'entries' => $entries->map(fn (SubledgerEntry $entry): array => [
                'entry_id' => $entry->entry_id,
                'bucket' => $entry->bucket,
                'direction' => $entry->direction,
                'amount_minor' => (int) $entry->amount_minor,
                'balance_after_minor' => (int) $entry->balance_after_minor,
                'reason_code' => $entry->reason_code,
                'ledger_journal_id' => $entry->ledger_journal_id,
                'idempotency_key' => $entry->idempotency_key,
                'created_at' => $entry->created_at?->toIso8601String(),
            ])->all(),
            'journals' => $journals->map(fn (LedgerJournal $journal): array => [
                'journal_id' => $journal->journal_id,
                'event_type' => $journal->event_type,
                'total_debit_minor' => (int) $journal->total_debit_minor,
                'currency' => $journal->currency,
                'posted_at' => $journal->posted_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function contractSnapshot(EscrowContract $contract): array
    {
        return [
            'contract_id' => $contract->contract_id,
            'reference' => $contract->reference,
            'status' => $contract->status?->value,
            'currency' => $contract->currency,
            'amount_minor' => (int) $contract->amount_minor,
            'initiator_id' => $contract->initiator_id,
            'counterparty_user_id' => $contract->counterparty_user_id,
            'protected_payment_id' => $contract->protectedPayment?->protected_payment_id,
        ];
    }
}
