<?php

use App\Enums\AccountStatus;
use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use App\Models\KycVerification;
use App\Models\NidaMockRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('nida kyc initiate requires exactly 20 digits', function () {
    $user = User::factory()->active()->individual()->create();

    \Laravel\Sanctum\Sanctum::actingAs($user, ['kyc']);

    $response = $this->postJson('/api/v1/kyc/nida/initiate', [
            'nida_number' => '12345',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['nida_number']);
});

test('nida kyc initiate fails if nida record does not exist', function () {
    $user = User::factory()->active()->individual()->create();

    \Laravel\Sanctum\Sanctum::actingAs($user, ['kyc']);

    $response = $this->postJson('/api/v1/kyc/nida/initiate', [
            'nida_number' => '99999999999999999999',
        ]);

    $response->assertStatus(404)
        ->assertJson([
            'success' => false,
            'message' => 'NIDA record not found with the provided identification number.',
        ]);
});

test('nida kyc initiate fails if name does not match record', function () {
    $record = NidaMockRecord::factory()->create([
        'nida_number' => '19900101123450000001',
        'first_name' => 'Juma',
        'last_name' => 'Hamisi',
    ]);

    // User with completely different name
    $user = User::factory()->active()->individual()->create([
        'first_name' => 'Charles',
        'last_name' => 'Dickens',
    ]);

    \Laravel\Sanctum\Sanctum::actingAs($user, ['kyc']);

    $response = $this->postJson('/api/v1/kyc/nida/initiate', [
            'nida_number' => $record->nida_number,
        ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Identity mismatch: The registered name does not match the NIDA record.',
        ]);
});

test('nida kyc initiate succeeds with loose name matching and returns 3 challenge questions without answers', function () {
    $record = NidaMockRecord::factory()->create([
        'nida_number' => '19900101123450000001',
        'first_name' => 'Juma',
        'last_name' => 'Hamisi',
        'date_of_birth' => '1990-01-01',
        'place_of_birth' => 'Dar es Salaam',
        'mother_first_name' => 'Fatuma',
        'region' => 'Dar es Salaam',
        'district' => 'Ilala',
    ]);

    $user = User::factory()->active()->individual()->create([
        'first_name' => 'juma', // lowercase loose match
        'last_name' => 'hamisi',
    ]);

    \Laravel\Sanctum\Sanctum::actingAs($user, ['kyc']);

    $response = $this->postJson('/api/v1/kyc/nida/initiate', [
            'nida_number' => $record->nida_number,
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'next_step' => 'verify_kyc_answers',
        ])
        ->assertJsonStructure([
            'data' => [
                'kyc_session_id',
                'expires_in_minutes',
                'questions' => [
                    '*' => ['key', 'question'],
                ],
            ],
        ]);

    $questions = $response->json('data.questions');
    expect(count($questions))->toBe(3);

    // Ensure questions do not contain answers
    foreach ($questions as $q) {
        expect($q)->not->toHaveKey('answer');
    }
});

test('nida kyc blocks linking already verified nida number to another account', function () {
    $record = NidaMockRecord::factory()->create([
        'nida_number' => '19900101123450000001',
        'first_name' => 'Juma',
        'last_name' => 'Hamisi',
    ]);

    $user1 = User::factory()->active()->individual()->create([
        'first_name' => 'Juma',
        'last_name' => 'Hamisi',
    ]);

    // User1 already verified this NIDA
    KycVerification::create([
        'user_id' => $user1->id,
        'nida_number' => $record->nida_number,
        'nida_hash' => hash('sha256', $record->nida_number),
        'questions_asked' => ['date_of_birth', 'place_of_birth', 'mother_first_name'],
        'status' => KycVerificationStatus::Verified,
        'verified_at' => now(),
    ]);

    // User2 attempts to use same NIDA
    $user2 = User::factory()->active()->individual()->create([
        'first_name' => 'Juma',
        'last_name' => 'Hamisi',
    ]);

    \Laravel\Sanctum\Sanctum::actingAs($user2, ['kyc']);

    $response = $this->postJson('/api/v1/kyc/nida/initiate', [
        'nida_number' => $record->nida_number,
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'This NIDA number is already registered and verified with another account.',
        ]);
});

test('nida kyc verification succeeds with case-insensitive answers and sets verified status', function () {
    $record = NidaMockRecord::factory()->create([
        'nida_number' => '19900101123450000001',
        'first_name' => 'Juma',
        'last_name' => 'Hamisi',
        'date_of_birth' => '1990-01-01',
        'place_of_birth' => 'Dar es Salaam',
        'mother_first_name' => 'Fatuma',
    ]);

    $user = User::factory()->active()->individual()->create([
        'first_name' => 'Juma',
        'last_name' => 'Hamisi',
        'kyc_status' => KycStatus::NotStarted,
    ]);

    $session = KycVerification::create([
        'user_id' => $user->id,
        'nida_number' => $record->nida_number,
        'nida_hash' => hash('sha256', $record->nida_number),
        'questions_asked' => ['date_of_birth', 'place_of_birth', 'mother_first_name'],
        'status' => KycVerificationStatus::Pending,
        'expires_at' => now()->addMinutes(10),
    ]);

    \Laravel\Sanctum\Sanctum::actingAs($user, ['kyc']);

    $response = $this->postJson('/api/v1/kyc/nida/verify', [
            'kyc_session_id' => $session->id,
            'answers' => [
                ['question_key' => 'date_of_birth', 'answer' => '1990-01-01'],
                ['question_key' => 'place_of_birth', 'answer' => '  dar es salaam  '], // whitespace and case test
                ['question_key' => 'mother_first_name', 'answer' => 'FATUMA'],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'KYC verified. Please login.',
            'next_step' => 'login',
        ]);

    $user->refresh();
    expect($user->kyc_status)->toBe(KycStatus::Verified);

    $session->refresh();
    expect($session->status)->toBe(KycVerificationStatus::Verified)
        ->and($session->verified_at)->not->toBeNull();

    // Verify NIDA number was encrypted in database
    $rawNidaInDb = \Illuminate\Support\Facades\DB::table('kyc_verifications')
        ->where('id', $session->id)
        ->value('nida_number');
    expect($rawNidaInDb)->not->toBe('19900101123450000001'); // Ciphertext, not plain
});

test('nida kyc verification locks account for 24h after 3 failed attempts', function () {
    $record = NidaMockRecord::factory()->create([
        'nida_number' => '19900101123450000001',
        'first_name' => 'Juma',
        'last_name' => 'Hamisi',
    ]);

    $user = User::factory()->active()->individual()->create([
        'first_name' => 'Juma',
        'last_name' => 'Hamisi',
    ]);

    // 2 failed attempts in past 24h
    for ($i = 0; $i < 2; $i++) {
        KycVerification::create([
            'user_id' => $user->id,
            'nida_number' => $record->nida_number,
            'nida_hash' => hash('sha256', $record->nida_number . $i),
            'questions_asked' => ['date_of_birth', 'place_of_birth', 'mother_first_name'],
            'status' => KycVerificationStatus::Failed,
            'attempts' => 1,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
    }

    // 3rd attempt
    $session = KycVerification::create([
        'user_id' => $user->id,
        'nida_number' => $record->nida_number,
        'nida_hash' => hash('sha256', $record->nida_number),
        'questions_asked' => ['date_of_birth', 'place_of_birth', 'mother_first_name'],
        'status' => KycVerificationStatus::Pending,
        'expires_at' => now()->addMinutes(10),
    ]);

    \Laravel\Sanctum\Sanctum::actingAs($user, ['kyc']);

    $response = $this->postJson('/api/v1/kyc/nida/verify', [
            'kyc_session_id' => $session->id,
            'answers' => [
                ['question_key' => 'date_of_birth', 'answer' => 'wrong'],
                ['question_key' => 'place_of_birth', 'answer' => 'wrong'],
                ['question_key' => 'mother_first_name', 'answer' => 'wrong'],
            ],
        ]);

    $response->assertStatus(423)
        ->assertJson([
            'success' => false,
        ]);

    // Further attempts are locked
    \Laravel\Sanctum\Sanctum::actingAs($user, ['kyc']);
    $initiateResponse = $this->postJson('/api/v1/kyc/nida/initiate', [
        'nida_number' => $record->nida_number,
    ]);
    $initiateResponse->assertStatus(423);
});

test('nida kyc re-initiating a challenge does not consume the failed attempt lockout budget', function () {
    $record = NidaMockRecord::factory()->create([
        'nida_number' => '19900101123450000001',
        'first_name' => 'Juma',
        'last_name' => 'Hamisi',
    ]);

    $user = User::factory()->active()->individual()->create([
        'first_name' => 'Juma',
        'last_name' => 'Hamisi',
    ]);

    \Laravel\Sanctum\Sanctum::actingAs($user, ['kyc']);

    // 4 consecutive initiations without ever answering a question. This is one more than
    // the 3-strike lockout threshold, so marking superseded sessions as Failed would have
    // locked this account out on the final request.
    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/v1/kyc/nida/initiate', [
            'nida_number' => $record->nida_number,
        ])->assertStatus(200);
    }

    expect(
        KycVerification::where('user_id', $user->id)
            ->where('status', KycVerificationStatus::Failed)
            ->count()
    )->toBe(0)
        ->and(
            KycVerification::where('user_id', $user->id)
                ->where('status', KycVerificationStatus::Cancelled)
                ->count()
        )->toBe(3)
        ->and(
            KycVerification::where('user_id', $user->id)
                ->where('status', KycVerificationStatus::Pending)
                ->count()
        )->toBe(1);
});

test('nida kyc expired session is not counted as a failed attempt', function () {
    $record = NidaMockRecord::factory()->create([
        'nida_number' => '19900101123450000001',
        'first_name' => 'Juma',
        'last_name' => 'Hamisi',
    ]);

    $user = User::factory()->active()->individual()->create([
        'first_name' => 'Juma',
        'last_name' => 'Hamisi',
    ]);

    // Three previously elapsed sessions — already at the lockout threshold if expiry
    // were treated as a failure.
    for ($i = 0; $i < 3; $i++) {
        KycVerification::create([
            'user_id' => $user->id,
            'nida_number' => $record->nida_number,
            'nida_hash' => hash('sha256', $record->nida_number . $i),
            'questions_asked' => ['date_of_birth', 'place_of_birth', 'mother_first_name'],
            'status' => KycVerificationStatus::Expired,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
    }

    $expiredSession = KycVerification::create([
        'user_id' => $user->id,
        'nida_number' => $record->nida_number,
        'nida_hash' => hash('sha256', $record->nida_number),
        'questions_asked' => ['date_of_birth', 'place_of_birth', 'mother_first_name'],
        'status' => KycVerificationStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);

    \Laravel\Sanctum\Sanctum::actingAs($user, ['kyc']);

    $response = $this->postJson('/api/v1/kyc/nida/verify', [
        'kyc_session_id' => $expiredSession->id,
        'answers' => [
            ['question_key' => 'date_of_birth', 'answer' => '1990-01-01'],
            ['question_key' => 'place_of_birth', 'answer' => 'Dar es Salaam'],
            ['question_key' => 'mother_first_name', 'answer' => 'Fatuma'],
        ],
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'KYC verification session has expired. Please initiate a new challenge.',
            'next_step' => 'initiate_kyc',
        ]);

    $expiredSession->refresh();
    expect($expiredSession->status)->toBe(KycVerificationStatus::Expired);

    // The account is still able to start a fresh challenge rather than being locked out.
    $this->postJson('/api/v1/kyc/nida/initiate', [
        'nida_number' => $record->nida_number,
    ])->assertStatus(200);
});
