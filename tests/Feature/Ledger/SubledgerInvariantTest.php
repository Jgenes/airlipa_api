<?php

use App\Enums\SubledgerType;
use App\Models\BalanceSnapshot;
use App\Models\ContractSubledgerAccount;
use App\Models\EscrowContract;
use App\Models\PartySubledgerAccount;
use App\Models\SubledgerEntry;
use App\Models\User;
use App\Services\SubledgerService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeContract(array $overrides = []): EscrowContract
{
    $initiator = User::factory()->kycVerified()->create();
    $counterparty = User::factory()->kycVerified()->create();

    return EscrowContract::create(array_merge([
        'contract_id' => 'esc_test_' . uniqid(),
        'reference' => 'ESC-TEST' . strtoupper(substr(uniqid(), -6)),
        'correlation_id' => 'corr_' . uniqid(),
        'initiator_id' => $initiator->id,
        'initiator_role' => 'SELLER',
        'counterparty_name' => 'Neema Msuya',
        'counterparty_phone' => $counterparty->phone,
        'counterparty_user_id' => $counterparty->id,
        'title' => 'Test escrow contract',
        'description' => 'Subledger invariant fixture.',
        'currency' => 'TZS',
        'amount_minor' => 10000000,
        'status' => 'ACCEPTED',
    ], $overrides));
}

test('opening contract subledgers is idempotent and creates party accounts', function () {
    $service = app(SubledgerService::class);
    $contract = makeContract();

    $first = $service->openForContract($contract);
    $second = $service->openForContract($contract);

    expect($first->id)->toBe($second->id)
        ->and(ContractSubledgerAccount::count())->toBe(1)
        ->and(PartySubledgerAccount::where('escrow_contract_id', $contract->id)->count())->toBe(2)
        ->and(PartySubledgerAccount::where('escrow_contract_id', $contract->id)->pluck('role')->sort()->values()->all())
        ->toBe(['BUYER', 'SELLER']);
});

test('deposit credits and bucket transfers are idempotent and conserve money', function () {
    $service = app(SubledgerService::class);
    $contract = makeContract();
    $account = $service->openForContract($contract);

    $credited = $service->creditDeposit($account, 5000000, ['idempotency_key' => 'dep-webhook-001', 'reason_code' => 'DEPOSIT_CONFIRMED']);
    expect((int) $credited->deposited_minor)->toBe(5000000)
        ->and((int) $credited->available_minor)->toBe(5000000);

    // Replayed webhook with the same idempotency key must not credit twice
    $replayed = $service->creditDeposit($account, 5000000, ['idempotency_key' => 'dep-webhook-001', 'reason_code' => 'DEPOSIT_CONFIRMED']);
    expect((int) $replayed->deposited_minor)->toBe(5000000)
        ->and(SubledgerEntry::where('subledger_type', SubledgerType::Contract)->where('subledger_id', $account->id)->count())->toBe(2);

    $moved = $service->transferBucket(SubledgerType::Contract, $account, 'available_minor', 'pending_release_minor', 2000000, [
        'idempotency_key' => 'rel-001',
        'reason_code' => 'RELEASE_REQUESTED',
    ]);

    expect((int) $moved->available_minor)->toBe(3000000)
        ->and((int) $moved->pending_release_minor)->toBe(2000000);

    // Replayed release request must not reserve twice
    $replayedMove = $service->transferBucket(SubledgerType::Contract, $account, 'available_minor', 'pending_release_minor', 2000000, [
        'idempotency_key' => 'rel-001',
        'reason_code' => 'RELEASE_REQUESTED',
    ]);

    expect((int) $replayedMove->pending_release_minor)->toBe(2000000)
        ->and(SubledgerEntry::where('subledger_type', SubledgerType::Contract)->where('subledger_id', $account->id)->count())->toBe(4);

    $service->assertContractConservation($moved->refresh());

    $scan = $service->scanPortfolioInvariant();
    expect($scan['violations'])->toBe([])
        ->and($scan['held_total_minor'])->toBe(5000000);
});

test('overspend of a subledger bucket is rejected and leaves balances untouched', function () {
    $service = app(SubledgerService::class);
    $contract = makeContract();
    $account = $service->openForContract($contract);

    $service->creditDeposit($account, 1000000, ['idempotency_key' => 'dep-002']);

    try {
        $service->transferBucket(SubledgerType::Contract, $account, 'available_minor', 'frozen_minor', 2500000, [
            'idempotency_key' => 'freeze-002',
            'reason_code' => 'DISPUTE_OPENED',
        ]);
        $this->fail('Expected DomainException for overspend.');
    } catch (DomainException $exception) {
        expect($exception->getMessage())->toContain('Insufficient CONTRACT subledger balance');
    }

    $fresh = ContractSubledgerAccount::find($account->id);
    expect((int) $fresh->available_minor)->toBe(1000000)
        ->and((int) $fresh->frozen_minor)->toBe(0)
        ->and($fresh->conservationDifference())->toBe(0);
});

test('unpermitted bucket pairs and missing idempotency keys are rejected', function () {
    $service = app(SubledgerService::class);
    $contract = makeContract();
    $account = $service->openForContract($contract);

    try {
        $service->transferBucket(SubledgerType::Contract, $account, 'fees_minor', 'available_minor', 100, ['idempotency_key' => 'bad-001']);
        // fees_minor -> available_minor is not a legal movement (fees never return to customer float)
        $this->fail('Expected DomainException for illegal bucket pair.');
    } catch (DomainException $exception) {
        expect($exception->getMessage())->toContain('not permitted');
    }

    try {
        $service->creditDeposit($account, 100, []);
        $this->fail('Expected DomainException for missing idempotency key.');
    } catch (DomainException $exception) {
        expect($exception->getMessage())->toContain('idempotency key');
    }
});

test('daily close writes one balance snapshot per subledger account', function () {
    $service = app(SubledgerService::class);
    $contract = makeContract();
    $account = $service->openForContract($contract);
    $service->creditDeposit($account, 750000, ['idempotency_key' => 'dep-003']);

    $written = $service->snapshotBalances('2026-10-20');

    expect($written)->toBe(3)
        ->and(BalanceSnapshot::where('as_of_date', '2026-10-20')->count())->toBe(3)
        ->and((int) BalanceSnapshot::where('snapshot_type', 'CONTRACT_SUBLEDGER')->where('subject_id', $account->id)->first()->balance_minor)
        ->toBe(750000);

    // Re-running the same day's close is an upsert, not a duplicate
    $service->snapshotBalances('2026-10-20');
    expect(BalanceSnapshot::where('as_of_date', '2026-10-20')->count())->toBe(3);
});
