<?php

use App\Models\ContractSignature;
use App\Models\ContractSubledgerAccount;
use App\Models\EscrowContract;
use App\Models\LedgerJournal;
use App\Models\OtpCode;
use App\Models\PartySubledgerAccount;
use App\Models\ProtectedPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createDraftContract(User $initiator, array $overrides = []): array
{
    Sanctum::actingAs($initiator, ['*']);
    $response = test()->postJson('/api/v1/contracts', array_merge([
        'initiator_role' => 'SELLER',
        'counterparty_name' => 'Neema Msuya',
        'counterparty_phone' => '+255712345678',
        'title' => '200W Solar Panel Kit',
        'description' => 'Complete solar home system with 2-year warranty, Arusha delivery.',
        'category' => 'ENERGY',
        'currency' => 'TZS',
        'amount_minor' => 25000000,
        'inspection_hours' => 48,
        'refund_terms' => 'Full refund if the kit is not delivered within the deadline.',
        'fee_payer' => 'BUYER',
    ], $overrides));

    $response->assertStatus(201)->assertJson(['success' => true, 'data' => ['status' => 'DRAFT']]);

    return $response->json('data');
}

test('initiator can draft, edit, share and counterparty can accept with OTP e-signature', function () {
    $initiator = User::factory()->kycVerified()->create(['phone' => '+255711111111']);
    $counterparty = User::factory()->kycVerified()->create(['phone' => '+255712345678']);

    $draft = createDraftContract($initiator);
    $contractId = $draft['id'];

    // Draft edits stay in DRAFT and refresh the terms hash
    Sanctum::actingAs($initiator, ['*']);
    $this->patchJson("/api/v1/contracts/{$contractId}", ['title' => '200W Solar Panel Kit + Inverter'])
        ->assertStatus(200)
        ->assertJsonPath('data.title', '200W Solar Panel Kit + Inverter');

    // Share through link, WhatsApp and QR
    Sanctum::actingAs($initiator, ['*']);
    $shareResponse = $this->postJson("/api/v1/contracts/{$contractId}/share", [
        'channels' => ['LINK', 'WHATSAPP', 'QR'],
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.contract.status', 'SHARED');

    expect($shareResponse->json('data.shares'))->toHaveCount(3);

    $token = collect($shareResponse->json('data.shares'))
        ->firstWhere('channel', 'LINK')['token'];

    // Anonymous view is read-only and tracked per channel
    $this->getJson("/api/v1/contracts/share/{$token}")
        ->assertStatus(200)
        ->assertJsonPath('data.your_role', 'BUYER')
        ->assertJsonPath('data.status', 'SHARED')
        ->assertJsonPath('data.share.view_count', 1);

    $this->getJson("/api/v1/contracts/share/{$token}")
        ->assertStatus(200)
        ->assertJsonPath('data.share.view_count', 2);

    // Counterparty requests the acceptance OTP then signs with it
    Sanctum::actingAs($counterparty, ['*']);
    $this->postJson("/api/v1/contracts/share/{$token}/acceptance-otp")
        ->assertStatus(200);

    OtpCode::where('user_id', $counterparty->id)
        ->where('purpose', 'contract_acceptance')
        ->latest()
        ->first()
        ->update(['code_hash' => Hash::make('424242')]);

    Sanctum::actingAs($counterparty, ['*']);
    $acceptResponse = $this->postJson("/api/v1/contracts/share/{$token}/accept", [
        'otp_code' => '424242',
        'full_name' => 'Neema Msuya',
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.contract.status', 'ACCEPTED');

    expect($acceptResponse->json('data.protected_payment_id'))->not->toBeNull();

    // Both e-signatures recorded against the same SHA-256 contract hash
    $contract = EscrowContract::findOrFail($contractId);
    $hash = $contract->versions()->where('version', 1)->first()->terms_hash;

    expect(ContractSignature::where('escrow_contract_id', $contractId)->count())->toBe(2)
        ->and(ContractSignature::where('escrow_contract_id', $contractId)->pluck('contract_hash')->unique()->all())
        ->toBe([$hash]);

    // Acceptance minted the Protected Payment and opened every subledger
    $payment = ProtectedPayment::where('escrow_contract_id', $contractId)->first();
    expect($payment)->not->toBeNull()
        ->and($payment->status->value)->toBe('AWAITING_FUNDING')
        ->and($payment->merchant_id)->toBe($initiator->id)
        ->and($payment->buyer_id)->toBe($counterparty->id);

    $subledger = ContractSubledgerAccount::where('escrow_contract_id', $contractId)->first();
    expect($subledger)->not->toBeNull()
        ->and((int) $subledger->deposited_minor)->toBe(0)
        ->and($subledger->conservationDifference())->toBe(0);

    expect(PartySubledgerAccount::where('escrow_contract_id', $contractId)->count())->toBe(2);

    // No money moved yet, so no GL journal may exist
    expect(LedgerJournal::count())->toBe(0);

    // A replayed acceptance on the same token is rejected
    Sanctum::actingAs($counterparty, ['*']);
    $this->postJson("/api/v1/contracts/share/{$token}/accept", ['otp_code' => '424242'])
        ->assertStatus(422);
});

test('milestone contracts open one milestone subledger per milestone on acceptance', function () {
    $initiator = User::factory()->kycVerified()->create();
    $counterparty = User::factory()->kycVerified()->create(['phone' => '+255712345678']);

    $draft = createDraftContract($initiator, [
        'milestones' => [
            ['title' => 'Deposit & procurement', 'amount_minor' => 15000000],
            ['title' => 'Installation & handover', 'amount_minor' => 10000000],
        ],
    ]);

    expect($draft['milestones'])->toHaveCount(2);

    Sanctum::actingAs($initiator, ['*']);
    $shareResponse = $this->postJson("/api/v1/contracts/{$draft['id']}/share", ['channels' => ['LINK']])
        ->assertStatus(200);

    $token = $shareResponse->json('data.shares.0.token');

    Sanctum::actingAs($counterparty, ['*']);
    $this->postJson("/api/v1/contracts/share/{$token}/acceptance-otp")
        ->assertStatus(200);

    OtpCode::where('user_id', $counterparty->id)->where('purpose', 'contract_acceptance')->latest()->first()
        ->update(['code_hash' => Hash::make('111222')]);

    Sanctum::actingAs($counterparty, ['*']);
    $this->postJson("/api/v1/contracts/share/{$token}/accept", ['otp_code' => '111222'])
        ->assertStatus(200);

    $payment = ProtectedPayment::where('escrow_contract_id', $draft['id'])->first();

    expect($payment->milestones()->count())->toBe(2)
        ->and(\App\Models\MilestoneSubledgerAccount::where('escrow_contract_id', $draft['id'])->count())->toBe(2);
});

test('milestone amounts must sum to the contract amount', function () {
    $initiator = User::factory()->kycVerified()->create();

    Sanctum::actingAs($initiator, ['*']);
    $this->postJson('/api/v1/contracts', [
            'initiator_role' => 'SELLER',
            'counterparty_name' => 'Neema Msuya',
            'counterparty_phone' => '+255712345678',
            'title' => 'Borehole drilling',
            'description' => 'Drill and line a 60m borehole.',
            'amount_minor' => 10000000,
            'milestones' => [
                ['title' => 'Mobilisation', 'amount_minor' => 4000000],
                ['title' => 'Drilling', 'amount_minor' => 4000000],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('milestones');
});

test('counterparty can reject a shared contract and initiator can cancel drafts', function () {
    $initiator = User::factory()->kycVerified()->create();
    $counterparty = User::factory()->kycVerified()->create(['phone' => '+255712345678']);

    $draft = createDraftContract($initiator);

    Sanctum::actingAs($initiator, ['*']);
    $shareResponse = $this->postJson("/api/v1/contracts/{$draft['id']}/share", ['channels' => ['LINK']])
        ->assertStatus(200);

    $token = $shareResponse->json('data.shares.0.token');

    Sanctum::actingAs($counterparty, ['*']);
    $this->postJson("/api/v1/contracts/share/{$token}/reject", ['reason' => 'Price too high for this scope.'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'REJECTED');

    // Cancelled/rejected contracts cannot be accepted
    Sanctum::actingAs($counterparty, ['*']);
    $this->postJson("/api/v1/contracts/share/{$token}/accept", ['otp_code' => '000000'])
        ->assertStatus(422);

    $second = createDraftContract($initiator);

    Sanctum::actingAs($initiator, ['*']);
    $this->postJson("/api/v1/contracts/{$second['id']}/cancel", ['reason' => 'Deal moved offline.'])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'CANCELLED');
});

test('shared contracts expire automatically once the acceptance window elapses', function () {
    $initiator = User::factory()->kycVerified()->create();

    $draft = createDraftContract($initiator);

    Sanctum::actingAs($initiator, ['*']);
    $this->postJson("/api/v1/contracts/{$draft['id']}/share", ['channels' => ['LINK']])
        ->assertStatus(200);

    EscrowContract::whereKey($draft['id'])->update(['acceptance_expires_at' => now()->subMinutes(5)]);

    $this->artisan('escrow-contracts:expire-overdue')->assertSuccessful();

    expect(EscrowContract::findOrFail($draft['id'])->status->value)->toBe('EXPIRED');
});

test('non-party users cannot read a contract through the authenticated endpoint', function () {
    $initiator = User::factory()->kycVerified()->create();
    $stranger = User::factory()->kycVerified()->create();

    $draft = createDraftContract($initiator);

    Sanctum::actingAs($stranger, ['*']);
    $this->getJson("/api/v1/contracts/{$draft['id']}")
        ->assertStatus(403);
});
