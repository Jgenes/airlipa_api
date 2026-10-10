<?php

use App\Enums\BusinessProfileStatus;
use App\Enums\KybStatus;
use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use App\Models\BusinessProfile;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('unauthenticated request to dashboard and profile is rejected with 401', function () {
    $this->getJson('/api/v1/dashboard')->assertStatus(401);
    $this->getJson('/api/v1/profile')->assertStatus(401);
});

test('individual user with unverified KYC cannot access dashboard or profile', function () {
    $user = User::factory()->active()->individual()->create([
        'kyc_status' => KycStatus::NotStarted,
    ]);

    Sanctum::actingAs($user, ['*']);

    $this->getJson('/api/v1/dashboard')->assertStatus(403)
        ->assertJson([
            'success' => false,
            'next_step' => 'kyc',
        ]);

    $this->getJson('/api/v1/profile')->assertStatus(403);
});

test('verified individual user can access dashboard with correct links object', function () {
    $user = User::factory()->kycVerified()->create();

    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('/api/v1/dashboard');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'welcome_message' => "Welcome back, {$user->first_name}!",
                'links' => [
                    'profile' => '/api/v1/profile',
                ],
            ],
        ]);
});

test('individual profile returns masked NIDA number and never leaks credentials', function () {
    $user = User::factory()->kycVerified()->create();

    KycVerification::create([
        'user_id' => $user->id,
        'nida_number' => '19900101123450000001',
        'nida_hash' => hash('sha256', '19900101123450000001'),
        'questions_asked' => ['date_of_birth', 'place_of_birth', 'mother_first_name'],
        'status' => KycVerificationStatus::Verified,
        'verified_at' => now(),
    ]);

    Sanctum::actingAs($user, ['*']);

    $response = $this->getJson('/api/v1/profile');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'nida_number_masked' => '****************0001',
            ],
        ]);

    // Ensure raw NIDA or passwords never present
    $json = $response->json();
    expect(json_encode($json))->not->toContain('19900101123450000001')
        ->and(json_encode($json))->not->toContain('password')
        ->and(json_encode($json))->not->toContain('remember_token');
});

test('business user with pending KYB sees verification notice in dashboard and profile', function () {
    $user = User::factory()->kybPending()->create();
    $profile = BusinessProfile::factory()->create([
        'user_id' => $user->id,
        'status' => BusinessProfileStatus::Pending,
    ]);

    Sanctum::actingAs($user, ['*']);

    $dashResponse = $this->getJson('/api/v1/dashboard');
    $dashResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'verification_notice' => 'Account verification pending',
            ],
        ]);

    $profResponse = $this->getJson('/api/v1/profile');
    $profResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'verification_notice' => 'Account verification pending',
                'business_profile' => [
                    'business_name' => $profile->business_name,
                ],
            ],
        ]);
});

test('business user with rejected KYB sees rejection notice and resubmit_kyb next_step', function () {
    $user = User::factory()->kybRejected()->create();
    BusinessProfile::factory()->create([
        'user_id' => $user->id,
        'status' => BusinessProfileStatus::Rejected,
        'rejection_reason' => 'Address verification document unreadable',
    ]);

    Sanctum::actingAs($user, ['*']);

    $dashResponse = $this->getJson('/api/v1/dashboard');
    $dashResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'next_step' => 'resubmit_kyb',
            'data' => [
                'verification_notice' => 'Account verification was rejected: Address verification document unreadable',
            ],
        ]);

    $profResponse = $this->getJson('/api/v1/profile');
    $profResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'next_step' => 'resubmit_kyb',
        ]);
});
