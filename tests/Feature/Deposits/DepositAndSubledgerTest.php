<?php

use App\Models\ContractSubledgerAccount;
use App\Models\EscrowContract;
use App\Models\LedgerJournal;
use App\Models\MilestoneSubledgerAccount;
use App\Models\OtpCode;
use App\Models\PartySubledgerAccount;
use App\Models\ReconciliationException;
use App\Models\SuspenseItem;
use App\Models\User;
use App\Services\QrDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function acceptEscrowContract(int $principalMinor, array $milestones = []): array
{
    $initiator = User::factory()->kycVerified()->create();
    $buyer = User::factory()->kycVerified()->create(['phone' => '+255712345678']);

    Sanctum::actingAs($initiator, ['*']);
    $createResponse = test()->postJson('/api/v1/contracts', array_merge([
        'initiator_role' => 'SELLER',
        'counterparty_name' => 'Neema Msuya',
        'counterparty_phone' => '+255712345678',
        'title' => 'Escrow deposit fixture',
        'description' => 'Goods held under pooled Selcom escrow custody.',
        'amount_minor' => $principalMinor,
    ], $milestones === [] ? [] : ['milestones' => $milestones]));

    $createResponse->assertStatus(201);
    $contractId = $createResponse->json('data.id');

    $token = test()->postJson("/api/v1/contracts/{$contractId}/share", ['channels' => ['LINK']])
        ->assertStatus(200)->json('data.shares.0.token');

    Sanctum::actingAs($buyer, ['*']);
    test()->postJson("/api/v1/contracts/share/{$token}/acceptance-otp")->assertStatus(200);

    OtpCode::where('user_id', $buyer->id)->where('purpose', 'contract_acceptance')->latest()->first()
        ->update(['code_hash' => Hash::make('262626')]);

    $acceptResponse = test()->postJson("/api/v1/contracts/share/{$token}/accept", ['otp_code' => '262626'])
        ->assertStatus(200);

    return [
        'contract_id' => $contractId,
        'payment_id' => $acceptResponse->json('data.protected_payment_id'),
        'initiator' => $initiator,
        'buyer' => $buyer,
    ];
}

function initiateSelcomDeposit(string $paymentId, User $buyer): array
{
    Sanctum::actingAs($buyer, ['*']);

    $payResponse = test()->postJson("/api/v1/protected-payments/{$paymentId}/pay", [
        'provider_code' => 'SELCOM_TZ',
        'payer_phone_or_account' => '0712345678',
        'idempotency_key' => 'idem-selcom-' . $paymentId,
    ])->assertStatus(202);

    return [
        'total_payable' => (int) $payResponse->json('data.amount_minor'),
        'instructions' => $payResponse->json('data.provider_instructions'),
    ];
}

test('verified Selcom deposit funds the contract and posts GL + subledger atomically', function () {
    $ctx = acceptEscrowContract(20000000);

    $deposit = initiateSelcomDeposit($ctx['payment_id'], $ctx['buyer']);

    expect($deposit['instructions']['metadata']['provider_code'])->toBe('SELCOM_TZ')
        ->and($deposit['instructions']['instructions_en'])->toContain('Selcom')
        ->and($deposit['instructions']['metadata']['tanqr_payload'])->not->toBeNull();

    Sanctum::actingAs($ctx['buyer'], ['*']);
    $callback = $this->postJson("/api/v1/protected-payments/{$ctx['payment_id']}/simulate-provider-callback", [
        'status' => 'SUCCESS',
        'event_id' => 'evt_selcom_fund_001',
    ])->assertStatus(200);

    expect($callback->json('data.processing_status'))->toBe('PROCESSED')
        ->and($callback->json('data.protected_payment_status'))->toBe('FUNDED_PROTECTED');

    // Contract subledger credited with the escrow principal only
    $subledger = ContractSubledgerAccount::where('escrow_contract_id', $ctx['contract_id'])->first();
    expect((int) $subledger->deposited_minor)->toBe(20000000)
        ->and((int) $subledger->available_minor)->toBe(20000000)
        ->and($subledger->conservationDifference())->toBe(0);

    // Party positions: buyer funded, seller entitled
    $buyerAccount = PartySubledgerAccount::where('escrow_contract_id', $ctx['contract_id'])->where('role', 'BUYER')->first();
    $sellerAccount = PartySubledgerAccount::where('escrow_contract_id', $ctx['contract_id'])->where('role', 'SELLER')->first();
    expect((int) $buyerAccount->funded_minor)->toBe(20000000)
        ->and((int) $sellerAccount->entitled_minor)->toBe(20000000);

    // Append-only entries tagged with the funding journal and reason code
    $this->assertDatabaseHas('subledger_entries', [
        'subledger_type' => 'CONTRACT',
        'subledger_id' => $subledger->id,
        'bucket' => 'deposited_minor',
        'reason_code' => 'DEPOSIT_CONFIRMED',
    ]);

    // GL journal balanced (debits == credits)
    $journal = LedgerJournal::where('event_type', 'FUNDING_CONFIRMED')->first();
    expect($journal)->not->toBeNull()
        ->and((int) $journal->total_debit_minor)->toBe((int) $journal->total_credit_minor);

    // Replayed webhook event is ignored and moves nothing
    $entriesBefore = \App\Models\SubledgerEntry::count();

    Sanctum::actingAs($ctx['buyer'], ['*']);
    $replay = $this->postJson("/api/v1/protected-payments/{$ctx['payment_id']}/simulate-provider-callback", [
        'status' => 'SUCCESS',
        'event_id' => 'evt_selcom_fund_001',
    ])->assertStatus(200);

    expect($replay->json('data.duplicate_ignored'))->toBeTrue()
        ->and(\App\Models\SubledgerEntry::count())->toBe($entriesBefore)
        ->and((int) ContractSubledgerAccount::where('escrow_contract_id', $ctx['contract_id'])->first()->deposited_minor)->toBe(20000000);
});

test('milestone deposits earmark the principal in held_milestones and fund each milestone sub-account', function () {
    $ctx = acceptEscrowContract(15000000, [
        ['title' => 'Mobilisation', 'amount_minor' => 6000000],
        ['title' => 'Delivery', 'amount_minor' => 9000000],
    ]);

    initiateSelcomDeposit($ctx['payment_id'], $ctx['buyer']);

    Sanctum::actingAs($ctx['buyer'], ['*']);
    $this->postJson("/api/v1/protected-payments/{$ctx['payment_id']}/simulate-provider-callback", ['status' => 'SUCCESS'])
        ->assertStatus(200);

    $subledger = ContractSubledgerAccount::where('escrow_contract_id', $ctx['contract_id'])->first();
    expect((int) $subledger->deposited_minor)->toBe(15000000)
        ->and((int) $subledger->available_minor)->toBe(0)
        ->and((int) $subledger->held_milestones_minor)->toBe(15000000)
        ->and($subledger->conservationDifference())->toBe(0);

    $milestoneAccounts = MilestoneSubledgerAccount::where('escrow_contract_id', $ctx['contract_id'])->get();
    expect($milestoneAccounts)->toHaveCount(2);
    expect($milestoneAccounts->sum('funded_minor'))->toBe(15000000);

    foreach ($milestoneAccounts as $account) {
        $sum = (int) $account->available_minor + (int) $account->frozen_minor + (int) $account->pending_release_minor
            + (int) $account->released_minor + (int) $account->refunded_minor;
        expect((int) $account->funded_minor)->toBe($sum);
    }
});

test('overpayment funds the payable total and parks the excess in Suspense with a HIGH exception', function () {
    $ctx = acceptEscrowContract(10000000);
    $deposit = initiateSelcomDeposit($ctx['payment_id'], $ctx['buyer']);

    Sanctum::actingAs($ctx['buyer'], ['*']);
    $this->postJson("/api/v1/protected-payments/{$ctx['payment_id']}/simulate-provider-callback", [
        'status' => 'SUCCESS',
        'amount_minor' => $deposit['total_payable'] + 250000,
    ])->assertStatus(200)
        ->assertJsonPath('data.protected_payment_status', 'FUNDED_PROTECTED');

    // Escrow position ignores the excess
    $subledger = ContractSubledgerAccount::where('escrow_contract_id', $ctx['contract_id'])->first();
    expect((int) $subledger->deposited_minor)->toBe(10000000);

    // Excess sits in suspense, unmatched, with a HIGH reconciliation exception
    $this->assertDatabaseHas('suspense_items', ['amount_minor' => 250000, 'status' => 'UNMATCHED']);
    $this->assertDatabaseHas('reconciliation_exceptions', ['category' => 'OVERPAYMENT', 'severity' => 'HIGH']);

    $suspenseJournal = LedgerJournal::where('event_type', 'DEPOSIT_OVERPAYMENT_SUSPENSE')->first();
    expect($suspenseJournal)->not->toBeNull()
        ->and((int) $suspenseJournal->total_debit_minor)->toBe(250000)
        ->and((int) $suspenseJournal->total_credit_minor)->toBe(250000);
});

test('part payment is held in suspense without funding the agreement or crediting subledgers', function () {
    $ctx = acceptEscrowContract(10000000);
    $deposit = initiateSelcomDeposit($ctx['payment_id'], $ctx['buyer']);

    Sanctum::actingAs($ctx['buyer'], ['*']);
    $callback = $this->postJson("/api/v1/protected-payments/{$ctx['payment_id']}/simulate-provider-callback", [
        'status' => 'SUCCESS',
        'amount_minor' => $deposit['total_payable'] - 500000,
    ])->assertStatus(200);

    expect($callback->json('data.processing_status'))->toBe('PARTIAL_HELD')
        ->and($callback->json('data.protected_payment_status'))->toBe('PAYMENT_PROCESSING');

    $this->assertDatabaseHas('suspense_items', ['amount_minor' => $deposit['total_payable'] - 500000, 'status' => 'UNMATCHED']);
    $this->assertDatabaseHas('reconciliation_exceptions', ['category' => 'PARTIAL_PAYMENT']);

    expect(LedgerJournal::where('event_type', 'FUNDING_CONFIRMED')->count())->toBe(0)
        ->and((int) ContractSubledgerAccount::where('escrow_contract_id', $ctx['contract_id'])->first()->deposited_minor)->toBe(0);

    // Buyer is notified bilingually through the outbox
    $this->assertDatabaseHas('outbox_events', ['event_type' => 'deposit.partial_received']);
});

test('deposit QR carries the exact amount, contract reference and a valid CRC16', function () {
    $ctx = acceptEscrowContract(8000000);

    Sanctum::actingAs($ctx['buyer'], ['*']);
    $qrResponse = $this->getJson("/api/v1/contracts/{$ctx['contract_id']}/deposit-qr")
        ->assertStatus(200);

    $payload = $qrResponse->json('data.emvco_payload');
    $reference = EscrowContract::findOrFail($ctx['contract_id'])->reference;

    expect($qrResponse->json('data.amount_minor'))->toBeGreaterThan(0)
        ->and($qrResponse->json('data.reference'))->toBe($reference)
        ->and($qrResponse->json('data.lipa_namba'))->toBe((string) config('protected_payment.selcom.lipa_namba'))
        ->and($payload)->toContain($reference);

    // CRC16-CCITT over everything before the checksum must match the tail
    $crcService = app(QrDepositService::class);
    $body = substr($payload, 0, -4);
    expect(substr($payload, -4))->toBe($crcService->crc16($body));

    // Amount TLV (tag 54) encodes the exact payable total
    $amountString = number_format($qrResponse->json('data.amount_minor') / 100, 2, '.', '');
    expect($payload)->toContain('54' . sprintf('%02d', strlen($amountString)) . $amountString);
});

test('deposit QR is unavailable before acceptance and strangers cannot fetch it', function () {
    $initiator = User::factory()->kycVerified()->create();
    $stranger = User::factory()->kycVerified()->create();

    Sanctum::actingAs($initiator, ['*']);
    $createResponse = $this->postJson('/api/v1/contracts', [
        'initiator_role' => 'SELLER',
        'counterparty_name' => 'Neema Msuya',
        'counterparty_phone' => '+255712345678',
        'title' => 'Draft without QR',
        'description' => 'Not accepted yet.',
        'amount_minor' => 5000000,
    ])->assertStatus(201);

    $this->getJson("/api/v1/contracts/{$createResponse->json('data.id')}/deposit-qr")->assertStatus(422);

    $ctx = acceptEscrowContract(5000000);

    Sanctum::actingAs($stranger, ['*']);
    $this->getJson("/api/v1/contracts/{$ctx['contract_id']}/deposit-qr")->assertStatus(403);
});

test('unauthenticated or tampered Selcom webhooks are rejected', function () {
    $ctx = acceptEscrowContract(6000000);
    initiateSelcomDeposit($ctx['payment_id'], $ctx['buyer']);

    $payload = json_encode([
        'event_id' => 'evt_tampered_001',
        'attempt_id' => \App\Models\PaymentAttempt::latest('id')->first()->attempt_id,
        'status' => 'SUCCESS',
        'amount_minor' => 6000000,
        'currency' => 'TZS',
    ], JSON_THROW_ON_ERROR);

    $response = $this->withHeaders([
        'X-Provider-Signature' => str_repeat('a', 64),
        'X-Provider-Timestamp' => (string) now()->timestamp,
        'X-Provider-Nonce' => 'nonce_tampered',
    ])->postJson('/api/v1/webhooks/providers/SELCOM_TZ', json_decode($payload, true));

    $response->assertStatus(403);

    // Stale timestamp outside the replay window is rejected even with a valid signature
    $capability = \App\Models\PaymentProviderCapability::where('provider_code', 'SELCOM_TZ')->first();
    $staleTimestamp = (string) now()->subMinutes(30)->timestamp;
    $signature = app(\App\Services\PaymentProviders\SelcomProvider::class)
        ->signWebhookPayload((string) $capability->webhook_secret, $payload, $staleTimestamp, 'nonce_stale');

    $this->withHeaders([
        'X-Provider-Signature' => $signature,
        'X-Provider-Timestamp' => $staleTimestamp,
        'X-Provider-Nonce' => 'nonce_stale',
    ])->postJson('/api/v1/webhooks/providers/SELCOM_TZ', json_decode($payload, true))
        ->assertStatus(403);
});

test('posting-rules catalogue exposes every registered deposit event', function () {
    $user = User::factory()->kycVerified()->create();

    Sanctum::actingAs($user, ['*']);
    $response = $this->getJson('/api/v1/ledger/posting-rules')->assertStatus(200);

    $codes = collect($response->json('data'))->pluck('event_code');

    // The catalogue is the full posting-rule book; this endpoint only has to
    // guarantee that the deposit events are published and not duplicated.
    expect($codes->all())->toContain('DEPOSIT_CONFIRMED', 'DEPOSIT_OVERPAYMENT_SUSPENSE', 'DEPOSIT_PARTIAL_HELD')
        ->and($codes->duplicates())->toBeEmpty();
});
