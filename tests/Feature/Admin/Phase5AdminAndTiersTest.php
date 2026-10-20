<?php

use App\Models\ContractSubledgerAccount;
use App\Models\EscrowRating;
use App\Models\OtpCode;
use App\Models\PartnerSettlementStatement;
use App\Models\PayoutFreezeState;
use App\Models\ReminderDispatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * End-to-end fixture: contract created, accepted, funded.
 *
 * @return array<string, mixed>
 */
function phase5Fixture(int $principalMinor = 8000000): array
{
    // Phones come from a per-call sequence: the escrow flow matches a
    // counterparty by phone, so reusing a hard-coded number makes the second
    // fixture in a process collide on users.phone.
    $GLOBALS['phase5_fixture_sequence'] = ($GLOBALS['phase5_fixture_sequence'] ?? 0) + 1;
    $sequence = $GLOBALS['phase5_fixture_sequence'];
    $sellerPhone = '+2557' . str_pad((string) (1000000 + $sequence), 8, '0', STR_PAD_LEFT);
    $buyerPhone = '+2557' . str_pad((string) (2000000 + $sequence), 8, '0', STR_PAD_LEFT);

    $seller = User::factory()->kycVerified()->create(['phone' => $sellerPhone, 'first_name' => 'Neema', 'last_name' => 'Msuya']);
    $buyer = User::factory()->kycVerified()->create(['phone' => $buyerPhone, 'first_name' => 'Juma', 'last_name' => 'Ally']);

    Sanctum::actingAs($seller, ['*']);
    $contract = test()->postJson('/api/v1/contracts', [
        'initiator_role' => 'SELLER',
        'counterparty_name' => 'Juma Ally',
        'counterparty_phone' => $buyerPhone,
        'title' => 'Phase 5 fixture',
        'description' => 'Goods under pooled Selcom escrow custody.',
        'amount_minor' => $principalMinor,
    ])->assertStatus(201)->json('data');

    $token = test()->postJson("/api/v1/contracts/{$contract['id']}/share", ['channels' => ['LINK']])
        ->assertStatus(200)->json('data.shares.0.token');

    Sanctum::actingAs($buyer, ['*']);
    test()->postJson("/api/v1/contracts/share/{$token}/acceptance-otp")->assertStatus(200);
    OtpCode::where('user_id', $buyer->id)->where('purpose', 'contract_acceptance')->latest()->first()
        ->update(['code_hash' => Hash::make('616161')]);

    $accepted = test()->postJson("/api/v1/contracts/share/{$token}/accept", ['otp_code' => '616161'])
        ->assertStatus(200)->json('data');

    test()->postJson("/api/v1/protected-payments/{$accepted['protected_payment_id']}/pay", [
        'provider_code' => 'SELCOM_TZ',
        'payer_phone_or_account' => '0711111111',
        'idempotency_key' => 'idem-p5-' . $accepted['protected_payment_id'],
    ])->assertStatus(202);

    test()->postJson("/api/v1/protected-payments/{$accepted['protected_payment_id']}/simulate-provider-callback", ['status' => 'SUCCESS'])
        ->assertStatus(200);

    return [
        'seller' => $seller,
        'buyer' => $buyer,
        'contract' => $contract,
        'payment_id' => $accepted['protected_payment_id'],
        'handover_otp' => $accepted['handover_otp'],
        'principal' => $principalMinor,
    ];
}

test('kyc tier summary exposes limits and next tier for an unverified user', function () {
    $unverified = User::factory()->create(['phone' => '+255799000111']);

    Sanctum::actingAs($unverified, ['*']);
    $response = test()->getJson('/api/v1/me/trading-limits')->assertStatus(200);

    expect($response->json('data.tier.tier'))->toBe('TIER_0')
        ->and($response->json('data.tier.single_transaction_max_minor'))->toBe(0)
        ->and($response->json('data.can_trade'))->toBeFalse()
        ->and($response->json('data.usage.remaining_monthly_capacity_minor'))->toBe(0);
});

test('tier limits block oversized escrow contracts', function () {
    config()->set('aml_tiers.tiers.TIER_1.single_transaction_max_minor', 1000000);

    $seller = User::factory()->kycVerified()->create();

    Sanctum::actingAs($seller, ['*']);
    test()->postJson('/api/v1/contracts', [
        'initiator_role' => 'SELLER',
        'counterparty_name' => 'Juma Ally',
        'counterparty_phone' => '+255711111111',
        'title' => 'Too large for tier 1',
        'description' => 'Should be blocked by AML tier limits.',
        'amount_minor' => 5000000,
    ])->assertStatus(422);
});

test('verified user sees tier 1 limits and can trade within them', function () {
    $seller = User::factory()->kycVerified()->create();

    Sanctum::actingAs($seller, ['*']);
    $limits = test()->getJson('/api/v1/me/trading-limits')->assertStatus(200);

    expect($limits->json('data.tier.tier'))->toBe('TIER_1')
        ->and($limits->json('data.can_trade'))->toBeTrue()
        ->and($limits->json('data.next_tier_hint.tier'))->toBe('TIER_2');

    test()->postJson('/api/v1/contracts', [
        'initiator_role' => 'SELLER',
        'counterparty_name' => 'Juma Ally',
        'counterparty_phone' => '+255711111111',
        'title' => 'Within tier 1',
        'description' => 'Allowed under tier 1 limits.',
        'amount_minor' => 500000,
    ])->assertStatus(201);
});

test('admins can drill down from account to journal to contract subledger', function () {
    $fixture = phase5Fixture(8000000);
    $admin = User::factory()->admin()->create();

    Sanctum::actingAs($admin, ['*']);

    $accounts = test()->getJson('/api/v1/admin/ledger/accounts')->assertStatus(200);
    $liability = collect($accounts->json('data'))->firstWhere('account_code', '2100-PROTECTED-CUSTOMER-LIABILITY');

    expect($liability)->not->toBeNull()
        ->and($liability['net_balance_minor'])->toBeGreaterThan(0);

    $detail = test()->getJson('/api/v1/admin/ledger/accounts/2100-PROTECTED-CUSTOMER-LIABILITY')->assertStatus(200);
    expect($detail->json('data.postings'))->not->toBeEmpty();

    $journalId = $detail->json('data.postings.0.journal_id');
    $journal = test()->getJson("/api/v1/admin/ledger/journals/{$journalId}")->assertStatus(200);

    expect($journal->json('data.balanced'))->toBeTrue()
        ->and($journal->json('data.total_debit_minor'))->toBe($journal->json('data.total_credit_minor'));

    $contractId = $fixture['contract']['contract_id'];
    $contract = test()->getJson("/api/v1/admin/ledger/contracts/{$contractId}")->assertStatus(200);

    expect($contract->json('data.subledger.deposited_minor'))->toBe(8000000)
        ->and($contract->json('data.subledger.conservation_difference_minor'))->toBe(0);
});

test('non-admins cannot reach the ledger explorer', function () {
    $user = User::factory()->kycVerified()->create();

    Sanctum::actingAs($user, ['*']);
    test()->getJson('/api/v1/admin/ledger/accounts')->assertStatus(403);
    test()->getJson('/api/v1/admin/reconciliation/dashboard')->assertStatus(403);
});

test('reconciliation dashboard reports a healthy invariant after a funded deposit', function () {
    phase5Fixture(8000000);
    $admin = User::factory()->admin()->create();

    Sanctum::actingAs($admin, ['*']);
    $dashboard = test()->getJson('/api/v1/admin/reconciliation/dashboard')->assertStatus(200);

    expect($dashboard->json('data.invariant.subledger.held_minor'))->toBeGreaterThanOrEqual(8000000)
        ->and($dashboard->json('data.portfolio.violations'))->toBe([])
        ->and($dashboard->json('data.payout_freeze.is_active'))->toBeFalse()
        ->and($dashboard->json('data.trial_balance_balanced'))->toBeTrue();
});

test('invariant check freezes payouts when the partner statement disagrees', function () {
    phase5Fixture(8000000);
    $admin = User::factory()->admin()->create();

    Sanctum::actingAs($admin, ['*']);

    // Provider reports a materially lower pooled-account balance than the ledger holds.
    PartnerSettlementStatement::create([
        'statement_reference' => 'SELCOM-STMT-TEST-001',
        'provider_code' => 'SELCOM_TZ',
        'settlement_account_reference' => 'SELCOM-POOLED-ESCROW-001',
        'currency' => 'TZS',
        'statement_date' => now()->toDateString(),
        'opening_balance_minor' => 0,
        'closing_balance_minor' => 100000,
        'total_inflow_minor' => 100000,
        'total_outflow_minor' => 0,
        'line_count' => 1,
        'source' => 'MANUAL',
        'ingested_by_user_id' => $admin->id,
    ]);

    $check = test()->postJson('/api/v1/admin/reconciliation/invariant-check')->assertStatus(409);

    expect($check->json('data.healthy'))->toBeFalse()
        ->and($check->json('data.freeze_active'))->toBeTrue()
        ->and(PayoutFreezeState::where('scope', 'GLOBAL')->first()->is_active)->toBeTrue();

    $this->assertDatabaseHas('reconciliation_exceptions', [
        'category' => 'ESCROW_INVARIANT_BREACH',
        'severity' => 'CRITICAL',
    ]);

    // Payouts are now hard-blocked even with a valid destination + step-up
    $fixture = phase5Fixture(4000000);
    Sanctum::actingAs($fixture['buyer'], ['*']);
    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/release", [
        'amount_minor' => 4000000,
        'handover_otp' => $fixture['handover_otp'],
        'idempotency_key' => 'rel-freeze-001',
    ])->assertStatus(200);

    Sanctum::actingAs($fixture['seller'], ['*']);
    test()->postJson('/api/v1/payout-accounts', [
        'provider_code' => 'SELCOM_TZ',
        'account_type' => 'MNO_WALLET',
        'account_name' => 'Neema Msuya',
        'account_number' => '0754111222',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(201);

    test()->postJson("/api/v1/protected-payments/{$fixture['payment_id']}/settle-payout", [
        'idempotency_key' => 'payout-blocked-by-freeze',
        'step_up_password' => 'Password#123456',
    ])->assertStatus(409);

    // Finance clears the freeze with an audited reason
    Sanctum::actingAs($admin, ['*']);
    test()->deleteJson('/api/v1/admin/payout-freeze', [
        'reason' => 'Provider statement corrected after investigating the settlement file.',
    ])->assertStatus(200);

    expect(PayoutFreezeState::where('scope', 'GLOBAL')->first()->is_active)->toBeFalse();
});

test('escrow register and settlements export as CSV', function () {
    phase5Fixture(6000000);
    $admin = User::factory()->admin()->create();

    Sanctum::actingAs($admin, ['*']);

    $register = $this->get('/api/v1/admin/reports/escrow-register.csv')->assertStatus(200);
    expect($register->headers->get('Content-Type'))->toContain('text/csv');
    $csv = $register->getContent();
    expect($csv)->toContain('reference')
        ->and($csv)->toContain('amount_minor');

    $settlements = $this->get('/api/v1/admin/reports/settlements.csv')->assertStatus(200);
    expect($settlements->headers->get('Content-Type'))->toContain('text/csv');
});

test('buyers and sellers can each rate a contract exactly once', function () {
    $fixture = phase5Fixture(5000000);

    Sanctum::actingAs($fixture['buyer'], ['*']);
    test()->postJson("/api/v1/contracts/{$fixture['contract']['id']}/rating", [
        'score' => 5,
        'comment' => 'Delivered exactly as agreed.',
        'dimensions' => ['communication' => 5, 'timeliness' => 4],
    ])->assertStatus(201);

    // Same party cannot rate twice
    test()->postJson("/api/v1/contracts/{$fixture['contract']['id']}/rating", [
        'score' => 1,
        'comment' => 'Changed my mind.',
    ])->assertStatus(409);

    // Other party may rate the same contract
    $seller = $fixture['seller'];
    $contract = \App\Models\EscrowContract::findOrFail($fixture['contract']['id']);
    if (!$contract->counterparty_user_id) {
        $contract->forceFill(['counterparty_user_id' => $fixture['buyer']->id])->save();
    }

    Sanctum::actingAs($seller, ['*']);
    test()->postJson("/api/v1/contracts/{$fixture['contract']['id']}/rating", ['score' => 4])
        ->assertStatus(201);

    expect(EscrowRating::where('escrow_contract_id', $fixture['contract']['id'])->count())->toBe(2);

    $reputation = $this->getJson('/api/v1/users/' . $fixture['seller']->id . '/reputation')->assertStatus(200);
    expect($reputation->json('data.rating_count'))->toBe(1)
        ->and($reputation->json('data.average_score'))->toBe(5); // JSON drops the .0 of round(5.0, 2)
});

test('a stranger cannot rate a contract they are not party to', function () {
    $fixture = phase5Fixture(5000000);
    $stranger = User::factory()->kycVerified()->create();

    Sanctum::actingAs($stranger, ['*']);
    test()->postJson("/api/v1/contracts/{$fixture['contract']['id']}/rating", ['score' => 5])
        ->assertStatus(403);
});

test('analytics overview and timeseries reflect escrow activity', function () {
    phase5Fixture(7000000);
    $admin = User::factory()->admin()->create();

    Sanctum::actingAs($admin, ['*']);

    $overview = test()->getJson('/api/v1/admin/analytics/overview?days=7')->assertStatus(200);
    expect($overview->json('data.funnel.contracts_created'))->toBeGreaterThanOrEqual(1)
        ->and($overview->json('data.volume.gross_escrow_funded_minor'))->toBeGreaterThanOrEqual(7000000)
        ->and($overview->json('data.users.by_tier'))->not->toBeEmpty();

    $series = test()->getJson('/api/v1/admin/analytics/timeseries?days=7')->assertStatus(200);
    expect($series->json('data'))->toHaveCount(7);
});

test('reminder command dispatches once per subject and user per day', function () {
    $fixture = phase5Fixture(4000000);

    \App\Models\EscrowContract::whereKey($fixture['contract']['id'])->update([
        'acceptance_expires_at' => now()->addHours(6),
        'status' => 'SHARED',
    ]);

    Artisan::call('escrow:send-reminders');
    $first = ReminderDispatch::count();

    Artisan::call('escrow:send-reminders');
    $second = ReminderDispatch::count();

    expect($first)->toBeGreaterThanOrEqual(1)
        ->and($second)->toBe($first);
});

test('invariant check is healthy and does not freeze when statements agree', function () {
    phase5Fixture(5000000);
    $admin = User::factory()->admin()->create();

    Sanctum::actingAs($admin, ['*']);
    $check = test()->postJson('/api/v1/admin/reconciliation/invariant-check')->assertStatus(200);

    expect($check->json('data.healthy'))->toBeTrue()
        ->and($check->json('data.freeze_active'))->toBeFalse();
});

test('compliance can raise a user tier manually with an audit trail', function () {
    $user = User::factory()->kycVerified()->create();
    $admin = User::factory()->admin()->create();

    Sanctum::actingAs($admin, ['*']);
    $response = test()->patchJson("/api/v1/admin/users/{$user->id}/aml-tier", [
        'kyc_tier' => 'TIER_3',
        'aml_risk_rating' => 'LOW',
        'reason' => 'Corporate KYB and source-of-funds evidence verified.',
    ])->assertStatus(200);

    expect($response->json('data.tier.tier'))->toBe('TIER_3')
        ->and($user->fresh()->kyc_tier->value)->toBe('TIER_3');

    $this->assertDatabaseHas('audit_logs', ['event' => 'user.aml_tier_changed']);
});
