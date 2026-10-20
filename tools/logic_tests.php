<?php

/**
 * Framework-free logic tests.
 *
 * These exercise the pure parts of the money machinery (posting rules, QR
 * payload/crc, tier ranking) with no Laravel bootstrap, so they run anywhere a
 * PHP binary exists - including CI's fast job and a developer laptop before
 * `composer install`.
 *
 * Usage: php tools/logic_tests.php
 * Exit:  0 = all passed, 1 = failures
 */

declare(strict_types=1);

require __DIR__ . '/../app/Enums/KycTier.php';
require __DIR__ . '/../app/Enums/AmlRiskRating.php';
require __DIR__ . '/../app/Services/LedgerService.php';
require __DIR__ . '/../app/Services/Ledger/PostingRulesEngine.php';
require __DIR__ . '/../app/Services/QrDepositService.php';

use App\Enums\KycTier;
use App\Services\Ledger\PostingRulesEngine;
use App\Services\LedgerService;
use App\Services\QrDepositService;

$passed = 0;
$failed = 0;

function check(string $name, callable $assertion): void
{
    global $passed, $failed;

    try {
        $assertion();
        $passed++;
        echo "  PASS  {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "  FAIL  {$name}\n        {$exception->getMessage()}\n";
    }
}

function assertSame($expected, $actual, string $context = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            sprintf('%sexpected %s, got %s', $context === '' ? '' : $context . ': ', var_export($expected, true), var_export($actual, true))
        );
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Minimal stand-in for a ProtectedPayment row (the posting rules only read
 * public properties, so a plain object is enough to test them in isolation).
 */
function fakePayment(array $overrides = []): object
{
    return (object) array_merge([
        'reference' => 'PP-TEST-0001',
        'escrow_contract_id' => 1,
        'currency' => 'TZS',
        'total_payable_minor' => 10177000,
        'principal_amount_minor' => 10000000,
        'buyer_fee_minor' => 150000,
        'buyer_tax_minor' => 27000,
        'merchant_fee_minor' => 0,
        'merchant_tax_minor' => 0,
        'buyer_id' => 10,
        'merchant_id' => 20,
        'custody_provider_code' => 'SELCOM_TZ',
    ], $overrides);
}

echo "Money machinery (framework-free)\n";

check('CRC16-CCITT matches the published check value for "123456789"', function (): void {
    assertSame('29B1', (new QrDepositService())->crc16('123456789'));
});

check('deposit posting rule is balanced and uses the right control accounts', function (): void {
    $lines = (new PostingRulesEngine())->linesFor(
        PostingRulesEngine::EVENT_DEPOSIT_CONFIRMED,
        ['payment' => fakePayment(), 'provider_reference' => 'SLC-TEST-1']
    );

    $debits = 0;
    $credits = 0;
    $byCode = [];

    foreach ($lines as $line) {
        if ($line['direction'] === 'DEBIT') {
            $debits += (int) $line['amount_minor'];
        } else {
            $credits += (int) $line['amount_minor'];
        }
        $byCode[$line['account_code']] = (int) $line['amount_minor'];
    }

    assertSame($debits, $credits, 'debits must equal credits');
    assertSame(10177000, $byCode[LedgerService::ACC_PROVIDER_CLEARING], 'clearing debit');
    assertSame(10000000, $byCode[LedgerService::ACC_PROTECTED_CUSTOMER_LIABILITY], 'protected liability credit');
    assertSame(150000, $byCode[LedgerService::ACC_DEFERRED_PLATFORM_FEE], 'deferred fee credit');
    assertSame(27000, $byCode[LedgerService::ACC_TAX_PAYABLE], 'VAT payable credit');
});

check('total payable equals principal + buyer fee + buyer VAT for realistic quotes', function (): void {
    $payment = fakePayment();

    assertSame(
        (int) $payment->total_payable_minor,
        (int) $payment->principal_amount_minor + (int) $payment->buyer_fee_minor + (int) $payment->buyer_tax_minor,
        'funding journal only balances when total_payable is principal + fee + VAT'
    );
});

check('deposit rule omits zero-value fee legs', function (): void {
    $lines = (new PostingRulesEngine())->linesFor(
        PostingRulesEngine::EVENT_DEPOSIT_CONFIRMED,
        ['payment' => fakePayment(['total_payable_minor' => 9000000, 'buyer_fee_minor' => 0, 'buyer_tax_minor' => 0]), 'provider_reference' => 'x']
    );

    assertSame(2, count($lines), 'no fee/tax lines when they are zero');
});

check('overpayment suspense rule moves exactly the excess and balances', function (): void {
    $lines = (new PostingRulesEngine())->linesFor(
        PostingRulesEngine::EVENT_DEPOSIT_OVERPAYMENT_SUSPENSE,
        ['excess_minor' => 250000, 'buyer_id' => 10, 'reference' => 'PP-1', 'provider_reference' => 'SLC-1']
    );

    $debits = array_sum(array_map(fn (array $l): int => $l['direction'] === 'DEBIT' ? (int) $l['amount_minor'] : 0, $lines));
    $credits = array_sum(array_map(fn (array $l): int => $l['direction'] === 'CREDIT' ? (int) $l['amount_minor'] : 0, $lines));

    assertSame(250000, $debits);
    assertSame(250000, $credits);
    assertSame(LedgerService::ACC_SUSPENSE_UNRECONCILED, $lines[1]['account_code']);
});

check('part-payment rule never touches protected liability', function (): void {
    $lines = (new PostingRulesEngine())->linesFor(
        PostingRulesEngine::EVENT_DEPOSIT_PARTIAL_HELD,
        ['received_minor' => 700000, 'buyer_id' => 10, 'reference' => 'PP-2', 'provider_reference' => 'SLC-2']
    );

    $codes = array_column($lines, 'account_code');
    assertTrue(!in_array(LedgerService::ACC_PROTECTED_CUSTOMER_LIABILITY, $codes, true), 'part payments must not fund escrow');
    assertTrue(in_array(LedgerService::ACC_SUSPENSE_UNRECONCILED, $codes, true), 'part payments land in suspense');
});

check('unknown events are refused rather than posted silently', function (): void {
    try {
        (new PostingRulesEngine())->linesFor('NOT_A_REAL_EVENT', []);
    } catch (DomainException $exception) {
        assertTrue(str_contains($exception->getMessage(), 'No posting rule registered'), 'explicit error message expected');
        return;
    }

    throw new RuntimeException('unknown event was accepted');
});

check('posting-rule catalogue covers the deposit events', function (): void {
    $codes = array_column((new PostingRulesEngine())->catalogue(), 'event_code');

    foreach ([
        'DEPOSIT_CONFIRMED',
        'DEPOSIT_OVERPAYMENT_SUSPENSE',
        'DEPOSIT_PARTIAL_HELD',
        'DISPUTE_OPENED',
        'DISPUTE_RESOLVED',
        'REFUND_REQUESTED',
        'REFUND_SUCCEEDED',
        'ADJUSTMENT',
    ] as $required) {
        assertTrue(in_array($required, $codes, true), "catalogue missing {$required}");
    }
});

check('EMVCo deposit QR is structurally valid with a correct checksum', function (): void {
    $service = new QrDepositService();
    $payload = $service->buildEmvcoPayload(25000000, 'ESC-ABC123', '987654', 'AIRLIPA ESCROW POOL');

    $body = substr($payload, 0, -4);
    assertSame($service->crc16($body), substr($payload, -4), 'trailing CRC must cover the payload body');

    // Walk the full TLV stream (including the trailing CRC tag) and make sure
    // every declared length is real.
    $offset = 0;
    $tags = [];
    while ($offset < strlen($payload)) {
        $tag = substr($payload, $offset, 2);
        $length = (int) substr($payload, $offset + 2, 2);
        $value = substr($payload, $offset + 4, $length);
        assertSame($length, strlen($value), "TLV length mismatch for tag {$tag}");
        $tags[$tag] = $value;
        $offset += 4 + $length;
    }

    assertSame(strlen($payload), $offset, 'TLV stream must consume the whole payload');
    assertSame(substr($payload, -4), $tags['63'], 'tag 63 carries the CRC16');
    assertSame('01', $tags['00'], 'EMVCo version');
    assertSame('12', $tags['01'], 'dynamic point of initiation');
    assertSame('834', $tags['53'], 'currency TZS');
    assertSame('250000.00', $tags['54'], 'exact amount');
    assertSame('TZ', $tags['58'], 'country');
    assertTrue(str_contains($tags['26'], '987654'), 'merchant account reference');
    assertTrue(str_contains($tags['62'], 'ESC-ABC123'), 'contract reference travels in the QR');
});

check('KYC tier ranking is monotonic and reversible', function (): void {
    $ranks = array_map(fn (KycTier $tier): int => $tier->rank(), KycTier::cases());

    assertSame([0, 1, 2, 3], $ranks);

    foreach (KycTier::cases() as $tier) {
        assertSame($tier, KycTier::fromRank($tier->rank()));
    }

    assertSame(KycTier::Tier3Enhanced, KycTier::fromRank(99), 'over-rank clamps to the top tier');
});

echo "\n{$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
