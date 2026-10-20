<?php

use App\Enums\BusinessProfileStatus;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('merchant can register verified payout account with step-up password and settle released funds', function () {
    $merchant = User::factory()->kybVerified()->create();
    BusinessProfile::factory()->create([
        'user_id' => $merchant->id,
        'business_name' => 'Zanzibar Spice Exporters Ltd',
        'status' => BusinessProfileStatus::Verified,
    ]);

    $buyer = User::factory()->kycVerified()->create();

    // 1. Public trust profile is accessible and shows verified badge (M03)
    $trustResponse = $this->getJson("/api/v1/merchants/{$merchant->id}/trust-profile");
    $trustResponse->assertStatus(200)
        ->assertJsonPath('data.trading_eligibility.can_accept_protected_payments', true)
        ->assertJsonPath('data.trading_eligibility.verification_level', 'KYB_BUSINESS');

    // 2. Merchant registers payout account using step-up password (M14, BPA-DATA-03)
    Sanctum::actingAs($merchant, ['*']);

    $badStepUp = $this->postJson('/api/v1/payout-accounts', [
        'account_type' => 'MNO_WALLET',
        'provider_code' => 'MPESA_TZ',
        'account_name' => 'Zanzibar Spice Exporters Ltd',
        'account_number' => '+255754777888',
        'step_up_password' => 'WrongPassword!',
    ]);
    $badStepUp->assertStatus(403);

    $goodAccount = $this->postJson('/api/v1/payout-accounts', [
        'account_type' => 'MNO_WALLET',
        'provider_code' => 'MPESA_TZ',
        'account_name' => 'Zanzibar Spice Exporters Ltd',
        'account_number' => '+255754777888',
        'step_up_password' => 'Password#123456',
    ]);
    $goodAccount->assertStatus(201)
        ->assertJsonPath('data.verification_status', 'VERIFIED')
        ->assertJsonPath('data.is_default', true);

    // 3. Create, fund, and release a Protected Payment of 100,000.00 TZS (10,000,000 minor units)
    Sanctum::actingAs($buyer, ['*']);

    $agreement = $this->postJson('/api/v1/protected-payments', [
        'merchant_id' => $merchant->id,
        'principal_amount_minor' => 10000000,
        'title' => 'Organic Cloves 50kg Sack',
        'item_description' => 'Export grade Zanzibar cloves',
    ]);
    $protectedPaymentId = $agreement->json('data.agreement.protected_payment_id');
    $handoverOtp = $agreement->json('data.handover_otp');

    $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/pay", [
        'provider_code' => 'MPESA_TZ',
        'idempotency_key' => 'idem-payout-fund-001',
    ])->assertStatus(202);

    $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/simulate-provider-callback", [
        'status' => 'SUCCEEDED',
    ])->assertStatus(200);

    $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/release", [
        'handover_otp' => $handoverOtp,
        'idempotency_key' => 'idem-payout-release-001',
    ])->assertStatus(200);

    // 4. Merchant settles released payout to their verified M-Pesa account (M14)
    Sanctum::actingAs($merchant, ['*']);

    $settleResponse = $this->postJson("/api/v1/protected-payments/{$protectedPaymentId}/settle-payout", [
        'idempotency_key' => 'idem-settle-payout-001',
    ]);

    $settleResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'next_step' => 'completed',
            'data' => [
                'idempotent_replay' => false,
                'payout_instruction' => [
                    'provider_code' => 'MPESA_TZ',
                    'gross_released_minor' => 10000000,
                    'merchant_fee_minor' => 100000,
                    'merchant_tax_minor' => 18000,
                    'net_payout_minor' => 9882000,
                    'status' => 'SETTLED',
                ],
            ],
        ]);

    // 5. Merchant fetches bilingual Kiswahili notifications (M12) and user statement (M16)
    $notifResponse = $this->getJson('/api/v1/notifications?lang=sw');
    $notifResponse->assertStatus(200)
        ->assertJsonPath('data.language', 'sw');
    expect($notifResponse->json('data.notifications'))->not->toBeEmpty();

    $stmtResponse = $this->getJson('/api/v1/reports/statements');
    $stmtResponse->assertStatus(200)
        ->assertJsonPath('data.currency', 'TZS');
    expect($stmtResponse->json('data.payout_settlements'))->toHaveCount(1);
});

test('3-way reconciliation run, maker-checker dual control governance, support tickets, and audit hash chain verification work end-to-end', function () {
    $makerAdmin = User::factory()->admin()->create();
    $checkerAdmin = User::factory()->admin()->create();
    $customer = User::factory()->kycVerified()->create();

    // 1. Customer opens a support ticket (M13)
    Sanctum::actingAs($customer, ['*']);
    $ticketResponse = $this->postJson('/api/v1/support/tickets', [
        'category' => 'RELEASE_HELP',
        'priority' => 'HIGH',
        'subject' => 'How to enter handover OTP',
        'message' => 'Please guide me on confirming delivery.',
    ]);
    $ticketResponse->assertStatus(201)
        ->assertJsonPath('data.status', 'OPEN');

    $ticketNumber = $ticketResponse->json('data.ticket_number');

    // 2. Maker Admin initiates a Dual-Control Approval Request (M17, P-05, FR-036)
    Sanctum::actingAs($makerAdmin, ['*']);

    $approvalCreate = $this->postJson('/api/v1/admin/governance/approvals', [
        'action_type' => 'PROVIDER_STATUS_CHANGE',
        'target_type' => 'PAYMENT_PROVIDER',
        'target_identifier' => 'AIRTEL_TZ',
        'proposed_payload' => ['is_active' => false],
        'reason' => 'Temporarily pause Airtel Money rail for gateway certificate rotation.',
    ]);
    $approvalCreate->assertStatus(201);
    $approvalCode = $approvalCreate->json('data.approval_request.approval_code');

    // 3. Maker Admin attempting to approve their own request fails with 403 (Segregation of Duties P-05)
    $selfApprove = $this->postJson("/api/v1/admin/governance/approvals/{$approvalCode}/decide", [
        'decision' => 'APPROVE',
        'notes' => 'Approving my own request',
    ]);
    $selfApprove->assertStatus(403);

    // 4. Independent Checker Admin approves the request, executing the provider status change
    Sanctum::actingAs($checkerAdmin, ['*']);

    $checkerApprove = $this->postJson("/api/v1/admin/governance/approvals/{$approvalCode}/decide", [
        'decision' => 'APPROVE',
        'notes' => 'Verified gateway maintenance schedule, approved.',
    ]);
    $checkerApprove->assertStatus(200)
        ->assertJsonPath('data.approval_request.status', 'APPROVED');

    // 5. Execute a 3-way reconciliation run with an orphan provider line (M09)
    $reconRun = $this->postJson('/api/v1/admin/reconciliation/runs', [
        'provider_code' => 'MPESA_TZ',
        'statement_date' => '2026-10-16',
        'currency' => 'TZS',
        'statement_lines' => [
            [
                'provider_reference' => 'MPESA-ORPHAN-99999',
                'direction' => 'INFLOW',
                'amount_minor' => 7500000,
            ],
        ],
    ]);
    $reconRun->assertStatus(201)
        ->assertJsonPath('data.exception_lines', 1)
        ->assertJsonPath('data.status', 'COMPLETED_WITH_EXCEPTIONS');

    // 6. Verify Safeguarding Proof Pack & Tamper-Evident Audit Hash Chain (M16, M17, FR-030)
    $proofPack = $this->getJson('/api/v1/admin/reports/safeguarding-proof-pack');
    $proofPack->assertStatus(200)
        ->assertJsonPath('data.conservation_invariants.all_conservation_equations_hold', true)
        ->assertJsonPath('data.trial_balance.totals.zero_sum_balanced', true)
        ->assertJsonPath('data.audit_trail_integrity.chain_valid', true);

    $auditVerify = $this->getJson('/api/v1/admin/audit-logs/verify-chain');
    $auditVerify->assertStatus(200)
        ->assertJsonPath('data.chain_valid', true);
    expect($auditVerify->json('data.verified_entries'))->toBeGreaterThan(0);
    expect($auditVerify->headers->get('X-Correlation-Id'))->not->toBeEmpty();
});
