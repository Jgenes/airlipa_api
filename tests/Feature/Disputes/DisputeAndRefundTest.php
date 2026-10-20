<?php

use App\Models\ContractSubledgerAccount;
use App\Models\DisputeCase;
use App\Models\DisputeHold;
use App\Models\DisputeMessage;
use App\Models\OtpCode;
use App\Models\PartySubledgerAccount;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Funded escrow fixture (Phase 4 disputes + refunds).
 *
 * @return array<string, mixed>
 */
function disputedContractFixture(int $principalMinor = 10000000): array
{
    $seller = User::factory()->kycVerified()->create(['phone' => '+255712345678', 'first_name' => 'Neema', 'last_name' => 'Msuya']);
    $buyer = User::factory()->kycVerified()->create(['phone' => '+255711111111']);

    Sanctum::actingAs($seller, ['*']);
    $contract = test()->postJson('/api/v1/contracts', [
        'initiator_role' => 'SELLER',
        'counterparty_name' => 'Buyer One',
        'counterparty_phone' => '+255711111111',
        'title' => 'Phase 4 dispute fixture',
        'description' => 'Goods held in the pooled Selcom escrow account.',
        'amount_minor' => $principalMinor,
    ])->assertStatus(201)->json('data');

    $token = test()->postJson("/api/v1/contracts/{$contract['id']}/share", ['channels' => ['LINK']])
        ->assertStatus(200)->json('data.shares.0.token');

    Sanctum::actingAs($buyer, ['*']);
    test()->postJson("/api/v1/contracts/share/{$token}/acceptance-otp")->assertStatus(200);
    OtpCode::where('user_id', $buyer->id)->where('purpose', 'contract_acceptance')->latest()->first()
        ->update(['code_hash' => Hash::make('515151')]);

    $accepted = test()->postJson("/api/v1/contracts/share/{$token}/accept", ['otp_code' => '515151'])
        ->assertStatus(200)->json('data');

    test()->postJson("/api/v1/protected-payments/{$accepted['protected_payment_id']}/pay", [
        'provider_code' => 'SELCOM_TZ',
        'payer_phone_or_account' => '0711111111',
        'idempotency_key' => 'idem-p4-' . $accepted['protected_payment_id'],
    ])->assertStatus(202);

    test()->postJson("/api/v1/protected-payments/{$accepted['protected_payment_id']}/simulate-provider-callback", [
        'status' => 'SUCCESS',
    ])->assertStatus(200);

    return [
        'seller' => $seller,
        'buyer' => $buyer,
        'contract_id' => $contract['id'],
        'payment_id' => $accepted['protected_payment_id'],
        'handover_otp' => $accepted['handover_otp'],
        'principal' => $principalMinor,
    ];
}

test('opening a dispute freezes the escrow subledger and blocks payout', function () {
    $fixture = disputedContractFixture(10000000);

    Sanctum::actingAs($fixture['buyer'], ['*']);
    $response = test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/disputes", [
        'case_type' => 'NON_DELIVERY',
        'disputed_amount_minor' => 4000000,
        'reason' => 'The seller never delivered the goods.',
    ])->assertStatus(201);

    $account = ContractSubledgerAccount::where('escrow_contract_id', $fixture['contract_id'])->first();
    expect((int) $account->available_minor)->toBe(6000000)
        ->and((int) $account->frozen_minor)->toBe(4000000)
        ->and($account->conservationDifference())->toBe(0)
        ->and(DisputeHold::where('escrow_contract_id', $fixture['contract_id'])->where('status', 'ACTIVE')->count())->toBe(1);

    $caseId = $response->json('data.dispute.case_id');
    $this->assertDatabaseHas('dispute_messages', ['dispute_case_id' => DisputeCase::where('case_id', $caseId)->first()->id, 'message_type' => 'TIMELINE']);

    // Seller cannot settle a payout while frozen
    Sanctum::actingAs($fixture['seller'], ['*']);
    test()->postJson('/api/v1/payout-accounts', [
        'provider_code' => 'SELCOM_TZ',
        'account_type' => 'MNO_WALLET',
        'account_name' => 'Neema Msuya',
        'account_number' => '0754111222',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(201);

    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/settle-payout", [
        'idempotency_key' => 'payout-while-frozen',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(409);
});

test('parties can chat on the case and strangers cannot', function () {
    $fixture = disputedContractFixture();
    $stranger = User::factory()->kycVerified()->create();

    Sanctum::actingAs($fixture['buyer'], ['*']);
    $caseId = test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/disputes", [
        'case_type' => 'MATERIALLY_DIFFERENT',
        'reason' => 'Item does not match the description.',
    ])->assertStatus(201)->json('data.dispute.case_id');

    test()->postJson("/api/v1/disputes/{$caseId}/messages", [
        'body' => 'I received a different model.',
    ])->assertStatus(201);

    Sanctum::actingAs($fixture['seller'], ['*']);
    test()->postJson("/api/v1/disputes/{$caseId}/messages", [
        'body' => 'Please send photos and I will replace it.',
    ])->assertStatus(201);

    $timeline = test()->getJson("/api/v1/disputes/{$caseId}/timeline")->assertStatus(200);
    expect(collect($timeline->json('data'))->pluck('body'))
        ->toContain('I received a different model.')
        ->toContain('Please send photos and I will replace it.');

    Sanctum::actingAs($stranger, ['*']);
    test()->getJson("/api/v1/disputes/{$caseId}/timeline")->assertStatus(403);
    test()->postJson("/api/v1/disputes/{$caseId}/messages", ['body' => 'Intruding'])->assertStatus(403);
});

test('mediator resolution settles the frozen hold into the seller and buyer positions', function () {
    $fixture = disputedContractFixture(10000000);
    $admin = User::factory()->admin()->create();

    Sanctum::actingAs($fixture['buyer'], ['*']);
    $caseId = test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/disputes", [
        'case_type' => 'PARTIAL_DELIVERY',
        'disputed_amount_minor' => 10000000,
        'reason' => 'Only half the order arrived.',
    ])->assertStatus(201)->json('data.dispute.case_id');

    Sanctum::actingAs($admin, ['*']);
    test()->postJson("/api/v1/admin/disputes/{$caseId}/resolve", [
        'decision_outcome' => 'PARTIAL_SPLIT',
        'release_to_merchant_minor' => 4000000,
        'refund_to_buyer_minor' => 6000000,
        'decision_reason_code' => 'PROVEN_PARTIAL_SUPPLY',
        'decision_notes' => 'Delivery note shows 50% of the order.',
        'idempotency_key' => 'resolve-001',
    ])->assertStatus(200);

    $account = ContractSubledgerAccount::where('escrow_contract_id', $fixture['contract_id'])->first();
    expect((int) $account->frozen_minor)->toBe(0)
        ->and((int) $account->released_minor)->toBe(4000000)
        ->and((int) $account->refunded_minor)->toBe(6000000)
        ->and($account->conservationDifference())->toBe(0);

    expect(DisputeHold::where('escrow_contract_id', $fixture['contract_id'])->first()->status)->toBe('SPLIT');
    $this->assertDatabaseHas('dispute_messages', ['dispute_case_id' => DisputeCase::where('case_id', $caseId)->first()->id, 'message_type' => 'DECISION']);
});

test('a case may be appealed only once and only inside the window', function () {
    $fixture = disputedContractFixture(10000000);
    $admin = User::factory()->admin()->create();

    Sanctum::actingAs($fixture['buyer'], ['*']);
    $caseId = test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/disputes", [
        'case_type' => 'NON_DELIVERY',
        'reason' => 'Nothing was delivered.',
    ])->assertStatus(201)->json('data.dispute.case_id');

    Sanctum::actingAs($admin, ['*']);
    test()->postJson("/api/v1/admin/disputes/{$caseId}/resolve", [
        'decision_outcome' => 'FULL_REFUND_BUYER',
        'decision_reason_code' => 'NON_DELIVERY_PROVEN',
        'decision_notes' => 'Seller produced no delivery evidence.',
        'idempotency_key' => 'resolve-002',
    ])->assertStatus(200);

    Sanctum::actingAs($fixture['seller'], ['*']);
    test()->postJson("/api/v1/disputes/{$caseId}/appeal", [
        'reason' => 'I have a signed delivery note that was not considered.',
    ])->assertStatus(201)
        ->assertJsonPath('data.status', 'APPEALED');

    // Second appeal is refused
    test()->postJson("/api/v1/disputes/{$caseId}/appeal", [
        'reason' => 'Again please.',
    ])->assertStatus(409);

    // Appeal blocks further financial decisions
    Sanctum::actingAs($admin, ['*']);
    test()->postJson("/api/v1/admin/disputes/{$caseId}/resolve", [
        'decision_outcome' => 'FULL_REFUND_BUYER',
        'decision_reason_code' => 'RETRY',
        'decision_notes' => 'Should be blocked while under appeal.',
        'idempotency_key' => 'resolve-003',
    ])->assertStatus(409);

    // Independent reviewer closes it
    $reviewer = User::factory()->admin()->create();
    Sanctum::actingAs($reviewer, ['*']);
    test()->postJson("/api/v1/admin/disputes/{$caseId}/appeal-decision", [
        'decision' => 'UPHOLD',
        'notes' => 'Delivery note predates the contract and is not credible.',
    ])->assertStatus(200)
        ->assertJsonPath('data.status', 'CLOSED');
});

test('expired appeal windows are refused', function () {
    $fixture = disputedContractFixture(10000000);
    $admin = User::factory()->admin()->create();

    Sanctum::actingAs($fixture['buyer'], ['*']);
    $caseId = test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/disputes", [
        'case_type' => 'NON_DELIVERY',
        'reason' => 'Nothing arrived.',
    ])->assertStatus(201)->json('data.dispute.case_id');

    Sanctum::actingAs($admin, ['*']);
    test()->postJson("/api/v1/admin/disputes/{$caseId}/resolve", [
        'decision_outcome' => 'FULL_REFUND_BUYER',
        'decision_reason_code' => 'NON_DELIVERY_PROVEN',
        'decision_notes' => 'No evidence of delivery.',
        'idempotency_key' => 'resolve-004',
    ])->assertStatus(200);

    DisputeCase::where('case_id', $caseId)->update(['appeal_deadline_at' => now()->subDay()]);

    Sanctum::actingAs($fixture['seller'], ['*']);
    test()->postJson("/api/v1/disputes/{$caseId}/appeal", ['reason' => 'Too late but trying.'])->assertStatus(422);
});

test('full refund moves the contract subledger through pending_refund to refunded', function () {
    $fixture = disputedContractFixture(10000000);

    Sanctum::actingAs($fixture['seller'], ['*']);
    $response = test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/refunds", [
        'reason' => 'Buyer cancelled before dispatch.',
        'step_up_password' => 'Password#123456',
        'idempotency_key' => 'refund-full-001',
    ])->assertStatus(200);

    expect($response->json('data.refund.scope'))->toBe('FULL')
        ->and($response->json('data.refund.status'))->toBe('SUCCEEDED');

    $account = ContractSubledgerAccount::where('escrow_contract_id', $fixture['contract_id'])->first();
    expect((int) $account->refunded_minor)->toBe(10000000)
        ->and((int) $account->available_minor)->toBe(0)
        ->and((int) $account->pending_refund_minor)->toBe(0)
        ->and($account->conservationDifference())->toBe(0);

    $buyerAccount = PartySubledgerAccount::where('escrow_contract_id', $fixture['contract_id'])->where('role', 'BUYER')->first();
    expect((int) $buyerAccount->refunded_minor)->toBe(10000000);

    $this->assertDatabaseHas('refund_requests', ['protected_payment_id' => \App\Models\ProtectedPayment::where('protected_payment_id', $fixture['payment_id'])->first()->id, 'status' => 'SUCCEEDED']);

    // History endpoint reflects the refund
    $history = test()->getJson("/api/v1/protected-payments/{$fixture['payment_id']}/refunds")->assertStatus(200);
    expect($history->json('data.0.refund_reference'))->toBe($response->json('data.refund.refund_reference'));
});

test('partial refunds are scoped correctly and over-refunds are rejected', function () {
    $fixture = disputedContractFixture(10000000);

    Sanctum::actingAs($fixture['seller'], ['*']);
    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/refunds", [
        'amount_minor' => 3000000,
        'reason' => 'Partial goodwill refund.',
        'step_up_password' => 'Password#123456',
        'idempotency_key' => 'refund-partial-001',
    ])->assertStatus(200)->assertJsonPath('data.refund.scope', 'PARTIAL');

    $account = ContractSubledgerAccount::where('escrow_contract_id', $fixture['contract_id'])->first();
    expect((int) $account->refunded_minor)->toBe(3000000)
        ->and((int) $account->available_minor)->toBe(7000000);

    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/refunds", [
        'amount_minor' => 9000000,
        'reason' => 'Too much.',
        'step_up_password' => 'Password#123456',
        'idempotency_key' => 'refund-too-big',
    ])->assertStatus(422);

    expect((int) ContractSubledgerAccount::where('escrow_contract_id', $fixture['contract_id'])->first()->refunded_minor)->toBe(3000000);
});

test('the PDF case file downloads with a tamper-evident digest', function () {
    $fixture = disputedContractFixture();

    Sanctum::actingAs($fixture['buyer'], ['*']);
    $caseId = test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/disputes", [
        'case_type' => 'NON_DELIVERY',
        'reason' => 'No delivery after two weeks.',
    ])->assertStatus(201)->json('data.dispute.case_id');

    $response = test()->getJson("/api/v1/disputes/{$caseId}/case-file")->assertStatus(200);

    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->headers->get('X-Case-File-SHA256'))->toHaveLength(64);

    expect(substr($response->streamedContent(), 0, 8))->toBe('%PDF-1.4');

    $dispute = DisputeCase::where('case_id', $caseId)->first();
    expect($dispute->case_file_path)->not->toBeNull();
});
