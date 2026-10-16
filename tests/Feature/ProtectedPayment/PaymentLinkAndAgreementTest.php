<?php

use App\Enums\BusinessProfileStatus;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('verified merchant can create a Protected Payment link, buyer can preview read-only and checkout', function () {
    $merchant = User::factory()->kybVerified()->create();
    BusinessProfile::factory()->create([
        'user_id' => $merchant->id,
        'business_name' => 'Arusha Solar Systems Ltd',
        'status' => BusinessProfileStatus::Verified,
    ]);

    $buyer = User::factory()->kycVerified()->create([
        'phone' => '+255712345678',
    ]);

    // 1. Merchant creates Protected Payment Link
    Sanctum::actingAs($merchant, ['*']);

    $createLinkResponse = $this->postJson('/api/v1/payment-links', [
        'title' => '200W Solar Panel Kit + Inverter',
        'description' => 'Complete solar home system with 2-year warranty and Arusha delivery.',
        'currency' => 'TZS',
        'principal_amount_minor' => 25000000, // 250,000.00 TZS
        'delivery_method' => 'COURIER',
        'delivery_days' => 2,
        'permitted_buyer_phone' => '0712345678',
        'max_uses' => 1,
    ]);

    $createLinkResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'next_step' => 'share_payment_link',
            'data' => [
                'title' => '200W Solar Panel Kit + Inverter',
                'status' => 'ACTIVE',
                'uses_count' => 0,
            ],
        ]);

    $token = $createLinkResponse->json('data.token');
    expect(strlen($token))->toBe(48);

    // 2. Public GET preview is strictly read-only and does not consume uses_count (BPA-CH-14)
    $previewResponse = $this->getJson("/api/v1/payment-links/{$token}");
    $previewResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'ACTIVE',
                'transaction_type' => 'PROTECTED_PAYMENT',
                'principal_amount_minor' => 25000000,
                'merchant_trust_panel' => [
                    'trading_name' => 'Arusha Solar Systems Ltd',
                    'is_verified' => true,
                ],
            ],
        ]);

    // 3. Buyer checks out from the link
    Sanctum::actingAs($buyer, ['*']);

    $checkoutResponse = $this->postJson("/api/v1/payment-links/{$token}/checkout");
    $checkoutResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'next_step' => 'initiate_payment_intent',
            'data' => [
                'agreement' => [
                    'title' => '200W Solar Panel Kit + Inverter',
                    'merchant_id' => $merchant->id,
                    'buyer_id' => $buyer->id,
                    'states' => [
                        'status' => 'AWAITING_FUNDING',
                        'fulfillment_status' => 'PENDING',
                        'dispute_status' => 'NONE',
                        'settlement_status' => 'NOT_ELIGIBLE',
                    ],
                ],
            ],
        ]);

    expect($checkoutResponse->json('data.handover_otp'))->toHaveLength(6);

    // 4. Subsequent preview or checkout fails because max_uses (1) is now EXHAUSTED
    $this->getJson("/api/v1/payment-links/{$token}")->assertStatus(422);
});

test('unverified business merchant cannot create a Protected Payment link', function () {
    $pendingMerchant = User::factory()->kybPending()->create();
    Sanctum::actingAs($pendingMerchant, ['*']);

    $response = $this->postJson('/api/v1/payment-links', [
        'title' => 'Laptop',
        'description' => 'Brand new laptop',
        'principal_amount_minor' => 50000000,
    ]);

    $response->assertStatus(403);
});

test('direct Protected Payment agreement with milestones validates milestone sum and enforces tenant isolation', function () {
    $merchant = User::factory()->kycVerified()->create();
    $buyer = User::factory()->kycVerified()->create();
    $stranger = User::factory()->kycVerified()->create();

    Sanctum::actingAs($buyer, ['*']);

    // 1. Mismatched milestones sum fails validation
    $badResponse = $this->postJson('/api/v1/protected-payments', [
        'merchant_id' => $merchant->id,
        'principal_amount_minor' => 10000000,
        'title' => 'Custom Tailored Wedding Suit',
        'item_description' => 'Two-piece bespoke suit with silk lining',
        'delivery_method' => 'MILESTONE',
        'milestones' => [
            ['title' => 'Fabric & Cutting', 'amount_minor' => 4000000],
            ['title' => 'Final Fitting & Delivery', 'amount_minor' => 4000000],
        ],
    ]);

    $badResponse->assertStatus(422)
        ->assertJsonValidationErrors(['milestones']);

    // 2. Matching milestones sum succeeds
    $goodResponse = $this->postJson('/api/v1/protected-payments', [
        'merchant_id' => $merchant->id,
        'principal_amount_minor' => 10000000,
        'title' => 'Custom Tailored Wedding Suit',
        'item_description' => 'Two-piece bespoke suit with silk lining',
        'delivery_method' => 'MILESTONE',
        'milestones' => [
            ['title' => 'Fabric & Cutting', 'amount_minor' => 4000000],
            ['title' => 'Final Fitting & Delivery', 'amount_minor' => 6000000],
        ],
    ]);

    $goodResponse->assertStatus(201);
    $protectedPaymentId = $goodResponse->json('data.agreement.protected_payment_id');
    expect($goodResponse->json('data.agreement.milestones'))->toHaveCount(2);

    // 3. Stranger user cannot access this Protected Payment agreement (FR-035 tenant/party isolation)
    Sanctum::actingAs($stranger, ['*']);
    $this->getJson("/api/v1/protected-payments/{$protectedPaymentId}")->assertStatus(403);
});
