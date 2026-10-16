<?php

namespace App\Services;

use App\Enums\SettlementStatus;
use App\Models\DisputeCase;
use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use App\Models\LedgerPosting;
use App\Models\PaymentIntent;
use App\Models\ProtectedPayment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LedgerService
{
    public const ACC_PROVIDER_CLEARING = '1100-PROVIDER-CLEARING';
    public const ACC_PROTECTED_CUSTOMER_LIABILITY = '2100-PROTECTED-CUSTOMER-LIABILITY';
    public const ACC_MERCHANT_PAYABLE_AVAILABLE = '2200-MERCHANT-PAYABLE-AVAILABLE';
    public const ACC_DEFERRED_PLATFORM_FEE = '2300-DEFERRED-PLATFORM-FEE';
    public const ACC_TAX_PAYABLE = '2400-TAX-PAYABLE';
    public const ACC_REFUND_PAYABLE = '2500-REFUND-PAYABLE';
    public const ACC_DISPUTE_FROZEN_LIABILITY = '2600-DISPUTE-FROZEN-LIABILITY';
    public const ACC_EARNED_PLATFORM_REVENUE = '4100-EARNED-PLATFORM-REVENUE';

    /**
     * Ensure the canonical operational chart of accounts exists (FR-M08-01).
     */
    public function ensureChartOfAccountsExists(string $currency = 'TZS'): void
    {
        $accounts = [
            [
                'account_code' => self::ACC_PROVIDER_CLEARING,
                'account_name' => 'Licensed Provider Custodial Clearing & Settlement Receivable',
                'account_class' => 'ASSET_CONTROL',
                'normal_side' => 'DEBIT',
                'description' => 'Tracks provider-confirmed funds held under licensed partner custody.',
            ],
            [
                'account_code' => self::ACC_PROTECTED_CUSTOMER_LIABILITY,
                'account_name' => 'Protected Customer Funds Obligation',
                'account_class' => 'LIABILITY',
                'normal_side' => 'CREDIT',
                'description' => 'Tracks conditionally held principal awaiting fulfillment release or refund.',
            ],
            [
                'account_code' => self::ACC_MERCHANT_PAYABLE_AVAILABLE,
                'account_name' => 'Merchant Payable Available for Settlement',
                'account_class' => 'LIABILITY',
                'normal_side' => 'CREDIT',
                'description' => 'Tracks released and unrestricted merchant obligations eligible for settlement.',
            ],
            [
                'account_code' => self::ACC_DEFERRED_PLATFORM_FEE,
                'account_name' => 'Deferred Airlipa Protection & Transaction Fee Liability',
                'account_class' => 'LIABILITY',
                'normal_side' => 'CREDIT',
                'description' => 'Tracks fees collected at funding but deferred until protection obligation reaches finality.',
            ],
            [
                'account_code' => self::ACC_TAX_PAYABLE,
                'account_name' => 'Statutory VAT / Tax Payable',
                'account_class' => 'LIABILITY',
                'normal_side' => 'CREDIT',
                'description' => 'Tracks statutory taxes on Airlipa service charges.',
            ],
            [
                'account_code' => self::ACC_REFUND_PAYABLE,
                'account_name' => 'Customer Refund Payable Control',
                'account_class' => 'LIABILITY',
                'normal_side' => 'CREDIT',
                'description' => 'Tracks authorized customer refunds pending/executed with provider.',
            ],
            [
                'account_code' => self::ACC_DISPUTE_FROZEN_LIABILITY,
                'account_name' => 'Dispute Frozen Protected Obligation',
                'account_class' => 'LIABILITY',
                'normal_side' => 'CREDIT',
                'description' => 'Tracks disputed protected principal blocked from automatic release or settlement.',
            ],
            [
                'account_code' => self::ACC_EARNED_PLATFORM_REVENUE,
                'account_name' => 'Earned Airlipa Platform & Protection Service Revenue',
                'account_class' => 'REVENUE',
                'normal_side' => 'CREDIT',
                'description' => 'Tracks recognized Airlipa service fee income after obligation satisfaction.',
            ],
        ];

        foreach ($accounts as $account) {
            LedgerAccount::firstOrCreate(
                ['account_code' => $account['account_code']],
                array_merge($account, [
                    'currency' => strtoupper($currency),
                    'is_active' => true,
                ])
            );
        }
    }

    /**
     * Post a balanced, append-only double-entry journal (FR-031, Rule 36, SASDS 4.3.6.B).
     *
     * @param array<int, array{
     *   account_code: string,
     *   direction: 'DEBIT'|'CREDIT',
     *   amount_minor: int,
     *   party_user_id?: int|null,
     *   narration?: string|null
     * }> $lines
     * @param array<string, mixed> $metadata
     */
    public function postJournal(
        string $correlationId,
        string $eventType,
        string $postingRuleCode,
        string $idempotencyKey,
        string $currency,
        string $description,
        array $lines,
        ?int $protectedPaymentId = null,
        ?string $providerReference = null,
        ?int $actorUserId = null,
        ?int $reversalOfJournalId = null,
        array $metadata = []
    ): LedgerJournal {
        $this->ensureChartOfAccountsExists($currency);

        // Idempotent check
        $existing = LedgerJournal::with('postings.account')
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        // Filter out zero-amount lines and validate strictly positive integer amounts
        $validLines = array_values(array_filter($lines, fn (array $line): bool => (int) $line['amount_minor'] > 0));

        if (count($validLines) < 2) {
            throw new DomainException('Double-entry journal requires at least one debit and one credit posting with positive minor units.');
        }

        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($validLines as $line) {
            $amount = (int) $line['amount_minor'];
            if ($amount <= 0) {
                throw new DomainException('Journal posting amount must be strictly positive.');
            }

            $direction = strtoupper($line['direction']);
            if ($direction === 'DEBIT') {
                $totalDebit += $amount;
            } elseif ($direction === 'CREDIT') {
                $totalCredit += $amount;
            } else {
                throw new DomainException("Invalid posting direction '{$direction}'. Must be DEBIT or CREDIT.");
            }
        }

        // Rule 36 / 4.3.6.B invariant: Sum(Debit) == Sum(Credit) > 0
        if ($totalDebit <= 0 || $totalDebit !== $totalCredit) {
            throw new DomainException("Unbalanced double-entry journal rejected: total_debit_minor ({$totalDebit}) !== total_credit_minor ({$totalCredit}).");
        }

        return DB::transaction(function () use (
            $correlationId,
            $eventType,
            $postingRuleCode,
            $idempotencyKey,
            $currency,
            $description,
            $validLines,
            $totalDebit,
            $totalCredit,
            $protectedPaymentId,
            $providerReference,
            $actorUserId,
            $reversalOfJournalId,
            $metadata
        ): LedgerJournal {
            $now = now();

            $journal = LedgerJournal::create([
                'journal_id' => 'jrn_' . Str::lower(Str::ulid()),
                'correlation_id' => $correlationId,
                'protected_payment_id' => $protectedPaymentId,
                'event_type' => $eventType,
                'posting_rule_code' => $postingRuleCode,
                'posting_rule_version' => 'v1.0',
                'idempotency_key' => $idempotencyKey,
                'currency' => strtoupper($currency),
                'total_debit_minor' => $totalDebit,
                'total_credit_minor' => $totalCredit,
                'provider_reference' => $providerReference,
                'actor_user_id' => $actorUserId,
                'reversal_of_journal_id' => $reversalOfJournalId,
                'description' => $description,
                'metadata' => $metadata,
                'posted_at' => $now,
            ]);

            foreach ($validLines as $line) {
                $account = LedgerAccount::where('account_code', $line['account_code'])->firstOrFail();

                LedgerPosting::create([
                    'posting_id' => 'pst_' . Str::lower(Str::ulid()),
                    'ledger_journal_id' => $journal->id,
                    'ledger_account_id' => $account->id,
                    'party_user_id' => $line['party_user_id'] ?? null,
                    'direction' => strtoupper($line['direction']),
                    'currency' => strtoupper($currency),
                    'amount_minor' => (int) $line['amount_minor'],
                    'narration' => $line['narration'] ?? $description,
                    'posted_at' => $now,
                ]);
            }

            return $journal->load('postings.account');
        });
    }

    /**
     * Post balanced journal for provider-confirmed funding (SASDS 3.5.6.C).
     * Debit: Provider Clearing (Total Payable)
     * Credit: Protected Customer Liability (Principal)
     * Credit: Deferred Platform Fee (Buyer Fee)
     * Credit: Tax Payable (Buyer Tax)
     */
    public function postFundingConfirmed(
        ProtectedPayment $payment,
        PaymentIntent $intent,
        string $providerReference,
        ?User $actor = null
    ): LedgerJournal {
        $lines = [
            [
                'account_code' => self::ACC_PROVIDER_CLEARING,
                'direction' => 'DEBIT',
                'amount_minor' => (int) $payment->total_payable_minor,
                'party_user_id' => $payment->buyer_id,
                'narration' => "Provider-confirmed funding received via {$payment->custody_provider_code} ({$providerReference})",
            ],
            [
                'account_code' => self::ACC_PROTECTED_CUSTOMER_LIABILITY,
                'direction' => 'CREDIT',
                'amount_minor' => (int) $payment->principal_amount_minor,
                'party_user_id' => $payment->buyer_id,
                'narration' => "Protected principal held for agreement {$payment->reference}",
            ],
        ];

        if ((int) $payment->buyer_fee_minor > 0) {
            $lines[] = [
                'account_code' => self::ACC_DEFERRED_PLATFORM_FEE,
                'direction' => 'CREDIT',
                'amount_minor' => (int) $payment->buyer_fee_minor,
                'party_user_id' => $payment->buyer_id,
                'narration' => "Deferred buyer protection service fee for {$payment->reference}",
            ];
        }

        if ((int) $payment->buyer_tax_minor > 0) {
            $lines[] = [
                'account_code' => self::ACC_TAX_PAYABLE,
                'direction' => 'CREDIT',
                'amount_minor' => (int) $payment->buyer_tax_minor,
                'party_user_id' => $payment->buyer_id,
                'narration' => "VAT payable on buyer protection fee for {$payment->reference}",
            ];
        }

        return $this->postJournal(
            correlationId: $payment->correlation_id,
            eventType: 'FUNDING_CONFIRMED',
            postingRuleCode: 'PR_PROTECTED_FUNDING_V1',
            idempotencyKey: "jrn_funding_{$payment->protected_payment_id}_{$intent->intent_id}",
            currency: $payment->currency,
            description: "Provider-confirmed funding for Protected Payment {$payment->reference}",
            lines: $lines,
            protectedPaymentId: $payment->id,
            providerReference: $providerReference,
            actorUserId: $actor?->id ?? $payment->buyer_id,
            metadata: [
                'intent_id' => $intent->intent_id,
                'quote_id' => $payment->feeQuote?->quote_id,
            ]
        );
    }

    /**
     * Post balanced journal when a dispute freezes protected funds (FR-014).
     * Debit: Protected Customer Liability
     * Credit: Dispute Frozen Liability
     */
    public function postDisputeFreeze(
        ProtectedPayment $payment,
        DisputeCase $dispute,
        int $amountMinor,
        ?User $actor = null
    ): LedgerJournal {
        return $this->postJournal(
            correlationId: $payment->correlation_id,
            eventType: 'DISPUTE_FREEZE',
            postingRuleCode: 'PR_DISPUTE_FREEZE_V1',
            idempotencyKey: "jrn_dispute_freeze_{$dispute->case_id}",
            currency: $payment->currency,
            description: "Dispute freeze for case {$dispute->case_reference} on Protected Payment {$payment->reference}",
            lines: [
                [
                    'account_code' => self::ACC_PROTECTED_CUSTOMER_LIABILITY,
                    'direction' => 'DEBIT',
                    'amount_minor' => $amountMinor,
                    'party_user_id' => $payment->buyer_id,
                    'narration' => "Reclassify protected funds to dispute hold ({$dispute->case_reference})",
                ],
                [
                    'account_code' => self::ACC_DISPUTE_FROZEN_LIABILITY,
                    'direction' => 'CREDIT',
                    'amount_minor' => $amountMinor,
                    'party_user_id' => $payment->buyer_id,
                    'narration' => "Frozen disputed allocation ({$dispute->case_reference})",
                ],
            ],
            protectedPaymentId: $payment->id,
            providerReference: $payment->custody_provider_reference,
            actorUserId: $actor?->id,
            metadata: [
                'case_id' => $dispute->case_id,
                'case_reference' => $dispute->case_reference,
            ]
        );
    }

    /**
     * Post balanced journal for authorized & provider-confirmed release (SASDS 3.5.6.C).
     */
    public function postProtectedRelease(
        ProtectedPayment $payment,
        int $principalReleaseMinor,
        int $merchantFeeMinor,
        int $merchantTaxMinor,
        int $deferredBuyerFeeRecognizedMinor,
        string $providerReference,
        string $idempotencyKey,
        ?User $actor = null,
        bool $fromDisputeFrozen = false
    ): LedgerJournal {
        $merchantNetMinor = max(0, $principalReleaseMinor - $merchantFeeMinor - $merchantTaxMinor);
        $sourceLiabilityAccount = $fromDisputeFrozen
            ? self::ACC_DISPUTE_FROZEN_LIABILITY
            : self::ACC_PROTECTED_CUSTOMER_LIABILITY;

        $lines = [
            [
                'account_code' => $sourceLiabilityAccount,
                'direction' => 'DEBIT',
                'amount_minor' => $principalReleaseMinor,
                'party_user_id' => $payment->buyer_id,
                'narration' => "Release of protected principal for {$payment->reference}",
            ],
            [
                'account_code' => self::ACC_MERCHANT_PAYABLE_AVAILABLE,
                'direction' => 'CREDIT',
                'amount_minor' => $merchantNetMinor,
                'party_user_id' => $payment->merchant_id,
                'narration' => "Net merchant payable released for {$payment->reference}",
            ],
        ];

        if ($merchantFeeMinor > 0) {
            $lines[] = [
                'account_code' => self::ACC_EARNED_PLATFORM_REVENUE,
                'direction' => 'CREDIT',
                'amount_minor' => $merchantFeeMinor,
                'party_user_id' => $payment->merchant_id,
                'narration' => "Earned merchant payment orchestration fee for {$payment->reference}",
            ];
        }

        if ($merchantTaxMinor > 0) {
            $lines[] = [
                'account_code' => self::ACC_TAX_PAYABLE,
                'direction' => 'CREDIT',
                'amount_minor' => $merchantTaxMinor,
                'party_user_id' => $payment->merchant_id,
                'narration' => "VAT on merchant fee for {$payment->reference}",
            ];
        }

        if ($deferredBuyerFeeRecognizedMinor > 0) {
            $lines[] = [
                'account_code' => self::ACC_DEFERRED_PLATFORM_FEE,
                'direction' => 'DEBIT',
                'amount_minor' => $deferredBuyerFeeRecognizedMinor,
                'party_user_id' => $payment->buyer_id,
                'narration' => "Recognize deferred buyer protection fee on release finality for {$payment->reference}",
            ];
            $lines[] = [
                'account_code' => self::ACC_EARNED_PLATFORM_REVENUE,
                'direction' => 'CREDIT',
                'amount_minor' => $deferredBuyerFeeRecognizedMinor,
                'party_user_id' => $payment->buyer_id,
                'narration' => "Earned buyer protection fee revenue for {$payment->reference}",
            ];
        }

        return $this->postJournal(
            correlationId: $payment->correlation_id,
            eventType: 'PROTECTED_RELEASE',
            postingRuleCode: 'PR_PROTECTED_RELEASE_V1',
            idempotencyKey: "jrn_release_{$payment->protected_payment_id}_{$idempotencyKey}",
            currency: $payment->currency,
            description: "Authorized release of protected funds for {$payment->reference}",
            lines: $lines,
            protectedPaymentId: $payment->id,
            providerReference: $providerReference,
            actorUserId: $actor?->id,
            metadata: [
                'principal_release_minor' => $principalReleaseMinor,
                'merchant_net_minor' => $merchantNetMinor,
                'merchant_fee_minor' => $merchantFeeMinor,
                'merchant_tax_minor' => $merchantTaxMinor,
                'deferred_buyer_fee_recognized_minor' => $deferredBuyerFeeRecognizedMinor,
            ]
        );
    }

    /**
     * Post balanced journal for authorized & provider-confirmed refund (SASDS 3.5.7, FR-013).
     */
    public function postProtectedRefund(
        ProtectedPayment $payment,
        int $principalRefundMinor,
        int $buyerFeeRefundMinor,
        int $buyerTaxRefundMinor,
        string $providerReference,
        string $idempotencyKey,
        ?User $actor = null,
        bool $fromDisputeFrozen = false
    ): LedgerJournal {
        $totalBuyerRefundMinor = $principalRefundMinor + $buyerFeeRefundMinor + $buyerTaxRefundMinor;
        $sourceLiabilityAccount = $fromDisputeFrozen
            ? self::ACC_DISPUTE_FROZEN_LIABILITY
            : self::ACC_PROTECTED_CUSTOMER_LIABILITY;

        $lines = [
            [
                'account_code' => $sourceLiabilityAccount,
                'direction' => 'DEBIT',
                'amount_minor' => $principalRefundMinor,
                'party_user_id' => $payment->buyer_id,
                'narration' => "Refund of protected principal to buyer for {$payment->reference}",
            ],
        ];

        if ($buyerFeeRefundMinor > 0) {
            $lines[] = [
                'account_code' => self::ACC_DEFERRED_PLATFORM_FEE,
                'direction' => 'DEBIT',
                'amount_minor' => $buyerFeeRefundMinor,
                'party_user_id' => $payment->buyer_id,
                'narration' => "Reversal of deferred buyer protection fee for {$payment->reference}",
            ];
        }

        if ($buyerTaxRefundMinor > 0) {
            $lines[] = [
                'account_code' => self::ACC_TAX_PAYABLE,
                'direction' => 'DEBIT',
                'amount_minor' => $buyerTaxRefundMinor,
                'party_user_id' => $payment->buyer_id,
                'narration' => "Reversal of VAT on refunded buyer fee for {$payment->reference}",
            ];
        }

        $lines[] = [
            'account_code' => self::ACC_PROVIDER_CLEARING,
            'direction' => 'CREDIT',
            'amount_minor' => $totalBuyerRefundMinor,
            'party_user_id' => $payment->buyer_id,
            'narration' => "Provider refund executed ({$providerReference}) for {$payment->reference}",
        ];

        return $this->postJournal(
            correlationId: $payment->correlation_id,
            eventType: 'PROTECTED_REFUND',
            postingRuleCode: 'PR_PROTECTED_REFUND_V1',
            idempotencyKey: "jrn_refund_{$payment->protected_payment_id}_{$idempotencyKey}",
            currency: $payment->currency,
            description: "Authorized customer refund for {$payment->reference}",
            lines: $lines,
            protectedPaymentId: $payment->id,
            providerReference: $providerReference,
            actorUserId: $actor?->id,
            metadata: [
                'principal_refund_minor' => $principalRefundMinor,
                'buyer_fee_refund_minor' => $buyerFeeRefundMinor,
                'buyer_tax_refund_minor' => $buyerTaxRefundMinor,
                'total_buyer_refund_minor' => $totalBuyerRefundMinor,
            ]
        );
    }

    /**
     * Create a compensating reversal journal for an existing journal without modifying history (BPA-DEF-07, FR-M08-03).
     */
    public function reverseJournal(LedgerJournal $original, User $approver, string $reason): LedgerJournal
    {
        $original->loadMissing('postings.account');

        $lines = [];
        foreach ($original->postings as $posting) {
            $lines[] = [
                'account_code' => $posting->account->account_code,
                'direction' => $posting->direction === 'DEBIT' ? 'CREDIT' : 'DEBIT',
                'amount_minor' => (int) $posting->amount_minor,
                'party_user_id' => $posting->party_user_id,
                'narration' => "Reversal of {$original->journal_id}: {$reason}",
            ];
        }

        return $this->postJournal(
            correlationId: $original->correlation_id,
            eventType: 'REVERSAL',
            postingRuleCode: 'PR_JOURNAL_REVERSAL_V1',
            idempotencyKey: "jrn_rev_{$original->journal_id}",
            currency: $original->currency,
            description: "Compensating reversal of journal {$original->journal_id}: {$reason}",
            lines: $lines,
            protectedPaymentId: $original->protected_payment_id,
            providerReference: $original->provider_reference,
            actorUserId: $approver->id,
            reversalOfJournalId: $original->id,
            metadata: ['reason' => $reason]
        );
    }

    /**
     * Compute operational obligation summary for a user/merchant (SASDS 3.7.9, FR-M08-08).
     * Explicitly distinguishes obligations from a wallet or deposit balance (BPA-DEF-12).
     *
     * @return array<string, mixed>
     */
    public function getObligationsSummary(User $user, string $currency = 'TZS'): array
    {
        $this->ensureChartOfAccountsExists($currency);

        $asMerchant = ProtectedPayment::where('merchant_id', $user->id)
            ->where('currency', strtoupper($currency))
            ->get();

        $asBuyer = ProtectedPayment::where('buyer_id', $user->id)
            ->where('currency', strtoupper($currency))
            ->get();

        $confirmedPrincipalMinor = (int) $asMerchant->sum('funded_amount_minor');
        $protectedPendingMinor = (int) $asMerchant->sum('protected_balance_minor');
        $disputeHoldsMinor = (int) $asMerchant->sum('disputed_amount_minor');
        $releasedGrossMinor = (int) $asMerchant->sum('released_amount_minor');
        $refundedMinor = (int) $asMerchant->sum('refunded_amount_minor');

        // Calculate net available merchant payable from ledger postings on 2200-MERCHANT-PAYABLE-AVAILABLE
        $payableAccount = LedgerAccount::where('account_code', self::ACC_MERCHANT_PAYABLE_AVAILABLE)->first();
        $availableForSettlementMinor = 0;

        if ($payableAccount) {
            $credits = (int) LedgerPosting::where('ledger_account_id', $payableAccount->id)
                ->where('party_user_id', $user->id)
                ->where('direction', 'CREDIT')
                ->sum('amount_minor');

            $debits = (int) LedgerPosting::where('ledger_account_id', $payableAccount->id)
                ->where('party_user_id', $user->id)
                ->where('direction', 'DEBIT')
                ->sum('amount_minor');

            $availableForSettlementMinor = max(0, $credits - $debits);
        }

        // Verify global trial balance across all journals
        $totalLedgerDebits = (int) LedgerPosting::where('currency', strtoupper($currency))
            ->where('direction', 'DEBIT')
            ->sum('amount_minor');
        $totalLedgerCredits = (int) LedgerPosting::where('currency', strtoupper($currency))
            ->where('direction', 'CREDIT')
            ->sum('amount_minor');

        return [
            'currency' => strtoupper($currency),
            'merchant_obligations' => [
                'confirmed_funded_principal_minor' => $confirmedPrincipalMinor,
                'protected_pending_release_minor' => $protectedPendingMinor,
                'dispute_frozen_minor' => $disputeHoldsMinor,
                'unrestricted_protected_minor' => max(0, $protectedPendingMinor - $disputeHoldsMinor),
                'released_principal_total_minor' => $releasedGrossMinor,
                'refunded_principal_total_minor' => $refundedMinor,
                'available_for_settlement_minor' => $availableForSettlementMinor,
                'eligible_agreements_count' => $asMerchant->where('settlement_status', SettlementStatus::Eligible)->count(),
            ],
            'buyer_positions' => [
                'active_protected_Minor' => (int) $asBuyer->sum('protected_balance_minor'),
                'disputed_minor' => (int) $asBuyer->sum('disputed_amount_minor'),
                'released_minor' => (int) $asBuyer->sum('released_amount_minor'),
                'refunded_minor' => (int) $asBuyer->sum('refunded_amount_minor'),
            ],
            'ledger_integrity' => [
                'trial_balance_debits_minor' => $totalLedgerDebits,
                'trial_balance_credits_minor' => $totalLedgerCredits,
                'is_balanced' => $totalLedgerDebits === $totalLedgerCredits,
            ],
            'regulatory_disclosure' => [
                'en' => 'Airlipa operational sub-ledger records commercial obligations and payables. It is not a bank account, e-money wallet, or deposit. Actual funds custody remains with the approved licensed financial partner.',
                'sw' => 'Leja ndogo ya Airlipa inarekodi wajibu wa kibiashara na malipo yanayodaiwa. Sio akaunti ya benki, pochi ya pesa mtandao, au amana. Utunzaji halisi wa fedha unabaki kwa mshirika wa kifedha aliyeidhinishwa.',
            ],
        ];
    }

    /**
     * Post balanced journal for merchant payout settlement execution (M14 / FR-016).
     */
    public function postMerchantPayoutSettlement(
        ProtectedPayment $payment,
        int $netPayoutMinor,
        string $providerReference,
        string $idempotencyKey,
        ?User $actor = null
    ): LedgerJournal {
        $lines = [
            [
                'account_code' => self::ACC_MERCHANT_PAYABLE_AVAILABLE,
                'direction' => 'DEBIT',
                'amount_minor' => $netPayoutMinor,
                'party_user_id' => $payment->merchant_id,
                'narration' => "Merchant settlement payout disbursement for {$payment->reference}",
            ],
            [
                'account_code' => self::ACC_PROVIDER_CLEARING,
                'direction' => 'CREDIT',
                'amount_minor' => $netPayoutMinor,
                'party_user_id' => $payment->merchant_id,
                'narration' => "Provider settlement outflow ({$providerReference}) for {$payment->reference}",
            ],
        ];

        return $this->postJournal(
            correlationId: $payment->correlation_id,
            eventType: 'MERCHANT_PAYOUT_SETTLEMENT',
            postingRuleCode: 'PR_MERCHANT_PAYOUT_V1',
            idempotencyKey: "jrn_payout_{$payment->protected_payment_id}_{$idempotencyKey}",
            currency: $payment->currency,
            description: "Merchant payout settlement for {$payment->reference}",
            lines: $lines,
            protectedPaymentId: $payment->id,
            providerReference: $providerReference,
            actorUserId: $actor?->id,
            metadata: [
                'net_payout_minor' => $netPayoutMinor,
                'provider_reference' => $providerReference,
            ]
        );
    }

    /**
     * Generate a complete Trial Balance across all operational sub-ledger accounts (M08 / M16).
     *
     * @return array<string, mixed>
     */
    public function getTrialBalance(string $currency = 'TZS'): array
    {
        $this->ensureChartOfAccountsExists($currency);

        $accounts = LedgerAccount::where('currency', strtoupper($currency))
            ->orderBy('account_code')
            ->get();

        $rows = [];
        $grandDebits = 0;
        $grandCredits = 0;

        foreach ($accounts as $account) {
            $debits = (int) LedgerPosting::where('ledger_account_id', $account->id)
                ->where('direction', 'DEBIT')
                ->sum('amount_minor');

            $credits = (int) LedgerPosting::where('ledger_account_id', $account->id)
                ->where('direction', 'CREDIT')
                ->sum('amount_minor');

            $netMinor = $account->normal_side === 'DEBIT'
                ? ($debits - $credits)
                : ($credits - $debits);

            $grandDebits += $debits;
            $grandCredits += $credits;

            $rows[] = [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'account_class' => $account->account_class,
                'normal_side' => $account->normal_side,
                'total_debits_minor' => $debits,
                'total_credits_minor' => $credits,
                'net_balance_minor' => $netMinor,
            ];
        }

        return [
            'currency' => strtoupper($currency),
            'generated_at' => now()->toIso8601String(),
            'accounts' => $rows,
            'totals' => [
                'total_debits_minor' => $grandDebits,
                'total_credits_minor' => $grandCredits,
                'zero_sum_balanced' => $grandDebits === $grandCredits,
                'journals_count' => LedgerJournal::where('currency', strtoupper($currency))->count(),
            ],
        ];
    }
}
