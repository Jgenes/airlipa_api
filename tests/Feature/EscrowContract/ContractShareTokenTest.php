<?php

use App\Mail\ContractShareMail;
use App\Models\ContractShare;
use App\Models\User;
use App\Services\ContractShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function shareContractVia(User $initiator, array $channels): array
{
    Sanctum::actingAs($initiator, ['*']);

    $createResponse = test()->postJson('/api/v1/contracts', [
        'initiator_role' => 'SELLER',
        'counterparty_name' => 'Neema Msuya',
        'counterparty_phone' => '+255712345678',
        'counterparty_email' => 'neema.msuya@example.co.tz',
        'title' => 'Chicken feed bulk order',
        'description' => '40 bags of 50kg layers mash, delivered Mwanza.',
        'amount_minor' => 4800000,
    ]);

    $createResponse->assertStatus(201);

    $shareResponse = test()->postJson("/api/v1/contracts/{$createResponse->json('data.id')}/share", [
        'channels' => $channels,
    ]);

    $shareResponse->assertStatus(200);

    return [$createResponse->json('data.id'), $shareResponse->json('data.shares')];
}

test('tampered or unknown share tokens resolve to 404 and expired tokens to 410', function () {
    $initiator = User::factory()->kycVerified()->create();
    [, $shares] = shareContractVia($initiator, ['LINK']);

    $token = $shares[0]['token'];

    $this->getJson("/api/v1/contracts/share/{$token}")->assertStatus(200);

    $tampered = substr($token, 0, -4) . 'dead';
    $this->getJson("/api/v1/contracts/share/{$tampered}")->assertStatus(404);

    [$validToken] = app(ContractShareService::class)->issueToken(987654);
    $this->getJson("/api/v1/contracts/share/{$validToken}")->assertStatus(404);

    ContractShare::where('token', $token)->update(['token_expires_at' => now()->subHour()]);
    $this->getJson("/api/v1/contracts/share/{$token}")->assertStatus(410);
});

test('whatsapp, sms, email, qr and link channels produce the expected payloads and delivery states', function () {
    Mail::fake();

    $initiator = User::factory()->kycVerified()->create();
    [, $shares] = shareContractVia($initiator, ['WHATSAPP', 'SMS', 'EMAIL', 'QR', 'LINK']);

    $byChannel = collect($shares)->keyBy('channel');

    expect($byChannel['WHATSAPP']['status'])->toBe('DELIVERED')
        ->and($byChannel['WHATSAPP']['payload']['whatsapp_url'])->toStartWith('https://wa.me/255712345678?text=')
        ->and($byChannel['SMS']['status'])->toBe('DELIVERED')
        ->and($byChannel['EMAIL']['status'])->toBe('DELIVERED')
        ->and($byChannel['QR']['status'])->toBe('CREATED')
        ->and($byChannel['QR']['payload']['qr_payload'])->toContain($byChannel['QR']['token'])
        ->and($byChannel['LINK']['payload']['url'])->toContain($byChannel['LINK']['token']);

    Mail::assertSent(ContractShareMail::class, function (ContractShareMail $mail): bool {
        return $mail->hasTo('neema.msuya@example.co.tz');
    });
});

test('share channels requiring a missing recipient are rejected', function () {
    $initiator = User::factory()->kycVerified()->create();

    Sanctum::actingAs($initiator, ['*']);
    $createResponse = $this->postJson('/api/v1/contracts', [
        'initiator_role' => 'SELLER',
        'counterparty_name' => 'Neema Msuya',
        'counterparty_email' => 'neema.msuya@example.co.tz',
        'title' => 'Email-only counterparty',
        'description' => 'No phone captured for this counterparty.',
        'amount_minor' => 1200000,
    ])->assertStatus(201);

    $this->postJson("/api/v1/contracts/{$createResponse->json('data.id')}/share", ['channels' => ['SMS']])
        ->assertStatus(422);
});

test('a user who is not the named counterparty cannot request the acceptance OTP', function () {
    $initiator = User::factory()->kycVerified()->create();
    $stranger = User::factory()->kycVerified()->create(['phone' => '+255799999999']);

    [, $shares] = shareContractVia($initiator, ['LINK']);

    Sanctum::actingAs($stranger, ['*']);
    $this->postJson("/api/v1/contracts/share/{$shares[0]['token']}/acceptance-otp")->assertStatus(403);
});

test('disabled channels are refused by the share endpoint', function () {
    config()->set('escrow_contract.enabled_channels', ['LINK']);

    $initiator = User::factory()->kycVerified()->create();

    Sanctum::actingAs($initiator, ['*']);
    $createResponse = $this->postJson('/api/v1/contracts', [
        'initiator_role' => 'SELLER',
        'counterparty_name' => 'Neema Msuya',
        'counterparty_phone' => '+255712345678',
        'title' => 'Channel control test',
        'description' => 'Validates the enabled channel allow-list.',
        'amount_minor' => 900000,
    ])->assertStatus(201);

    $this->postJson("/api/v1/contracts/{$createResponse->json('data.id')}/share", ['channels' => ['WHATSAPP']])
        ->assertStatus(422);
});
