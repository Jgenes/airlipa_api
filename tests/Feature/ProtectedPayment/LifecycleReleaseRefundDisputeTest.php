<?php

use App\Models\LedgerJournal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('full lifecycle: funding -> fulfillment evidence -> step-up buyer release -> verifiable receipt & conservation invariant', function () {
    $merchant = User::factory()->kycVerified()->create();
    $buyer = User::factory()->kycVerified()->create(['phone' => '+255754999888']);

    Sanctum::actingAs($buyer, ['*']);

    // 1. Create Protected Payment Agreement
    $agreementResponse = $this->postJson('/api/v1/protected-payments', [
        'merchant_id' => $merchant->id,
        'principal_amount_minor' => 20000000, // 200,000.00 TZS
        'title' => 'Samsung Galaxy S23 Ultra',
        'item_description' => '256GB Phantom Black with original charger',
        'delivery_method' => 'IN_PERSON_OTP',
    ]);

    $protectedPaymentId = $agreementResponse->json('data.agreement.protected_payment_id');
    $handoverOtp = $agreementResponse->json('data.handover_otp');

    // 2. Initiate Payment Intent & Confirm via Authenticated Provider Callback
    $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/pay", [
        'provider_code' => 'MPESA_TZ',
        'payer_phone_or_account' => '0754999888',
        'idempotency_key' => 'idem-lifecycle-001',
    ])->assertStatus(202);

    $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/simulate-provider-callback", [
        'status' => 'SUCCEEDED',
    ])->assertStatus(200);

    // 3. Merchant submits fulfillment evidence; verify it does NOT auto-release funds (FR-M10-06)
    Sanctum::actingAs($merchant, ['*']);

    $evidenceResponse = $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/evidence", [
        'evidence_type' => 'COURIER_TRACKING',
        'tracking_number' => 'DHL-TZ-99887766',
        'courier_name' => 'DHL Express Tanzania',
        'description' => 'Dispatched from Dar es Salaam to Arusha Clocktower branch.',
    ]);

    $evidenceResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'next_step' => 'review_and_release_or_dispute',
            'data' => [
                'agreement' => [
                    'states' => [
                        'status' => 'FULFILLMENT_SUBMITTED',
                        'fulfillment_status' => 'IN_TRANSIT',
                        'settlement_status' => 'NOT_ELIGIBLE',
                    ],
                ],
            ],
        ]);

    // 4. Merchant attempting to release funds to themselves is forbidden (P-02 Segregation of Duties)
    $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/release", [
        'handover_otp' => $handoverOtp,
        'idempotency_key' => 'idem-merchant-self-release',
    ])->assertStatus(403);

    // 5. Buyer releases funds using handover OTP step-up authentication (BPA-PER-06)
    Sanctum::actingAs($buyer, ['*']);

    $releaseResponse = $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/release", [
        'handover_otp' => $handoverOtp,
        'idempotency_key' => 'idem-buyer-release-001',
        'notes' => 'Inspected phone in person at Arusha branch, works great.',
    ]);

    $releaseResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'next_step' => 'view_receipt',
            'data' => [
                'idempotent_replay' => false,
                'agreement' => [
                    'states' => [
                        'status' => 'RELEASED',
                        'fulfillment_status' => 'CONFIRMED',
                        'settlement_status' => 'ELIGIBLE',
                    ],
                    'money' => [
                        'principal_amount_minor' => 20000000,
                        'protected_balance_minor' => 0,
                        'released_amount_minor' => 20000000,
                        'refunded_amount_minor' => 0,
                        'conservation_invariant_valid' => true,
                    ],
                ],
            ],
        ]);

    // 6. Verify verifiable digital receipt (FR-023)
    $receiptResponse = $this->getJson("/api/v1/protected-payments/{$protectedPaymentId}/receipt");
    $receiptResponse->assertStatus(200)
        ->assertJsonPath('data.status', 'RELEASED')
        ->assertJsonPath('data.conservation_invariant_valid', true);
    expect($receiptResponse->json('data.receipt_verification_hash'))->toHaveLength(64);
});

test('dispute opening atomically freezes protected balance and blocks buyer release until authorized resolution', function () {
    $admin = User::factory()->admin()->create();
    $merchant = User::factory()->kycVerified()->create();
    $buyer = User::factory()->kycVerified()->create();

    Sanctum::actingAs($buyer, ['*']);

    // 1. Create and fund a 100,000.00 TZS (10,000,000 minor units) Protected Payment
    $agreement = $this->postJson('/api/v1/protected-payments', [
        'merchant_id' => $merchant->id,
        'principal_amount_minor' => 10000000,
        'title' => 'Generator 5kVA',
        'item_description' => 'Silent diesel generator',
    ]);
    $protectedPaymentId = $agreement->json('data.agreement.protected_payment_id');
    $handoverOtp = $agreement->json('data.handover_otp');

    $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/pay", [
        'provider_code' => 'SANDBOX_PSP',
        'idempotency_key' => 'idem-dispute-fund-001',
    ])->assertStatus(202);

    $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/simulate-provider-callback", [
        'status' => 'SUCCEEDED',
    ])->assertStatus(200);

    // 2. Buyer opens a dispute for the full 10,000,000 minor units (FR-014)
    $disputeResponse = $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/disputes", [
        'case_type' => 'MATERIALLY_DIFFERENT',
        'disputed_amount_minor' => 10000000,
        'reason' => 'Generator delivered is 3kVA instead of agreed 5kVA.',
    ]);

    $disputeResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'next_step' => 'submit_dispute_evidence',
            'data' => [
                'dispute' => [
                    'case_type' => 'MATERIALLY_DIFFERENT',
                    'status' => 'OPEN_FROZEN',
                    'disputed_amount_minor' => 10000000,
                ],
                'agreement' => [
                    'states' => [
                        'status' => 'DISPUTED',
                        'dispute_status' => 'OPEN_FROZEN',
                        'settlement_status' => 'BLOCKED',
                    ],
                    'money' => [
                        'protected_balance_minor' => 10000000,
                        'disputed_amount_minor' => 10000000,
                        'eligible_for_decision_minor' => 0,
                        'conservation_invariant_valid' => true,
                    ],
                ],
            ],
        ]);

    $disputeCaseId = $disputeResponse->json('data.dispute.case_id');

    // 3. Release attempt while disputed is blocked (FR-014)
    $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/release", [
        'handover_otp' => $handoverOtp,
        'idempotency_key' => 'idem-release-blocked-by-dispute',
    ])->assertStatus(422);

    // 4. Buyer/Merchant cannot resolve their own dispute (P-07 Segregation of Duties)
    $this->postJson("/api/v1/disputes/{$disputeCaseId}/resolve", [
        'decision_outcome' => 'FULL_REFUND_BUYER',
        'release_to_merchant_minor' => 0,
        'refund_to_buyer_minor' => 10000000,
        'decision_reason_code' => 'SNAD_CONFIRMED',
        'decision_notes' => 'Self resolution attempt',
        'idempotency_key' => 'idem-self-resolve',
    ])->assertStatus(403);

    // 5. Admin resolves dispute with a 60/40 PARTIAL_SPLIT (6,000,000 to merchant, 4,000,000 to buyer)
    Sanctum::actingAs($admin, ['*']);

    $resolveResponse = $this->postJson("/api/v1/admin/disputes/{$disputeCaseId}/resolve", [
        'decision_outcome' => 'PARTIAL_SPLIT',
        'release_to_merchant_minor' => 6000000,
        'refund_to_buyer_minor' => 4000000,
        'decision_reason_code' => 'MUTUAL_PARTIAL_SETTLEMENT',
        'decision_notes' => 'Both parties agreed to keep the 3kVA unit at a 40% price reduction.',
        'idempotency_key' => 'idem-admin-resolve-split-001',
    ]);

    $resolveResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'dispute' => [
                    'status' => 'EXECUTED',
                    'decision_outcome' => 'PARTIAL_SPLIT',
                    'release_to_merchant_minor' => 6000000,
                    'refund_to_buyer_minor' => 4000000,
                ],
                'agreement' => [
                    'money' => [
                        'principal_amount_minor' => 10000000,
                        'protected_balance_minor' => 0,
                        'disputed_amount_minor' => 0,
                        'released_amount_minor' => 6000000,
                        'refunded_amount_minor' => 4000000,
                        'conservation_invariant_valid' => true,
                    ],
                ],
            ],
        ]);
});

test('append-only operational sub-ledger blocks in-place mutation or deletion of posted journals', function () {
    $merchant = User::factory()->kycVerified()->create();
    $buyer = User::factory()->kycVerified()->create();

    Sanctum::actingAs($buyer, ['*']);

    $agreement = $this->postJson('/api/v1/protected-payments', [
        'merchant_id' => $merchant->id,
        'principal_amount_minor' => 5000000,
        'title' => 'Printer HP LaserJet',
        'item_description' => 'Monochrome laser printer',
    ]);
    $protectedPaymentId = $agreement->json('data.agreement.protected_payment_id');

    $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/pay", [
        'provider_code' => 'SANDBOX_PSP',
        'idempotency_key' => 'idem-immutability-001',
    ]);
    $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/simulate-provider-callback", [
        'status' => 'SUCCEEDED',
    ]);

    $journal = LedgerJournal::firstOrFail();

    expect(fn () => $journal->update(['description' => 'Tampered description']))
        ->toThrow(DomainException::class);

    expect(fn () => $journal->delete())
        ->toThrow(DomainException::class);
});
