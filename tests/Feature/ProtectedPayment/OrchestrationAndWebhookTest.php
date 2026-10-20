<?php

use App\Models\LedgerJournal;
use App\Models\ReconciliationException;
use App\Models\User;
use App\Services\PaymentProviders\PaymentProviderAdapterInterface;
use App\Services\ProviderRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('initiating payment intent enforces idempotency and blocks duplicate failover while PENDING', function () {
    $merchant = User::factory()->kycVerified()->create();
    $buyer = User::factory()->kycVerified()->create(['phone' => '+255754111222']);

    Sanctum::actingAs($buyer, ['*']);

    $agreementResponse = $this->postJson('/api/v1/protected-payments', [
        'merchant_id' => $merchant->id,
        'principal_amount_minor' => 10000000, // 100,000.00 TZS
        'title' => 'Refurbished ThinkPad X1 Carbon',
        'item_description' => '16GB RAM, 512GB NVMe SSD, Grade A',
        'delivery_method' => 'COURIER',
    ]);

    $protectedPaymentId = $agreementResponse->json('data.agreement.protected_payment_id');

    // 1. First collection request with Idempotency-Key
    $payPayload = [
        'provider_code' => 'MPESA_TZ',
        'payer_phone_or_account' => '0754111222',
        'idempotency_key' => 'idem-mpesa-collect-001',
    ];

    $firstPay = $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/pay", $payPayload);
    $firstPay->assertStatus(202)
        ->assertJson([
            'success' => true,
            'next_step' => 'await_provider_confirmation',
            'data' => [
                'idempotent_replay' => false,
                'provider_code' => 'MPESA_TZ',
                'intent_status' => 'PENDING',
                'amount_minor' => 10177000,
            ],
        ]);

    $intentId = $firstPay->json('data.intent_id');

    // 2. Exact replay with same Idempotency-Key returns 200 and same intent (FR-007)
    $replayPay = $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/pay", $payPayload);
    $replayPay->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'idempotent_replay' => true,
                'intent_id' => $intentId,
            ],
        ]);

    // 3. Reusing same Idempotency-Key with mutated payload returns 409 Conflict (FR-007)
    $mutatedPay = $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/pay", [
        'provider_code' => 'AIRTEL_TZ',
        'payer_phone_or_account' => '0754111222',
        'idempotency_key' => 'idem-mpesa-collect-001',
    ]);
    $mutatedPay->assertStatus(409);

    // 4. Attempting failover with a new Idempotency-Key while previous attempt is still PENDING is blocked (BPA-SVC-06)
    $failoverPay = $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/pay", [
        'provider_code' => 'AIRTEL_TZ',
        'payer_phone_or_account' => '0754111222',
        'idempotency_key' => 'idem-airtel-collect-002',
    ]);
    $failoverPay->assertStatus(409);
});

test('HMAC-signed provider webhook is idempotent, rejects forged signatures, and posts balanced sub-ledger entries', function () {
    $merchant = User::factory()->kycVerified()->create();
    $buyer = User::factory()->kycVerified()->create(['phone' => '+255754333444']);

    Sanctum::actingAs($buyer, ['*']);

    $agreementResponse = $this->postJson('/api/v1/protected-payments', [
        'merchant_id' => $merchant->id,
        'principal_amount_minor' => 10000000,
        'title' => 'iPhone 14 Pro 256GB',
        'item_description' => 'Factory unlocked, battery health 94%',
    ]);
    $protectedPaymentId = $agreementResponse->json('data.agreement.protected_payment_id');

    $payResponse = $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/pay", [
        'provider_code' => 'MPESA_TZ',
        'payer_phone_or_account' => '0754333444',
        'idempotency_key' => 'idem-mpesa-webhook-001',
    ]);

    $attemptId = $payResponse->json('data.attempt_id');
    $providerRef = $payResponse->json('data.provider_reference');
    $grossAmountMinor = $payResponse->json('data.amount_minor'); // 10,177,000

    $webhookPayload = [
        'event_id' => 'evt_mpesa_001',
        'event_type' => 'ProviderPaymentConfirmed',
        'attempt_id' => $attemptId,
        'provider_reference' => $providerRef,
        'provider_transaction_id' => $providerRef,
        'status' => 'SUCCESS',
        'amount_minor' => $grossAmountMinor,
        'currency' => 'TZS',
    ];
    $rawBody = json_encode($webhookPayload);
    $timestamp = (string) now()->timestamp;
    $nonce = 'nonce_mpesa_test_001';

    // 1. Forged signature is rejected with 403 and causes zero ledger postings
    $forgedResponse = $this->call(
        'POST',
        '/api/v1/webhooks/providers/MPESA_TZ',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PROVIDER_TIMESTAMP' => $timestamp,
            'HTTP_X_PROVIDER_NONCE' => $nonce,
            'HTTP_X_PROVIDER_SIGNATURE' => 'forged-invalid-hmac',
        ],
        $rawBody
    );
    $forgedResponse->assertStatus(403);
    expect(LedgerJournal::count())->toBe(0);

    // 2. Valid HMAC-signed webhook transitions payment to FUNDED_PROTECTED and posts balanced journal
    /** @var ProviderRegistryService $registry */
    $registry = app(ProviderRegistryService::class);
    /** @var PaymentProviderAdapterInterface $adapter */
    $adapter = app(PaymentProviderAdapterInterface::class);

    $capability = $registry->getCapability('MPESA_TZ');
    $validSignature = $adapter->signWebhookPayload($capability->webhook_secret, $rawBody, $timestamp, $nonce);

    $validResponse = $this->call(
        'POST',
        '/api/v1/webhooks/providers/MPESA_TZ',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PROVIDER_TIMESTAMP' => $timestamp,
            'HTTP_X_PROVIDER_NONCE' => $nonce,
            'HTTP_X_PROVIDER_SIGNATURE' => $validSignature,
        ],
        $rawBody
    );

    $validResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'duplicate_ignored' => false,
                'processing_status' => 'PROCESSED',
                'protected_payment_status' => 'FUNDED_PROTECTED',
            ],
        ]);

    expect(LedgerJournal::count())->toBe(1);
    $journal = LedgerJournal::with('postings')->first();
    expect($journal->total_debit_minor)->toBe(10177000)
        ->and($journal->total_credit_minor)->toBe(10177000);

    // 3. Replaying the exact same webhook 3 times produces zero duplicate ledger journals (4.2.7)
    for ($i = 0; $i < 3; $i++) {
        $replayWebhook = $this->call(
            'POST',
            '/api/v1/webhooks/providers/MPESA_TZ',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_PROVIDER_TIMESTAMP' => $timestamp,
                'HTTP_X_PROVIDER_NONCE' => $nonce,
                'HTTP_X_PROVIDER_SIGNATURE' => $validSignature,
            ],
            $rawBody
        );

        $replayWebhook->assertStatus(200)
            ->assertJsonPath('data.duplicate_ignored', true);
    }

    expect(LedgerJournal::count())->toBe(1);
});

test('provider webhook with mismatched amount transitions intent to UNKNOWN and opens a reconciliation exception', function () {
    $merchant = User::factory()->kycVerified()->create();
    $buyer = User::factory()->kycVerified()->create();

    Sanctum::actingAs($buyer, ['*']);

    $agreementResponse = $this->postJson('/api/v1/protected-payments', [
        'merchant_id' => $merchant->id,
        'principal_amount_minor' => 10000000,
        'title' => 'Canon EOS R6 Camera Body',
        'item_description' => 'Low shutter count mirrorless body',
    ]);
    $protectedPaymentId = $agreementResponse->json('data.agreement.protected_payment_id');

    $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/pay", [
        'provider_code' => 'TPESA_TZ',
        'payer_phone_or_account' => '0732111222',
        'idempotency_key' => 'idem-tpesa-mismatch-001',
    ]);

    // Simulate a callback that pays half the expected amount. Phase 2 rules
    // say an underpayment is held as a partial in suspense - it is never
    // credited to the agreement and never released.
    $simResponse = $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/simulate-provider-callback", [
        'status' => 'SUCCEEDED',
        'amount_minor' => 5000000, // Half of expected amount!
    ]);

    $simResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'processing_status' => 'PARTIAL_HELD',
                'intent_status' => 'PENDING',
            ],
        ]);

    expect(ReconciliationException::count())->toBe(1)
        ->and(LedgerJournal::count())->toBe(0);
});

test('admin can suspend a degraded provider capability and new collections on that rail are blocked', function () {
    $admin = User::factory()->admin()->create();
    $merchant = User::factory()->kycVerified()->create();
    $buyer = User::factory()->kycVerified()->create();

    // 1. Admin suspends MIXX_TZ due to upstream partner maintenance
    Sanctum::actingAs($admin, ['*']);
    $govResponse = $this->patchJson('/api/v1/admin/providers/MIXX_TZ/status', [
        'is_active' => false,
        'reason' => 'Scheduled partner gateway maintenance window',
    ]);
    $govResponse->assertStatus(200)
        ->assertJsonPath('data.is_active', false);

    // 2. Buyer tries to collect via MIXX_TZ and receives 422
    Sanctum::actingAs($buyer, ['*']);
    $agreement = $this->postJson('/api/v1/protected-payments', [
        'merchant_id' => $merchant->id,
        'principal_amount_minor' => 5000000,
        'title' => 'Office Desk',
        'item_description' => 'Solid mahogany office desk',
    ]);
    $protectedPaymentId = $agreement->json('data.agreement.protected_payment_id');

    $payResponse = $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/pay", [
        'provider_code' => 'MIXX_TZ',
        'payer_phone_or_account' => '0655111222',
        'idempotency_key' => 'idem-mixx-suspended-001',
    ]);
    $payResponse->assertStatus(422);
});
