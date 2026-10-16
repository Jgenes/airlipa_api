<?php

use App\Enums\BusinessProfileStatus;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('authenticated user can retrieve current approved price book schedule', function () {
    $user = User::factory()->kycVerified()->create();
    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('/api/v1/pricing/schedules/current');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'next_step' => 'create_quote',
            'data' => [
                'pricing_version' => 'TZ-R1-2026-10-01',
                'currency' => 'TZS',
            ],
        ]);

    expect($response->json('data.rules'))->toBeArray()->not->toBeEmpty();
});

test('pricing service deterministically calculates buyer and merchant fees using integer minor units', function () {
    $merchant = User::factory()->kybVerified()->create();
    BusinessProfile::factory()->create([
        'user_id' => $merchant->id,
        'business_name' => 'Kilimanjaro Electronics Ltd',
        'status' => BusinessProfileStatus::Verified,
    ]);

    $buyer = User::factory()->kycVerified()->create();
    Sanctum::actingAs($buyer, ['*']);

    // 100,000.00 TZS in minor units = 10,000,000
    // Buyer Protection Fee: 1.5% of 10,000,000 = 150,000 minor units (1,500.00 TZS)
    // Buyer VAT (18% of 150,000) = 27,000 minor units (270.00 TZS)
    // Total Payable = 10,000,000 + 150,000 + 27,000 = 10,177,000
    // Merchant Orchestration Fee: 1.0% of 10,000,000 = 100,000 minor units (1,000.00 TZS)
    // Merchant VAT (18% of 100,000) = 18,000 minor units (180.00 TZS)
    // Merchant Net = 10,000,000 - 100,000 - 18,000 = 9,882,000
    $response = $this->postJson('/api/v1/pricing/quotes', [
        'merchant_id' => $merchant->id,
        'buyer_id' => $buyer->id,
        'transaction_type' => 'PROTECTED_PAYMENT',
        'currency' => 'TZS',
        'principal_amount_minor' => 10000000,
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'next_step' => 'accept_quote',
            'data' => [
                'pricing_version' => 'TZ-R1-2026-10-01',
                'transaction_type' => 'PROTECTED_PAYMENT',
                'currency' => 'TZS',
                'principal_amount_minor' => 10000000,
                'buyer_fee_minor' => 150000,
                'buyer_tax_minor' => 27000,
                'merchant_fee_minor' => 100000,
                'merchant_tax_minor' => 18000,
                'total_payable_minor' => 10177000,
                'merchant_net_minor' => 9882000,
                'status' => 'CREATED',
            ],
        ]);

    $quoteId = $response->json('data.quote_id');
    expect($quoteId)->toStartWith('fq_');

    // Accept quote
    $acceptResponse = $this->postJson("/api/v1/pricing/quotes/{$quoteId}/accept");
    $acceptResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'next_step' => 'create_protected_payment',
            'data' => [
                'quote_id' => $quoteId,
                'status' => 'ACCEPTED',
            ],
        ]);
});

test('creating a fee quote for an unverified merchant is rejected per BPA-SBV-02', function () {
    $unverifiedMerchant = User::factory()->kybPending()->create();
    $buyer = User::factory()->kycVerified()->create();

    Sanctum::actingAs($buyer, ['*']);

    $response = $this->postJson('/api/v1/pricing/quotes', [
        'merchant_id' => $unverifiedMerchant->id,
        'principal_amount_minor' => 5000000,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['merchant_id']);
});
