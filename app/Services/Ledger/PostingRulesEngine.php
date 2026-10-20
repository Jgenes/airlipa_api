<?php

namespace App\Services\Ledger;

use App\Models\ProtectedPayment;
use App\Services\LedgerService;
use DomainException;

/**
 * Posting-rules engine: every business event maps to a defined, versioned set
 * of journal lines (GL) plus a subledger plan. No ad-hoc postings anywhere in
 * the codebase - callers ask the engine for the lines of an event.
 *
 * Phase 2 covers the deposit-side events; later phases register release,
 * refund, dispute, fee-sweep and adjustment rules here as they are built.
 */
class PostingRulesEngine
{
    public const EVENT_DEPOSIT_CONFIRMED = 'DEPOSIT_CONFIRMED';
    public const EVENT_DEPOSIT_OVERPAYMENT_SUSPENSE = 'DEPOSIT_OVERPAYMENT_SUSPENSE';
    public const EVENT_DEPOSIT_PARTIAL_HELD = 'DEPOSIT_PARTIAL_HELD';
    public const EVENT_DISPUTE_OPENED = 'DISPUTE_OPENED';
    public const EVENT_DISPUTE_RESOLVED = 'DISPUTE_RESOLVED';
    public const EVENT_REFUND_REQUESTED = 'REFUND_REQUESTED';
    public const EVENT_REFUND_SUCCEEDED = 'REFUND_SUCCEEDED';
    public const EVENT_ADJUSTMENT = 'ADJUSTMENT';

    /**
     * Rule registry. Each rule declares its GL line template as a pure
     * function of the event context so lines are deterministic and testable.
     *
     * @return array<string, array{version: string, status: string, description: string, lines: callable(array<string, mixed>): array<int, array<string, mixed>>}>
     */
    public static function rules(): array
    {
        return [
            self::EVENT_DEPOSIT_CONFIRMED => [
                'version' => 'v1.0',
                'status' => 'LIVE',
                'description' => 'Verified provider funding received into the pooled Selcom escrow account: debit provider clearing, credit protected-funds liability plus deferred fee/VAT legs.',
                'lines' => static function (array $context): array {
                    /** @var ProtectedPayment $payment */
                    $payment = $context['payment'];

                    $lines = [
                        [
                            'account_code' => LedgerService::ACC_PROVIDER_CLEARING,
                            'direction' => 'DEBIT',
                            'amount_minor' => (int) $payment->total_payable_minor,
                            'party_user_id' => $payment->buyer_id,
                            'narration' => "Provider-confirmed funding received via {$payment->custody_provider_code} ({$context['provider_reference']})",
                        ],
                        [
                            'account_code' => LedgerService::ACC_PROTECTED_CUSTOMER_LIABILITY,
                            'direction' => 'CREDIT',
                            'amount_minor' => (int) $payment->principal_amount_minor,
                            'party_user_id' => $payment->buyer_id,
                            'narration' => "Protected principal held for agreement {$payment->reference}",
                        ],
                    ];

                    if ((int) $payment->buyer_fee_minor > 0) {
                        $lines[] = [
                            'account_code' => LedgerService::ACC_DEFERRED_PLATFORM_FEE,
                            'direction' => 'CREDIT',
                            'amount_minor' => (int) $payment->buyer_fee_minor,
                            'party_user_id' => $payment->buyer_id,
                            'narration' => "Deferred buyer protection service fee for {$payment->reference}",
                        ];
                    }

                    if ((int) $payment->buyer_tax_minor > 0) {
                        $lines[] = [
                            'account_code' => LedgerService::ACC_TAX_PAYABLE,
                            'direction' => 'CREDIT',
                            'amount_minor' => (int) $payment->buyer_tax_minor,
                            'party_user_id' => $payment->buyer_id,
                            'narration' => "VAT payable on buyer protection fee for {$payment->reference}",
                        ];
                    }

                    return $lines;
                },
            ],

            self::EVENT_DEPOSIT_OVERPAYMENT_SUSPENSE => [
                'version' => 'v1.0',
                'status' => 'LIVE',
                'description' => 'Customer paid more than the payable total: the excess stays in provider clearing but is owed back, so it is credited to Suspense/Unreconciled until refunded or matched.',
                'lines' => static function (array $context): array {
                    return [
                        [
                            'account_code' => LedgerService::ACC_PROVIDER_CLEARING,
                            'direction' => 'DEBIT',
                            'amount_minor' => (int) $context['excess_minor'],
                            'party_user_id' => $context['buyer_id'] ?? null,
                            'narration' => "Overpayment excess held in provider clearing for {$context['reference']} ({$context['provider_reference']})",
                        ],
                        [
                            'account_code' => LedgerService::ACC_SUSPENSE_UNRECONCILED,
                            'direction' => 'CREDIT',
                            'amount_minor' => (int) $context['excess_minor'],
                            'party_user_id' => $context['buyer_id'] ?? null,
                            'narration' => "Suspense obligation for overpayment on {$context['reference']}, pending refund or matcher approval",
                        ],
                    ];
                },
            ],

            self::EVENT_DEPOSIT_PARTIAL_HELD => [
                'version' => 'v1.0',
                'status' => 'LIVE',
                'description' => 'Customer paid less than the payable total: the part payment is held in Suspense/Unreconciled (no funding, no escrow credit) until topped up or returned.',
                'lines' => static function (array $context): array {
                    return [
                        [
                            'account_code' => LedgerService::ACC_PROVIDER_CLEARING,
                            'direction' => 'DEBIT',
                            'amount_minor' => (int) $context['received_minor'],
                            'party_user_id' => $context['buyer_id'] ?? null,
                            'narration' => "Part payment received into provider clearing for {$context['reference']} ({$context['provider_reference']})",
                        ],
                        [
                            'account_code' => LedgerService::ACC_SUSPENSE_UNRECONCILED,
                            'direction' => 'CREDIT',
                            'amount_minor' => (int) $context['received_minor'],
                            'party_user_id' => $context['buyer_id'] ?? null,
                            'narration' => "Suspense holding for part payment on {$context['reference']}, pending top-up or return",
                        ],
                    ];
                },
            ],
        ];
    }

    /**
     * Events whose GL journal is produced by a dedicated LedgerService poster
     * (they still belong in the registry so the catalogue is complete and
     * every booked event is traceable to a rule).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function delegatedRules(): array
    {
        return [
            self::EVENT_DISPUTE_OPENED => [
                'version' => 'v1.0',
                'poster' => 'LedgerService::postDisputeFreeze',
                'description' => 'Freeze disputed funds: debit Protected Customer Liability, credit Dispute Frozen Liability; subledger available -> frozen.',
            ],
            self::EVENT_DISPUTE_RESOLVED => [
                'version' => 'v1.0',
                'poster' => 'LedgerService::postProtectedRelease / postProtectedRefund (from dispute hold)',
                'description' => 'Execute the mediator split from the dispute-frozen liability; subledger frozen -> released / refunded.',
            ],
            self::EVENT_REFUND_REQUESTED => [
                'version' => 'v1.0',
                'poster' => 'subledger reservation only (no GL movement until the provider confirms)',
                'description' => 'Reserve the refundable amount: subledger available -> pending_refund, blocking double release.',
            ],
            self::EVENT_REFUND_SUCCEEDED => [
                'version' => 'v1.0',
                'poster' => 'LedgerService::postProtectedRefund',
                'description' => 'Provider-confirmed refund: debit customer liability (fee/VAT reversal legs), credit provider clearing; subledger pending_refund -> refunded.',
            ],
            self::EVENT_ADJUSTMENT => [
                'version' => 'v1.0',
                'poster' => 'LedgerService::postJournal (reversal + new entry only, maker-checker required)',
                'description' => 'Manual correction: never edits history; a reversal entry plus a corrected entry, approved by an independent checker.',
            ],
        ];
    }

    /**
     * Resolve the journal lines for an event. Throws on unknown events so no
     * caller can post outside the registry.
     *
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function linesFor(string $eventCode, array $context): array
    {
        $rules = self::rules();

        if (!isset($rules[$eventCode])) {
            throw new DomainException("No posting rule registered for event '{$eventCode}'.");
        }

        $lines = ($rules[$eventCode]['lines'])($context);

        foreach ($lines as $line) {
            if ((int) $line['amount_minor'] <= 0) {
                throw new DomainException("Posting rule '{$eventCode}' produced a non-positive line amount.");
            }
        }

        return $lines;
    }

    public function ruleVersion(string $eventCode): string
    {
        $rules = self::rules();

        return (string) ($rules[$eventCode]['version'] ?? 'unknown');
    }

    /**
     * Catalogue payload for the API/docs endpoint (finance visibility).
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogue(): array
    {
        $catalogue = [];

        foreach (self::rules() as $eventCode => $rule) {
            $catalogue[] = [
                'event_code' => $eventCode,
                'version' => $rule['version'],
                'status' => $rule['status'],
                'description' => $rule['description'],
                'line_template' => array_map(
                    static fn (array $line): array => [
                        'account_code' => $line['account_code'],
                        'direction' => $line['direction'],
                        'amount_basis' => $line['amount_basis'] ?? 'context',
                    ],
                    $this->lineTemplate($eventCode)
                ),
            ];
        }

        foreach (self::delegatedRules() as $eventCode => $rule) {
            $catalogue[] = [
                'event_code' => $eventCode,
                'version' => $rule['version'],
                'status' => 'LIVE',
                'description' => $rule['description'],
                'poster' => $rule['poster'],
            ];
        }

        return $catalogue;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function lineTemplate(string $eventCode): array
    {
        return match ($eventCode) {
            self::EVENT_DEPOSIT_CONFIRMED => [
                ['account_code' => LedgerService::ACC_PROVIDER_CLEARING, 'direction' => 'DEBIT', 'amount_basis' => 'total_payable_minor'],
                ['account_code' => LedgerService::ACC_PROTECTED_CUSTOMER_LIABILITY, 'direction' => 'CREDIT', 'amount_basis' => 'principal_amount_minor'],
                ['account_code' => LedgerService::ACC_DEFERRED_PLATFORM_FEE, 'direction' => 'CREDIT', 'amount_basis' => 'buyer_fee_minor (if > 0)'],
                ['account_code' => LedgerService::ACC_TAX_PAYABLE, 'direction' => 'CREDIT', 'amount_basis' => 'buyer_tax_minor (if > 0)'],
            ],
            self::EVENT_DEPOSIT_OVERPAYMENT_SUSPENSE => [
                ['account_code' => LedgerService::ACC_PROVIDER_CLEARING, 'direction' => 'DEBIT', 'amount_basis' => 'excess_minor'],
                ['account_code' => LedgerService::ACC_SUSPENSE_UNRECONCILED, 'direction' => 'CREDIT', 'amount_basis' => 'excess_minor'],
            ],
            self::EVENT_DEPOSIT_PARTIAL_HELD => [
                ['account_code' => LedgerService::ACC_PROVIDER_CLEARING, 'direction' => 'DEBIT', 'amount_basis' => 'received_minor'],
                ['account_code' => LedgerService::ACC_SUSPENSE_UNRECONCILED, 'direction' => 'CREDIT', 'amount_basis' => 'received_minor'],
            ],
            default => [],
        };
    }
}
