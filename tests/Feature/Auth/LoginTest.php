<?php

use App\Enums\AccountStatus;
use App\Enums\KybStatus;
use App\Enums\KycStatus;
use App\Enums\OtpPurpose;
use App\Enums\UserType;
use App\Mail\LoginOtpMail;
use App\Models\BusinessProfile;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Sms\LogSmsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    LogSmsDriver::reset();
});

test('invalid credentials return generic message without user enumeration', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'WrongPassword#123!',
    ]);

    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
            'message' => 'Invalid credentials',
        ]);
});

test('unactivated user is blocked from logging in', function () {
    $user = User::factory()->individual()->create([
        'is_active' => false,
        'account_status' => AccountStatus::Pending,
        'password' => Hash::make('Password#123456'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Password#123456',
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
            'next_step' => 'verify_activation',
        ]);
});

test('individual user with unverified KYC is blocked from logging in', function () {
    $user = User::factory()->active()->individual()->create([
        'kyc_status' => KycStatus::NotStarted,
        'password' => Hash::make('Password#123456'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Password#123456',
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
            'next_step' => 'kyc',
        ]);
});

test('business user with unsubmitted KYB is blocked from logging in', function () {
    $user = User::factory()->active()->business()->create([
        'kyb_status' => KybStatus::NotStarted,
        'password' => Hash::make('Password#123456'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Password#123456',
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'success' => false,
            'next_step' => 'kyb',
        ]);
});

test('verified individual login dispatches OTP and returns session token', function () {
    Mail::fake();

    $user = User::factory()->kycVerified()->create([
        'password' => Hash::make('Password#123456'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Password#123456',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'next_step' => 'verify_login_otp',
        ])
        ->assertJsonStructure([
            'data' => [
                'otp_session_token',
                'expires_in_minutes',
            ],
        ]);

    Mail::assertQueued(LoginOtpMail::class);
    expect(LogSmsDriver::count())->toBe(1);

    // Verify OTP record exists
    expect(OtpCode::where('user_id', $user->id)->where('purpose', OtpPurpose::Login)->exists())->toBeTrue();
});

test('business user with pending KYB can login and sees verification notice', function () {
    $user = User::factory()->kybPending()->create([
        'password' => Hash::make('Password#123456'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Password#123456',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'next_step' => 'verify_login_otp',
            'data' => [
                'verification_notice' => 'Account verification pending',
            ],
        ]);
});

test('account locks after 5 consecutive failed login attempts', function () {
    $user = User::factory()->kycVerified()->create([
        'password' => Hash::make('CorrectPassword#123!'),
    ]);

    for ($i = 0; $i < 4; $i++) {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'WrongPassword',
        ]);
        $response->assertStatus(401);
    }

    // 5th attempt locks account
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'WrongPassword',
    ]);

    $response->assertStatus(423)
        ->assertJson([
            'success' => false,
        ]);

    $user->refresh();
    expect($user->isLocked())->toBeTrue();

    // Even with correct password, login is blocked while locked (423/429)
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'CorrectPassword#123!',
    ]);
    expect(in_array($response->status(), [423, 429], true))->toBeTrue();
});

test('user can verify login OTP and obtain full token', function () {
    $user = User::factory()->kycVerified()->create([
        'password' => Hash::make('Password#123456'),
    ]);

    // Perform initial login
    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Password#123456',
    ]);

    $sessionToken = $loginResponse->json('data.otp_session_token');

    // Retrieve the generated OTP code from the database
    $otp = OtpCode::where('user_id', $user->id)->where('purpose', OtpPurpose::Login)->latest()->first();

    // Verify OTP with custom mock or known code
    // Let's create an OTP with known code
    $knownCode = '654321';
    $otp->update(['code_hash' => Hash::make($knownCode)]);

    $verifyResponse = $this->postJson('/api/v1/auth/login/verify-otp', [
        'otp_session_token' => $sessionToken,
        'code' => $knownCode,
    ]);

    $verifyResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Login successful.',
            'next_step' => 'dashboard',
        ])
        ->assertJsonStructure([
            'data' => [
                'token',
                'token_type',
                'user',
            ],
        ]);

    $token = $verifyResponse->json('data.token');
    expect($token)->not->toBeEmpty();

    $user->refresh();
    expect($user->last_login_at)->not->toBeNull();
});

test('business user with rejected KYB receives resubmit_kyb next_step after login OTP verification', function () {
    $user = User::factory()->kybRejected()->create([
        'password' => Hash::make('Password#123456'),
    ]);

    BusinessProfile::factory()->create([
        'user_id' => $user->id,
        'status' => \App\Enums\BusinessProfileStatus::Rejected,
        'rejection_reason' => 'TIN certificate expired',
    ]);

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Password#123456',
    ]);

    $sessionToken = $loginResponse->json('data.otp_session_token');
    $otp = OtpCode::where('user_id', $user->id)->where('purpose', OtpPurpose::Login)->latest()->first();
    $knownCode = '112233';
    $otp->update(['code_hash' => Hash::make($knownCode)]);

    $verifyResponse = $this->postJson('/api/v1/auth/login/verify-otp', [
        'otp_session_token' => $sessionToken,
        'code' => $knownCode,
    ]);

    $verifyResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'next_step' => 'resubmit_kyb',
            'data' => [
                'verification_notice' => 'Account verification was rejected: TIN certificate expired',
            ],
        ]);
});

test('user can logout and logout-all', function () {
    $user = User::factory()->kycVerified()->create();
    $token1 = $user->createToken('token1');
    $token2 = $user->createToken('token2');

    // Logout current token
    $response = $this->withHeader('Authorization', 'Bearer ' . $token1->plainTextToken)
        ->postJson('/api/v1/auth/logout');

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    expect($user->tokens()->count())->toBe(1);

    // Logout-all tokens
    $responseAll = $this->withHeader('Authorization', 'Bearer ' . $token2->plainTextToken)
        ->postJson('/api/v1/auth/logout-all');

    $responseAll->assertStatus(200)
        ->assertJson(['success' => true]);

    expect($user->tokens()->count())->toBe(0);
});
