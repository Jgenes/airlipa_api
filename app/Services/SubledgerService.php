<?php

namespace App\Services;

use App\Enums\SubledgerType;
use App\Models\ContractSubledgerAccount;
use App\Models\EscrowContract;
use App\Models\LedgerJournal;
use App\Models\MilestoneSubledgerAccount;
use App\Models\PartySubledgerAccount;
use App\Models\ProtectedPayment;
use App\Models\SubledgerEntry;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Subledger layer sitting under the GL control accounts (M08 / SASDS 4.3.6).
 *
 * The GL answers "how much do we hold in total"; these subledgers answer
 * "whose money is it and for which contract / milestone / party". Every bucket
 * movement is append-only (subledger_entries), row-locked and idempotent so a
 * replayed webhook or job can never double-move funds.
 */
class SubledgerService
{
    /**
     * Buckets that may be moved per subledger type (whitelist against typos/abuse).
     *
     * @return array<int, string>
     */
    public static function allowedBuckets(SubledgerType $type): array
    {
        return match ($type) {
            SubledgerType::Contract => [
                'available_minor',
                'held_milestones_minor',
                'frozen_minor',
                'pending_release_minor',
                'pending_refund_minor',
                'released_minor',
                'refunded_minor',
            ],
            SubledgerType::Milestone => [
                'funded_minor',
                'available_minor',
                'frozen_minor',
                'pending_release_minor',
                'released_minor',
                'refunded_minor',
            ],
            SubledgerType::Party => [
                'funded_minor',
                'entitled_minor',
                'held_minor',
                'paid_out_minor',
                'refunded_minor',
                'reduced_minor',
            ],
            default => [],
        };
    }

    /**
     * Open the contract subledger, party subledgers and milestone sub-accounts.
     * Idempotent: re-opening an already opened contract returns existing accounts.
     */
    public function openForContract(EscrowContract $contract, ?ProtectedPayment $payment = null): ContractSubledgerAccount
    {
        return DB::transaction(function () use ($contract, $payment): ContractSubledgerAccount {
            $account = ContractSubledgerAccount::where('escrow_contract_id', $contract->id)
                ->lockForUpdate()
                ->first();

            if (!$account) {
                $account = ContractSubledgerAccount::create([
                    'account_id' => 'csl_' . Str::lower(Str::ulid()),
                    'escrow_contract_id' => $contract->id,
                    'protected_payment_id' => $payment?->id,
                    'currency' => $contract->currency,
                ]);
            } elseif ($payment && !$account->protected_payment_id) {
                $account->update(['protected_payment_id' => $payment->id]);
            }

            foreach ($this->partyRolesFor($contract, $payment) as [$userId, $role]) {
                PartySubledgerAccount::firstOrCreate(
                    [
                        'escrow_contract_id' => $contract->id,
                        'user_id' => $userId,
                        'role' => $role,
                    ],
                    [
                        'account_id' => 'psl_' . Str::lower(Str::ulid()),
                        'protected_payment_id' => $payment?->id,
                        'currency' => $contract->currency,
                    ]
                );
            }

            if ($payment) {
                foreach ($payment->milestones as $milestone) {
                    MilestoneSubledgerAccount::firstOrCreate(
                        ['milestone_id' => $milestone->id],
                        [
                            'account_id' => 'msl_' . Str::lower(Str::ulid()),
                            'escrow_contract_id' => $contract->id,
                            'currency' => $contract->currency,
                        ]
                    );
                }
            }

            return $account;
        });
    }

    /**
     * @return array<int, array{0: int, 1: string}>
     */
    protected function partyRolesFor(EscrowContract $contract, ?ProtectedPayment $payment): array
    {
        if ($payment) {
            return [
                [(int) $payment->buyer_id, 'BUYER'],
                [(int) $payment->merchant_id, 'SELLER'],
            ];
        }

        $parties = [];
        $initiatorRole = strtoupper((string) $contract->initiator_role);
        $parties[] = [(int) $contract->initiator_id, $initiatorRole];

        if ($contract->counterparty_user_id) {
            $parties[] = [(int) $contract->counterparty_user_id, $initiatorRole === 'BUYER' ? 'SELLER' : 'BUYER'];
        }

        return $parties;
    }

    /**
     * Apply the DEPOSIT_CONFIRMED event to the subledger set atomically:
     * contract bucket credit, milestone earmarking and party positions.
     * Idempotent per funding journal (replayed webhooks are no-ops).
     */
    public function applyFundingConfirmed(EscrowContract $contract, ProtectedPayment $payment, LedgerJournal $journal): ContractSubledgerAccount
    {
        $base = 'fund:' . $journal->journal_id;
        $principal = (int) $payment->principal_amount_minor;
        $reference = (string) ($payment->custody_provider_reference ?? $payment->reference);

        return DB::transaction(function () use ($contract, $payment, $journal, $base, $principal, $reference): ContractSubledgerAccount {
            $account = $this->openForContract($contract, $payment);

            $meta = [
                'ledger_journal_id' => $journal->id,
                'reference' => $reference,
                'reason_code' => 'DEPOSIT_CONFIRMED',
                'actor_user_id' => $payment->buyer_id,
                'currency' => $payment->currency,
            ];

            $account = $this->creditDeposit($account, $principal, $meta + ['idempotency_key' => $base . ':contract']);

            $milestones = $payment->milestones;

            if ($milestones->isNotEmpty()) {
                // Earmark the full principal against milestones so it cannot be
                // released outside the milestone approval flow.
                $account = $this->transferBucket(
                    SubledgerType::Contract,
                    $account,
                    'available_minor',
                    'held_milestones_minor',
                    $principal,
                    $meta + ['idempotency_key' => $base . ':earmark']
                );

                foreach ($milestones as $milestone) {
                    $milestoneAccount = MilestoneSubledgerAccount::where('milestone_id', $milestone->id)->first();

                    if ($milestoneAccount) {
                        $this->creditMilestoneFunding(
                            $milestoneAccount,
                            (int) $milestone->amount_minor,
                            $meta + ['idempotency_key' => $base . ':milestone:' . $milestone->id]
                        );
                    }
                }
            }

            $buyerAccount = PartySubledgerAccount::where('escrow_contract_id', $contract->id)
                ->where('user_id', $payment->buyer_id)
                ->where('role', 'BUYER')
                ->first();

            $sellerAccount = PartySubledgerAccount::where('escrow_contract_id', $contract->id)
                ->where('user_id', $payment->merchant_id)
                ->where('role', 'SELLER')
                ->first();

            if ($buyerAccount) {
                $this->increaseBucket(SubledgerType::Party, $buyerAccount, 'funded_minor', $principal, $meta + ['idempotency_key' => $base . ':party-buyer']);
            }

            if ($sellerAccount) {
                $this->increaseBucket(SubledgerType::Party, $sellerAccount, 'entitled_minor', $principal, $meta + ['idempotency_key' => $base . ':party-seller']);
            }

            $this->assertContractConservation($account->refresh());

            return $account;
        });
    }

    /**
     * Append an immutable subledger entry (idempotent by key).
     *
     * @param array<string, mixed> $meta
     */
    public function appendEntry(
        SubledgerType $type,
        int $subledgerId,
        string $bucket,
        string $direction,
        int $amountMinor,
        int $balanceAfterMinor,
        array $meta = []
    ): SubledgerEntry {
        $idempotencyKey = (string) ($meta['idempotency_key'] ?? throw new DomainException('Subledger entries require an idempotency key.'));

        $existing = SubledgerEntry::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        return SubledgerEntry::create([
            'entry_id' => 'sle_' . Str::lower(Str::ulid()),
            'subledger_type' => $type,
            'subledger_id' => $subledgerId,
            'bucket' => $bucket,
            'direction' => $direction,
            'currency' => (string) ($meta['currency'] ?? 'TZS'),
            'amount_minor' => $amountMinor,
            'balance_after_minor' => $balanceAfterMinor,
            'ledger_journal_id' => $meta['ledger_journal_id'] ?? null,
            'reference' => $meta['reference'] ?? null,
            'reason_code' => $meta['reason_code'] ?? null,
            'actor_user_id' => $meta['actor_user_id'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'created_at' => now(),
        ]);
    }

    /**
     * Move an amount between two buckets of one subledger account under a row
     * lock (double-spend guard). Posts two append-only entries atomically.
     *
     * @param array<string, mixed> $meta must contain idempotency_key
     */
    public function transferBucket(
        SubledgerType $type,
        Model $account,
        string $fromBucket,
        string $toBucket,
        int $amountMinor,
        array $meta = []
    ): Model {
        if ($amountMinor <= 0) {
            throw new DomainException('Subledger transfer amount must be a positive integer minor value.');
        }

        $allowed = self::allowedBuckets($type);
        if (!in_array($fromBucket, $allowed, true) || !in_array($toBucket, $allowed, true)) {
            throw new DomainException("Bucket pair {$fromBucket}->{$toBucket} is not permitted for {$type->value}.");
        }

        $baseKey = (string) ($meta['idempotency_key'] ?? throw new DomainException('Subledger transfers require an idempotency key.'));

        return DB::transaction(function () use ($type, $account, $fromBucket, $toBucket, $amountMinor, $meta, $baseKey): Model {
            /** @var Model $locked */
            $locked = $account->newQuery()->whereKey($account->getKey())->lockForUpdate()->first();

            // Operation-level idempotency: a replayed webhook/job must not move buckets twice.
            if (SubledgerEntry::where('idempotency_key', $baseKey . ':src')->exists()) {
                return $locked;
            }

            if ((int) $locked->{$fromBucket} < $amountMinor) {
                throw new DomainException(
                    "Insufficient {$type->value} subledger balance in {$fromBucket}: has {$locked->{$fromBucket}}, needs {$amountMinor}."
                );
            }

            $locked->{$fromBucket} = (int) $locked->{$fromBucket} - $amountMinor;
            $locked->{$toBucket} = (int) $locked->{$toBucket} + $amountMinor;
            $locked->state_version = (int) $locked->state_version + 1;
            $locked->save();

            $this->appendEntry($type, (int) $locked->id, $fromBucket, 'DECREASE', $amountMinor, (int) $locked->{$fromBucket}, array_merge($meta, ['idempotency_key' => $baseKey . ':src']));
            $this->appendEntry($type, (int) $locked->id, $toBucket, 'INCREASE', $amountMinor, (int) $locked->{$toBucket}, array_merge($meta, ['idempotency_key' => $baseKey . ':dst']));

            return $locked;
        });
    }

    /**
     * Raise a single bucket of any subledger account under a row lock
     * (append-only INCREASE entry), idempotent per key.
     *
     * @param array<string, mixed> $meta
     */
    public function increaseBucket(SubledgerType $type, Model $account, string $bucket, int $amountMinor, array $meta = []): Model
    {
        if ($amountMinor <= 0) {
            throw new DomainException('Subledger increase amount must be a positive integer minor value.');
        }

        if (!in_array($bucket, self::allowedBuckets($type), true)) {
            throw new DomainException("Bucket {$bucket} is not permitted for {$type->value}.");
        }

        $baseKey = (string) ($meta['idempotency_key'] ?? throw new DomainException('Subledger increases require an idempotency key.'));

        return DB::transaction(function () use ($type, $account, $bucket, $amountMinor, $meta, $baseKey): Model {
            /** @var Model $locked */
            $locked = $account->newQuery()->whereKey($account->getKey())->lockForUpdate()->first();

            if (SubledgerEntry::where('idempotency_key', $baseKey)->exists()) {
                return $locked;
            }

            $locked->{$bucket} = (int) $locked->{$bucket} + $amountMinor;
            $locked->state_version = (int) $locked->state_version + 1;
            $locked->save();

            $this->appendEntry($type, (int) $locked->id, $bucket, 'INCREASE', $amountMinor, (int) $locked->{$bucket}, array_merge($meta, ['idempotency_key' => $baseKey]));

            return $locked;
        });
    }

    /**
     * Credit a confirmed deposit: raises both the deposited total and the
     * available bucket so the conservation invariant stays intact.
     *
     * @param array<string, mixed> $meta
     */
    public function creditDeposit(ContractSubledgerAccount $account, int $amountMinor, array $meta = []): ContractSubledgerAccount
    {
        if ($amountMinor <= 0) {
            throw new DomainException('Deposit amount must be a positive integer minor value.');
        }

        $baseKey = (string) ($meta['idempotency_key'] ?? throw new DomainException('Deposit credits require an idempotency key.'));

        return DB::transaction(function () use ($account, $amountMinor, $meta, $baseKey): ContractSubledgerAccount {
            /** @var ContractSubledgerAccount $locked */
            $locked = ContractSubledgerAccount::whereKey($account->id)->lockForUpdate()->first();

            // Operation-level idempotency: a replayed webhook/job must not credit twice.
            if (SubledgerEntry::where('idempotency_key', $baseKey . ':dep')->exists()) {
                return $locked;
            }

            $locked->deposited_minor = (int) $locked->deposited_minor + $amountMinor;
            $locked->available_minor = (int) $locked->available_minor + $amountMinor;
            $locked->state_version = (int) $locked->state_version + 1;
            $locked->save();

            $this->appendEntry(SubledgerType::Contract, $locked->id, 'deposited_minor', 'INCREASE', $amountMinor, (int) $locked->deposited_minor, array_merge($meta, ['idempotency_key' => $baseKey . ':dep']));
            $this->appendEntry(SubledgerType::Contract, $locked->id, 'available_minor', 'INCREASE', $amountMinor, (int) $locked->available_minor, array_merge($meta, ['idempotency_key' => $baseKey . ':avl']));

            return $locked;
        });
    }

    /**
     * Credit a milestone sub-account: funded and available rise together so
     * funded == available + frozen + pending_release + released + refunded.
     *
     * @param array<string, mixed> $meta
     */
    public function creditMilestoneFunding(MilestoneSubledgerAccount $account, int $amountMinor, array $meta = []): MilestoneSubledgerAccount
    {
        if ($amountMinor <= 0) {
            throw new DomainException('Milestone funding amount must be a positive integer minor value.');
        }

        $baseKey = (string) ($meta['idempotency_key'] ?? throw new DomainException('Milestone funding requires an idempotency key.'));

        return DB::transaction(function () use ($account, $amountMinor, $meta, $baseKey): MilestoneSubledgerAccount {
            /** @var MilestoneSubledgerAccount $locked */
            $locked = MilestoneSubledgerAccount::whereKey($account->id)->lockForUpdate()->first();

            if (SubledgerEntry::where('idempotency_key', $baseKey . ':fnd')->exists()) {
                return $locked;
            }

            $locked->funded_minor = (int) $locked->funded_minor + $amountMinor;
            $locked->available_minor = (int) $locked->available_minor + $amountMinor;
            $locked->state_version = (int) $locked->state_version + 1;
            $locked->save();

            $this->appendEntry(SubledgerType::Milestone, $locked->id, 'funded_minor', 'INCREASE', $amountMinor, (int) $locked->funded_minor, array_merge($meta, ['idempotency_key' => $baseKey . ':fnd']));
            $this->appendEntry(SubledgerType::Milestone, $locked->id, 'available_minor', 'INCREASE', $amountMinor, (int) $locked->available_minor, array_merge($meta, ['idempotency_key' => $baseKey . ':avl']));

            return $locked;
        });
    }

    /**
     * Money-conservation invariant: deposited == sum of living buckets.
     */
    public function assertContractConservation(ContractSubledgerAccount $account): void
    {
        $difference = $account->conservationDifference();

        if ($difference !== 0) {
            throw new DomainException(
                "Contract subledger {$account->account_id} violates money conservation by {$difference} minor units."
            );
        }
    }

    /**
     * Portfolio-level invariant scan used by reconciliation and tests.
     *
     * @return array{violations: array<int, array{account_id: string, difference: int}>, contracts_scanned: int, held_total_minor: int}
     */
    public function scanPortfolioInvariant(): array
    {
        $violations = [];
        $heldTotal = 0;

        ContractSubledgerAccount::query()->orderBy('id')->each(function (ContractSubledgerAccount $account) use (&$violations, &$heldTotal): void {
            $difference = $account->conservationDifference();

            if ($difference !== 0) {
                $violations[] = ['account_id' => $account->account_id, 'difference' => $difference];
            }

            $heldTotal += (int) $account->available_minor
                + (int) $account->held_milestones_minor
                + (int) $account->frozen_minor
                + (int) $account->pending_release_minor
                + (int) $account->pending_refund_minor;
        });

        return [
            'violations' => $violations,
            'contracts_scanned' => ContractSubledgerAccount::count(),
            'held_total_minor' => $heldTotal,
        ];
    }

    /**
     * Month-end / daily close snapshot of subledger balances.
     */
    public function snapshotBalances(?string $asOfDate = null): int
    {
        $date = $asOfDate ?? now()->toDateString();
        $written = 0;

        ContractSubledgerAccount::query()->orderBy('id')->each(function (ContractSubledgerAccount $account) use ($date, &$written): void {
            \App\Models\BalanceSnapshot::updateOrCreate(
                [
                    'snapshot_type' => 'CONTRACT_SUBLEDGER',
                    'subject_type' => ContractSubledgerAccount::class,
                    'subject_id' => $account->id,
                    'as_of_date' => $date,
                ],
                [
                    'currency' => $account->currency,
                    'balance_minor' => (int) $account->available_minor
                        + (int) $account->held_milestones_minor
                        + (int) $account->frozen_minor
                        + (int) $account->pending_release_minor
                        + (int) $account->pending_refund_minor,
                    'metadata' => ['account_id' => $account->account_id],
                ]
            );
            $written++;
        });

        PartySubledgerAccount::query()->orderBy('id')->each(function (PartySubledgerAccount $account) use ($date, &$written): void {
            \App\Models\BalanceSnapshot::updateOrCreate(
                [
                    'snapshot_type' => 'PARTY_SUBLEDGER',
                    'subject_type' => PartySubledgerAccount::class,
                    'subject_id' => $account->id,
                    'as_of_date' => $date,
                ],
                [
                    'currency' => $account->currency,
                    'balance_minor' => (int) $account->funded_minor - (int) $account->paid_out_minor - (int) $account->refunded_minor,
                    'metadata' => [
                        'account_id' => $account->account_id,
                        'role' => $account->role,
                        'entitled_minor' => (int) $account->entitled_minor,
                        'held_minor' => (int) $account->held_minor,
                        'reduced_minor' => (int) $account->reduced_minor,
                    ],
                ]
            );
            $written++;
        });

        return $written;
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 3 - Release, payout settlement and reversal operations
    |--------------------------------------------------------------------------
    | Reservation (RELEASE_REQUESTED) is subledger-only: money does not leave
    | the pooled account until the provider confirms the disbursement, but the
    | contract's available balance drops immediately so it can never be spent
    | twice while a payout is in flight.
    */

    public function accountFor(EscrowContract $contract): ?ContractSubledgerAccount
    {
        return ContractSubledgerAccount::where('escrow_contract_id', $contract->id)->first();
    }

    /**
     * RELEASE_REQUESTED: move funds available -> pending_release and, for the
     * seller, entitled -> held (money owed but not yet paid out).
     *
     * @param array<string, mixed> $meta
     */
    public function reserveRelease(
        EscrowContract $contract,
        ProtectedPayment $payment,
        int $amountMinor,
        string $baseKey,
        ?int $milestoneId = null,
        ?int $actorUserId = null
    ): void {
        DB::transaction(function () use ($contract, $payment, $amountMinor, $baseKey, $milestoneId, $actorUserId): void {
            $meta = [
                'ledger_journal_id' => null,
                'reference' => (string) ($payment->escrowContract?->reference ?? $payment->reference),
                'reason_code' => 'RELEASE_REQUESTED',
                'actor_user_id' => $actorUserId,
                'currency' => $payment->currency,
            ];

            $account = $this->accountFor($contract);

            if ($account) {
                $this->transferBucket(
                    SubledgerType::Contract,
                    $account,
                    'available_minor',
                    'pending_release_minor',
                    $amountMinor,
                    $meta + ['idempotency_key' => $baseKey . ':contract']
                );
            }

            if ($milestoneId) {
                $milestoneAccount = MilestoneSubledgerAccount::where('milestone_id', $milestoneId)->first();

                if ($milestoneAccount) {
                    $this->transferBucket(
                        SubledgerType::Milestone,
                        $milestoneAccount,
                        'available_minor',
                        'pending_release_minor',
                        $amountMinor,
                        $meta + ['idempotency_key' => $baseKey . ':milestone']
                    );
                }
            }

            $sellerAccount = PartySubledgerAccount::where('escrow_contract_id', $contract->id)
                ->where('user_id', $payment->merchant_id)
                ->where('role', 'SELLER')
                ->first();

            if ($sellerAccount && (int) $sellerAccount->entitled_minor >= $amountMinor) {
                $this->transferBucket(
                    SubledgerType::Party,
                    $sellerAccount,
                    'entitled_minor',
                    'held_minor',
                    $amountMinor,
                    $meta + ['idempotency_key' => $baseKey . ':party']
                );
            }
        });
    }

    /**
     * Provider confirmed the release: pending_release -> released on contract
     * and milestone subledgers.
     *
     * @param array<string, mixed> $meta
     */
    public function confirmRelease(
        EscrowContract $contract,
        int $amountMinor,
        string $baseKey,
        ?int $milestoneId = null,
        array $meta = []
    ): void {
        DB::transaction(function () use ($contract, $amountMinor, $baseKey, $milestoneId, $meta): void {
            $meta += ['reason_code' => 'RELEASE_CONFIRMED'];

            $account = $this->accountFor($contract);

            if ($account) {
                $this->transferBucket(
                    SubledgerType::Contract,
                    $account,
                    'pending_release_minor',
                    'released_minor',
                    $amountMinor,
                    $meta + ['idempotency_key' => $baseKey . ':contract']
                );
            }

            if ($milestoneId) {
                $milestoneAccount = MilestoneSubledgerAccount::where('milestone_id', $milestoneId)->first();

                if ($milestoneAccount) {
                    $this->transferBucket(
                        SubledgerType::Milestone,
                        $milestoneAccount,
                        'pending_release_minor',
                        'released_minor',
                        $amountMinor,
                        $meta + ['idempotency_key' => $baseKey . ':milestone']
                    );
                }
            }
        });
    }

    /**
     * Release failed at the provider: put the money straight back to available
     * (pending_release -> available) and the seller back to entitled.
     * Reversal-only - never edit prior entries.
     *
     * @param array<string, mixed> $meta
     */
    public function reverseReleaseReservation(
        EscrowContract $contract,
        ProtectedPayment $payment,
        int $amountMinor,
        string $baseKey,
        ?int $milestoneId = null,
        array $meta = []
    ): void {
        DB::transaction(function () use ($contract, $payment, $amountMinor, $baseKey, $milestoneId, $meta): void {
            $meta += ['reason_code' => 'RELEASE_FAILED_REVERSAL'];

            $account = $this->accountFor($contract);

            if ($account) {
                $this->transferBucket(
                    SubledgerType::Contract,
                    $account,
                    'pending_release_minor',
                    'available_minor',
                    $amountMinor,
                    $meta + ['idempotency_key' => $baseKey . ':contract']
                );
            }

            if ($milestoneId) {
                $milestoneAccount = MilestoneSubledgerAccount::where('milestone_id', $milestoneId)->first();

                if ($milestoneAccount) {
                    $this->transferBucket(
                        SubledgerType::Milestone,
                        $milestoneAccount,
                        'pending_release_minor',
                        'available_minor',
                        $amountMinor,
                        $meta + ['idempotency_key' => $baseKey . ':milestone']
                    );
                }
            }

            $sellerAccount = PartySubledgerAccount::where('escrow_contract_id', $contract->id)
                ->where('user_id', $payment->merchant_id)
                ->where('role', 'SELLER')
                ->first();

            if ($sellerAccount && (int) $sellerAccount->held_minor >= $amountMinor) {
                $this->transferBucket(
                    SubledgerType::Party,
                    $sellerAccount,
                    'held_minor',
                    'entitled_minor',
                    $amountMinor,
                    $meta + ['idempotency_key' => $baseKey . ':party']
                );
            }
        });
    }

    /**
     * Payout settled: seller held -> paid_out.
     *
     * @param array<string, mixed> $meta
     */
    public function confirmPayoutSettlement(EscrowContract $contract, ProtectedPayment $payment, int $amountMinor, string $baseKey, array $meta = []): void
    {
        DB::transaction(function () use ($contract, $payment, $amountMinor, $baseKey, $meta): void {
            $sellerAccount = PartySubledgerAccount::where('escrow_contract_id', $contract->id)
                ->where('user_id', $payment->merchant_id)
                ->where('role', 'SELLER')
                ->first();

            if (!$sellerAccount || (int) $sellerAccount->held_minor < $amountMinor) {
                return;
            }

            $this->transferBucket(
                SubledgerType::Party,
                $sellerAccount,
                'held_minor',
                'paid_out_minor',
                $amountMinor,
                $meta + ['reason_code' => 'PAYOUT_SUCCEEDED', 'idempotency_key' => $baseKey . ':party']
            );
        });
    }

    /**
     * Payout failed: seller paid_out -> held.
     *
     * @param array<string, mixed> $meta
     */
    public function reversePayoutSettlement(EscrowContract $contract, ProtectedPayment $payment, int $amountMinor, string $baseKey, array $meta = []): void
    {
        DB::transaction(function () use ($contract, $payment, $amountMinor, $baseKey, $meta): void {
            $sellerAccount = PartySubledgerAccount::where('escrow_contract_id', $contract->id)
                ->where('user_id', $payment->merchant_id)
                ->where('role', 'SELLER')
                ->first();

            if (!$sellerAccount || (int) $sellerAccount->paid_out_minor < $amountMinor) {
                return;
            }

            $this->transferBucket(
                SubledgerType::Party,
                $sellerAccount,
                'paid_out_minor',
                'held_minor',
                $amountMinor,
                $meta + ['reason_code' => 'PAYOUT_FAILED_REVERSAL', 'idempotency_key' => $baseKey . ':party']
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 4 - Dispute freezes and refunds
    |--------------------------------------------------------------------------
    | Frozen money is still customer money: DISPUTE_OPENED moves available ->
    | frozen (never out of the contract), and resolution returns it to exactly
    | one of released / refunded / available so conservation always holds.
    */

    /**
     * DISPUTE_OPENED: ring-fence the disputed amount inside the contract.
     *
     * @param array<string, mixed> $meta
     */
    public function freezeForDispute(EscrowContract $contract, int $amountMinor, string $baseKey, ?int $actorUserId = null, array $meta = []): void
    {
        $account = $this->accountFor($contract);

        if (!$account) {
            return;
        }

        $this->transferBucket(
            SubledgerType::Contract,
            $account,
            'available_minor',
            'frozen_minor',
            $amountMinor,
            $meta + [
                'reason_code' => 'DISPUTE_OPENED',
                'actor_user_id' => $actorUserId,
                'idempotency_key' => $baseKey . ':contract',
            ]
        );
    }

    /**
     * DISPUTE_RESOLVED: split a frozen amount into a released part (to the
     * seller) and/or a refunded part (to the buyer).
     *
     * @param array<string, mixed> $meta
     */
    public function settleDisputeHold(
        EscrowContract $contract,
        int $releasedMinor,
        int $refundedMinor,
        string $baseKey,
        ?int $actorUserId = null,
        array $meta = [],
        ?ProtectedPayment $payment = null
    ): void {
        $account = $this->accountFor($contract);

        if (!$account) {
            return;
        }

        $meta += ['reason_code' => 'DISPUTE_RESOLVED', 'actor_user_id' => $actorUserId];

        if ($releasedMinor > 0) {
            $this->transferBucket(
                SubledgerType::Contract,
                $account,
                'frozen_minor',
                'released_minor',
                $releasedMinor,
                $meta + ['idempotency_key' => $baseKey . ':released']
            );
        }

        if ($refundedMinor > 0) {
            $this->transferBucket(
                SubledgerType::Contract,
                $account,
                'frozen_minor',
                'refunded_minor',
                $refundedMinor,
                $meta + ['idempotency_key' => $baseKey . ':refunded']
            );
        }

        $this->assertContractConservation($account->refresh());

        if ($payment) {
            $sellerAccount = PartySubledgerAccount::where('escrow_contract_id', $contract->id)
                ->where('user_id', $payment->merchant_id)
                ->where('role', 'SELLER')
                ->first();

            $buyerAccount = PartySubledgerAccount::where('escrow_contract_id', $contract->id)
                ->where('user_id', $payment->buyer_id)
                ->where('role', 'BUYER')
                ->first();

            if ($sellerAccount && $releasedMinor > 0 && (int) $sellerAccount->entitled_minor >= $releasedMinor) {
                $this->transferBucket(
                    SubledgerType::Party,
                    $sellerAccount,
                    'entitled_minor',
                    'held_minor',
                    $releasedMinor,
                    $meta + ['idempotency_key' => $baseKey . ':party-held']
                );
            }

            if ($sellerAccount && $refundedMinor > 0 && (int) $sellerAccount->entitled_minor >= $refundedMinor) {
                $this->transferBucket(
                    SubledgerType::Party,
                    $sellerAccount,
                    'entitled_minor',
                    'reduced_minor',
                    $refundedMinor,
                    $meta + ['idempotency_key' => $baseKey . ':party-reduced']
                );
            }

            if ($buyerAccount && $refundedMinor > 0 && (int) $buyerAccount->funded_minor >= $refundedMinor) {
                $this->transferBucket(
                    SubledgerType::Party,
                    $buyerAccount,
                    'funded_minor',
                    'refunded_minor',
                    $refundedMinor,
                    $meta + ['idempotency_key' => $baseKey . ':party-refunded']
                );
            }
        }
    }

    /**
     * REFUND_REQUESTED: reserve the amount so it cannot be released while the
     * refund is in flight (available -> pending_refund).
     *
     * @param array<string, mixed> $meta
     */
    public function reserveRefund(
        EscrowContract $contract,
        int $amountMinor,
        string $baseKey,
        ?string $milestoneUuid = null,
        ?int $actorUserId = null,
        array $meta = []
    ): void {
        $account = $this->accountFor($contract);

        if (!$account) {
            return;
        }

        $meta += ['reason_code' => 'REFUND_REQUESTED', 'actor_user_id' => $actorUserId];

        $this->transferBucket(
            SubledgerType::Contract,
            $account,
            'available_minor',
            'pending_refund_minor',
            $amountMinor,
            $meta + ['idempotency_key' => $baseKey . ':contract']
        );

        if ($milestoneUuid !== null) {
            $milestoneAccount = MilestoneSubledgerAccount::where('milestone_id', $milestoneUuid)->first();

            if ($milestoneAccount) {
                $this->transferBucket(
                    SubledgerType::Milestone,
                    $milestoneAccount,
                    'available_minor',
                    'pending_release_minor',
                    $amountMinor,
                    $meta + ['idempotency_key' => $baseKey . ':milestone']
                );
            }
        }
    }

    /**
     * REFUND_SUCCEEDED: pending_refund -> refunded on contract + milestone, and
     * the buyer's party position moves funded -> refunded.
     *
     * @param array<string, mixed> $meta
     */
    public function confirmRefund(
        EscrowContract $contract,
        ProtectedPayment $payment,
        int $amountMinor,
        string $baseKey,
        ?string $milestoneUuid = null,
        array $meta = []
    ): void {
        DB::transaction(function () use ($contract, $payment, $amountMinor, $baseKey, $milestoneUuid, $meta): void {
            $meta += ['reason_code' => 'REFUND_SUCCEEDED'];

            $account = $this->accountFor($contract);

            if ($account) {
                $this->transferBucket(
                    SubledgerType::Contract,
                    $account,
                    'pending_refund_minor',
                    'refunded_minor',
                    $amountMinor,
                    $meta + ['idempotency_key' => $baseKey . ':contract']
                );
            }

            if ($milestoneUuid !== null) {
                $milestoneAccount = MilestoneSubledgerAccount::where('milestone_id', $milestoneUuid)->first();

                if ($milestoneAccount) {
                    $this->transferBucket(
                        SubledgerType::Milestone,
                        $milestoneAccount,
                        'pending_release_minor',
                        'refunded_minor',
                        $amountMinor,
                        $meta + ['idempotency_key' => $baseKey . ':milestone']
                    );
                }
            }

            $buyerAccount = PartySubledgerAccount::where('escrow_contract_id', $contract->id)
                ->where('user_id', $payment->buyer_id)
                ->where('role', 'BUYER')
                ->first();

            if ($buyerAccount && (int) $buyerAccount->funded_minor >= $amountMinor) {
                $this->transferBucket(
                    SubledgerType::Party,
                    $buyerAccount,
                    'funded_minor',
                    'refunded_minor',
                    $amountMinor,
                    $meta + ['idempotency_key' => $baseKey . ':party']
                );
            }

            $sellerAccount = PartySubledgerAccount::where('escrow_contract_id', $contract->id)
                ->where('user_id', $payment->merchant_id)
                ->where('role', 'SELLER')
                ->first();

            if ($sellerAccount && (int) $sellerAccount->entitled_minor >= $amountMinor) {
                $this->transferBucket(
                    SubledgerType::Party,
                    $sellerAccount,
                    'entitled_minor',
                    'reduced_minor',
                    $amountMinor,
                    $meta + ['idempotency_key' => $baseKey . ':party-seller']
                );
            }
        });
    }

    /**
     * Refund failed at the provider: pending_refund -> available (reverse only).
     *
     * @param array<string, mixed> $meta
     */
    public function reverseRefundReservation(
        EscrowContract $contract,
        int $amountMinor,
        string $baseKey,
        ?string $milestoneUuid = null,
        array $meta = []
    ): void {
        $account = $this->accountFor($contract);

        if (!$account) {
            return;
        }

        $meta += ['reason_code' => 'REFUND_FAILED_REVERSAL'];

        $this->transferBucket(
            SubledgerType::Contract,
            $account,
            'pending_refund_minor',
            'available_minor',
            $amountMinor,
            $meta + ['idempotency_key' => $baseKey . ':contract']
        );

        if ($milestoneUuid !== null) {
            $milestoneAccount = MilestoneSubledgerAccount::where('milestone_id', $milestoneUuid)->first();

            if ($milestoneAccount) {
                $this->transferBucket(
                    SubledgerType::Milestone,
                    $milestoneAccount,
                    'pending_release_minor',
                    'available_minor',
                    $amountMinor,
                    $meta + ['idempotency_key' => $baseKey . ':milestone']
                );
            }
        }
    }
}
