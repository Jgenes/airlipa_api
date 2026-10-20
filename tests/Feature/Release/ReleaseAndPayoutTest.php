<?php

use App\Jobs\RetryFailedPayoutJob;
use App\Models\ContractSubledgerAccount;
use App\Models\MilestoneSubledgerAccount;
use App\Models\OtpCode;
use App\Models\PartySubledgerAccount;
use App\Models\PaymentProviderCapability;
use App\Models\PayoutInstruction;
use App\Models\User;
use App\Services\DeliveryConfirmationService;
use App\Services\PaymentProviders\PaymentProviderAdapterInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Funded + released escrow contract fixture for Phase 3 flows.
 *
 * @return array<string, mixed>
 */
function fundedContractFixture(int $principalMinor = 12000000, array $milestones = []): array
{
    $seller = User::factory()->kycVerified()->create([
        'first_name' => 'Neema',
        'last_name' => 'Msuya',
        'phone' => '+255712345678',
    ]);
    $buyer = User::factory()->kycVerified()->create(['phone' => '+255711111111']);

    Sanctum::actingAs($seller, ['*']);
    $contract = test()->postJson('/api/v1/contracts', array_merge([
        'initiator_role' => 'SELLER',
        'counterparty_name' => 'Buyer One',
        'counterparty_phone' => '+255711111111',
        'title' => 'Phase 3 escrow fixture',
        'description' => 'Release and payout fixture for the pooled Selcom escrow account.',
        'amount_minor' => $principalMinor,
    ], $milestones === [] ? [] : ['milestones' => $milestones]))->assertStatus(201)->json('data');

    $token = test()->postJson("/api/v1/contracts/{$contract['id']}/share", ['channels' => ['LINK']])
        ->assertStatus(200)->json('data.shares.0.token');

    Sanctum::actingAs($buyer, ['*']);
    test()->postJson("/api/v1/contracts/share/{$token}/acceptance-otp")->assertStatus(200);
    OtpCode::where('user_id', $buyer->id)->where('purpose', 'contract_acceptance')->latest()->first()
        ->update(['code_hash' => Hash::make('434343')]);

    $accepted = test()->postJson("/api/v1/contracts/share/{$token}/accept", ['otp_code' => '434343'])
        ->assertStatus(200)->json('data');

    $paymentId = $accepted['protected_payment_id'];

    test()->postJson("/api/v1/protected-payments/{$paymentId}/pay", [
        'provider_code' => 'SELCOM_TZ',
        'payer_phone_or_account' => '0711111111',
        'idempotency_key' => 'idem-phase3-' . $paymentId,
    ])->assertStatus(202);

    test()->postJson("/api/v1/protected-payments/{$paymentId}/simulate-provider-callback", ['status' => 'SUCCESS'])
        ->assertStatus(200);

    return [
        'seller' => $seller,
        'buyer' => $buyer,
        'contract_id' => $contract['id'],
        'payment_id' => $paymentId,
        'handover_otp' => $accepted['handover_otp'],
        'principal' => $principalMinor,
    ];
}

/**
 * @param array<string, mixed> $fixture
 * @return array<string, mixed>
 */
function releaseFunds(array $fixture, int $amountMinor, ?string $milestoneId = null): array
{
    Sanctum::actingAs($fixture['buyer'], ['*']);

    $payload = [
        'amount_minor' => $amountMinor,
        'handover_otp' => $fixture['handover_otp'],
        'idempotency_key' => 'rel-' . $amountMinor . '-' . ($milestoneId ?? 'full'),
    ];

    if ($milestoneId !== null) {
        $payload['milestone_id'] = $milestoneId;
    }

    return test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/release", $payload)
        ->assertStatus(200)
        ->json('data');
}

test('release reserves then confirms funds in the contract subledger and blocks double release', function () {
    $fixture = fundedContractFixture(12000000);

    releaseFunds($fixture, 5000000);

    $contractAccount = ContractSubledgerAccount::where('escrow_contract_id', $fixture['contract_id'])->first();
    expect((int) $contractAccount->available_minor)->toBe(7000000)
        ->and((int) $contractAccount->pending_release_minor)->toBe(0)
        ->and((int) $contractAccount->released_minor)->toBe(5000000)
        ->and($contractAccount->conservationDifference())->toBe(0);

    $sellerAccount = PartySubledgerAccount::where('escrow_contract_id', $fixture['contract_id'])->where('role', 'SELLER')->first();
    expect((int) $sellerAccount->entitled_minor)->toBe(7000000)
        ->and((int) $sellerAccount->held_minor)->toBe(5000000);

    // Over-release beyond the available bucket is rejected
    Sanctum::actingAs($fixture['buyer'], ['*']);
    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/release", [
        'amount_minor' => 9000000,
        'handover_otp' => $fixture['handover_otp'],
        'idempotency_key' => 'rel-over-001',
    ])->assertStatus(422);
});

test('milestone release moves only that milestone subledger and keeps conservation', function () {
    $fixture = fundedContractFixture(12000000, [
        'milestones' => [
            ['title' => 'Deposit', 'amount_minor' => 4000000],
            ['title' => 'Handover', 'amount_minor' => 8000000],
        ],
    ]);

    $milestone = MilestoneSubledgerAccount::where('escrow_contract_id', $fixture['contract_id'])
        ->orderBy('id')->first();
    $milestonePublicId = \App\Models\ProtectedPaymentMilestone::whereKey($milestone->milestone_id)->firstOrFail()->milestone_id;

    releaseFunds($fixture, 4000000, $milestonePublicId);

    $contractAccount = ContractSubledgerAccount::where('escrow_contract_id', $fixture['contract_id'])->first();
    expect((int) $contractAccount->held_milestones_minor)->toBe(8000000)
        ->and((int) $contractAccount->released_minor)->toBe(4000000)
        ->and($contractAccount->conservationDifference())->toBe(0);

    $releasedMilestone = MilestoneSubledgerAccount::find($milestone->id);
    expect((int) $releasedMilestone->released_minor)->toBe(4000000)
        ->and((int) $releasedMilestone->available_minor)->toBe(0);
});

test('payout refuses a destination name that does not match the KYC name', function () {
    $fixture = fundedContractFixture(12000000);
    releaseFunds($fixture, 12000000);

    Sanctum::actingAs($fixture['seller'], ['*']);

    test()->postJson('/api/v1/payout-accounts', [
        'provider_code' => 'SELCOM_TZ',
        'account_type' => 'MNO_WALLET',
        'account_name' => 'John Doe Third Party',
        'account_number' => '0754111222',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(201);

    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/settle-payout", [
        'idempotency_key' => 'payout-mismatch-001',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(422);
});

test('payout settles to a name-matched destination and moves the seller position to paid out', function () {
    $fixture = fundedContractFixture(12000000);
    releaseFunds($fixture, 12000000);

    Sanctum::actingAs($fixture['seller'], ['*']);

    test()->postJson('/api/v1/payout-accounts', [
        'provider_code' => 'SELCOM_TZ',
        'account_type' => 'MNO_WALLET',
        'account_name' => 'Neema Msuya',
        'account_number' => '0754111222',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(201);

    $settle = test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/settle-payout", [
        'idempotency_key' => 'payout-ok-001',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(200);

    expect($settle->json('data.payout_instruction.status'))->toBe('SETTLED');

    $instruction = PayoutInstruction::where('protected_payment_id', \App\Models\ProtectedPayment::where('protected_payment_id', $fixture['payment_id'])->first()->id)->first();
    expect((int) $instruction->net_payout_minor)->toBeGreaterThan(0)
        ->and((int) $instruction->net_payout_minor)->toBeLessThanOrEqual(12000000);

    $sellerAccount = PartySubledgerAccount::where('escrow_contract_id', $fixture['contract_id'])->where('role', 'SELLER')->first();
    expect((int) $sellerAccount->paid_out_minor)->toBe((int) $instruction->net_payout_minor);

    $this->assertDatabaseHas('ledger_journals', ['event_type' => 'MERCHANT_PAYOUT_SETTLEMENT']);
});

test('high value payouts demand step-up authentication', function () {
    config()->set('protected_payment.payout_step_up_threshold_minor', 1000);

    $fixture = fundedContractFixture(12000000);
    releaseFunds($fixture, 12000000);

    Sanctum::actingAs($fixture['seller'], ['*']);

    test()->postJson('/api/v1/payout-accounts', [
        'provider_code' => 'SELCOM_TZ',
        'account_type' => 'MNO_WALLET',
        'account_name' => 'Neema Msuya',
        'account_number' => '0754111222',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(201);

    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/settle-payout", [
        'idempotency_key' => 'payout-stepup-missing',
    ])->assertStatus(422);

    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/settle-payout", [
        'idempotency_key' => 'payout-stepup-ok',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(200);
});

test('large payouts require an independent maker-checker approval', function () {
    config()->set('protected_payment.dual_control_threshold_minor', 1000);

    $fixture = fundedContractFixture(12000000);
    releaseFunds($fixture, 12000000);

    Sanctum::actingAs($fixture['seller'], ['*']);

    test()->postJson('/api/v1/payout-accounts', [
        'provider_code' => 'SELCOM_TZ',
        'account_type' => 'MNO_WALLET',
        'account_name' => 'Neema Msuya',
        'account_number' => '0754111222',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(201);

    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/settle-payout", [
        'idempotency_key' => 'payout-no-approval',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(409);

    \App\Models\ApprovalRequest::create([
        'approval_code' => 'APR-TEST-0001',
        'action_type' => 'LARGE_PAYOUT',
        'target_type' => 'PROTECTED_PAYMENT',
        'target_identifier' => $fixture['payment_id'],
        'proposed_payload' => ['protected_payment_id' => $fixture['payment_id']],
        'reason' => 'Large payout requires independent review.',
        'status' => 'APPROVED',
        'maker_user_id' => User::factory()->admin()->create()->id,
    ]);

    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/settle-payout", [
        'idempotency_key' => 'payout-approved',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(200);
});

test('provider rejection schedules a backoff retry without posting a payout journal', function () {
    Queue::fake();

    $fixture = fundedContractFixture(12000000);
    releaseFunds($fixture, 12000000);

    Sanctum::actingAs($fixture['seller'], ['*']);

    test()->postJson('/api/v1/payout-accounts', [
        'provider_code' => 'SELCOM_TZ',
        'account_type' => 'MNO_WALLET',
        'account_name' => 'Neema Msuya',
        'account_number' => '0754111222',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(201);

    $this->mock(PaymentProviderAdapterInterface::class, function ($mock) {
        $mock->shouldReceive('executeRelease')->andReturn([
            'executed' => false,
            'provider_release_reference' => 'REL-SELCOM_TZ-FAILED',
            'raw_status' => 'SELCOM_INSUFFICIENT_POOL',
        ]);
    });

    $response = test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/settle-payout", [
        'idempotency_key' => 'payout-fail-001',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(200);

    expect($response->json('data.payout_instruction.status'))->toBe('PENDING');

    Queue::assertPushed(RetryFailedPayoutJob::class);

    $this->assertDatabaseMissing('ledger_journals', ['event_type' => 'MERCHANT_PAYOUT_SETTLEMENT']);

    // Escrow funds stay put while the retry is pending
    expect((int) ContractSubledgerAccount::where('escrow_contract_id', $fixture['contract_id'])->first()->released_minor)->toBe(12000000);
});

test('delivery confirmation QR is single-use and moves the agreement to awaiting acceptance', function () {
    $fixture = fundedContractFixture(9000000);

    Sanctum::actingAs($fixture['seller'], ['*']);
    $issued = test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/delivery-qr")
        ->assertStatus(201);

    $token = $issued->json('data.token');
    expect($token)->toBeString()->and($issued->json('data.qr_payload'))->toContain($token);

    Sanctum::actingAs($fixture['buyer'], ['*']);
    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/confirm-delivery", [
        'delivery_token' => $token,
    ])->assertStatus(200)
        ->assertJsonPath('data.status', 'AWAITING_ACCEPTANCE')
        ->assertJsonPath('data.fulfillment_status', 'DELIVERED_CONFIRMED');

    // Token cannot be replayed
    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/confirm-delivery", [
        'delivery_token' => $token,
    ])->assertStatus(404);
});

test('only the seller can issue a delivery code and only the buyer can consume it', function () {
    $fixture = fundedContractFixture(9000000);
    $stranger = User::factory()->kycVerified()->create();

    Sanctum::actingAs($fixture['buyer'], ['*']);
    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/delivery-qr")->assertStatus(403);

    Sanctum::actingAs($fixture['seller'], ['*']);
    $token = test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/delivery-qr")
        ->assertStatus(201)->json('data.token');

    Sanctum::actingAs($stranger, ['*']);
    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/confirm-delivery", [
        'delivery_token' => $token,
    ])->assertStatus(403);
});

test('expired delivery codes are refused', function () {
    $fixture = fundedContractFixture(9000000);

    Sanctum::actingAs($fixture['seller'], ['*']);
    $token = test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/delivery-qr")
        ->assertStatus(201)->json('data.token');

    \App\Models\DeliveryConfirmationToken::query()->update(['expires_at' => now()->subMinute()]);

    Sanctum::actingAs($fixture['buyer'], ['*']);
    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/confirm-delivery", [
        'delivery_token' => $token,
    ])->assertStatus(409);
});
