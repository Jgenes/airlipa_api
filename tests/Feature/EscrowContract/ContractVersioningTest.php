<?php

use App\Models\EscrowContract;
use App\Models\EscrowContractVersion;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('counter-proposals require initiator approval before acceptance and re-sign the final hash', function () {
    $initiator = User::factory()->kycVerified()->create();
    $counterparty = User::factory()->kycVerified()->create(['phone' => '+255712345678']);

    Sanctum::actingAs($initiator, ['*']);
    $createResponse = $this->postJson('/api/v1/contracts', [
        'initiator_role' => 'SELLER',
        'counterparty_name' => 'Neema Msuya',
        'counterparty_phone' => '+255712345678',
        'title' => 'Maize milling machine',
        'description' => '1TPH hammer mill with motor and stand.',
        'amount_minor' => 18000000,
    ])->assertStatus(201);

    $contractId = $createResponse->json('data.id');

    $shareResponse = $this->postJson("/api/v1/contracts/{$contractId}/share", ['channels' => ['LINK']])
        ->assertStatus(200);

    $oldToken = $shareResponse->json('data.shares.0.token');

    // Counterparty proposes a lower price -> version 2 pending initiator approval
    Sanctum::actingAs($counterparty, ['*']);
    $this->postJson("/api/v1/contracts/share/{$oldToken}/request-changes", [
        'amount_minor' => 15000000,
        'refund_terms' => '50% refund if not delivered within 14 days.',
    ])->assertStatus(201)
        ->assertJsonPath('data.version', 2)
        ->assertJsonPath('data.status', 'PENDING_INITIATOR_APPROVAL');

    expect(EscrowContract::findOrFail($contractId)->status->value)->toBe('DRAFT');

    // Acceptance is blocked while an amendment is pending
    $this->postJson("/api/v1/contracts/share/{$oldToken}/acceptance-otp")->assertStatus(422);

    // Initiator approves v2: it becomes CURRENT and the contract is shareable again
    Sanctum::actingAs($initiator, ['*']);
    $this->postJson("/api/v1/contracts/{$contractId}/versions/2/approve")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'SHARED')
        ->assertJsonPath('data.current_version', 2);

    expect(EscrowContractVersion::where('escrow_contract_id', $contractId)->where('version', 1)->first()->status->value)
        ->toBe('SUPERSEDED');

    // The stale v1 link cannot be used to accept the v2 terms
    Sanctum::actingAs($counterparty, ['*']);
    $this->postJson("/api/v1/contracts/share/{$oldToken}/accept", ['otp_code' => '000000'])
        ->assertStatus(422);

    // Re-share issues a v2 token; acceptance signs the v2 hash
    Sanctum::actingAs($initiator, ['*']);
    $reshare = $this->postJson("/api/v1/contracts/{$contractId}/share", ['channels' => ['LINK']])->assertStatus(200);
    $newToken = $reshare->json('data.shares.0.token');

    Sanctum::actingAs($counterparty, ['*']);
    $this->postJson("/api/v1/contracts/share/{$newToken}/acceptance-otp")->assertStatus(200);

    OtpCode::where('user_id', $counterparty->id)->where('purpose', 'contract_acceptance')->latest()->first()
        ->update(['code_hash' => Hash::make('314159')]);

    $this->postJson("/api/v1/contracts/share/{$newToken}/accept", ['otp_code' => '314159'])
        ->assertStatus(200)
        ->assertJsonPath('data.contract.status', 'ACCEPTED');

    $v2Hash = EscrowContractVersion::where('escrow_contract_id', $contractId)->where('version', 2)->first()->terms_hash;

    $this->assertDatabaseHas('contract_signatures', [
        'escrow_contract_id' => $contractId,
        'user_id' => $counterparty->id,
        'signer_role' => 'COUNTERPARTY',
        'contract_hash' => $v2Hash,
    ]);
});

test('only the initiator can approve a counter-proposal version', function () {
    $initiator = User::factory()->kycVerified()->create();
    $counterparty = User::factory()->kycVerified()->create(['phone' => '+255712345678']);

    Sanctum::actingAs($initiator, ['*']);
    $createResponse = $this->postJson('/api/v1/contracts', [
        'initiator_role' => 'BUYER',
        'counterparty_name' => 'Juma Hassan',
        'counterparty_phone' => '+255712345678',
        'title' => 'Office furniture supply',
        'description' => '12 desks and chairs delivered to Dodoma office.',
        'amount_minor' => 9000000,
    ])->assertStatus(201);

    $contractId = $createResponse->json('data.id');
    $token = $this->postJson("/api/v1/contracts/{$contractId}/share", ['channels' => ['LINK']])
        ->assertStatus(200)->json('data.shares.0.token');

    Sanctum::actingAs($counterparty, ['*']);
    $this->postJson("/api/v1/contracts/share/{$token}/request-changes", ['amount_minor' => 8500000])
        ->assertStatus(201);

    $this->postJson("/api/v1/contracts/{$contractId}/versions/2/approve")->assertStatus(403);
});
