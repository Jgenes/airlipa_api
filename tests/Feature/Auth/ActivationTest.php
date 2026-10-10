<?php

use App\Enums\AccountStatus;
use App\Enums\OtpPurpose;
use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use App\Services\Sms\LogSmsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    LogSmsDriver::reset();
});

test('individual user can activate account with valid OTP and receive kyc scoped token', function () {
    $user = User::factory()->individual()->create([
        'is_active' => false,
        'account_status' => AccountStatus::Pending,
        'email_verified_at' => null,
        'phone_verified_at' => null,
    ]);

    $code = '123456';
    OtpCode::create([
        'user_id' => $user->id,
        'purpose' => OtpPurpose::Activation,
        'code_hash' => Hash::make($code),
        'channel' => \App\Enums\OtpChannel::Email,
        'expires_at' => now()->addMinutes(10),
        'attempts' => 0,
        'max_attempts' => 5,
    ]);

    $response = $this->postJson('/api/v1/auth/activate', [
        'identifier' => $user->email,
        'code' => $code,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Account successfully activated. Please complete identity verification.',
            'next_step' => 'kyc',
        ]);

    $user->refresh();
    expect($user->is_active)->toBeTrue()
        ->and($user->account_status)->toBe(AccountStatus::Active)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->phone_verified_at)->not->toBeNull();

    // Verify token ability is scoped to kyc
    $token = $user->tokens()->first();
    expect($token)->not->toBeNull()
        ->and($token->can('kyc'))->toBeTrue()
        ->and($token->can('*'))->toBeFalse();

    // Verify OTP was consumed
    $otp = OtpCode::where('user_id', $user->id)->first();
    expect($otp->consumed_at)->not->toBeNull();

    // Verify Audit log
    expect(AuditLog::where('user_id', $user->id)->where('event', 'account_activated')->exists())->toBeTrue();
});

test('business user activation returns next_step kyb', function () {
    $user = User::factory()->business()->create([
        'is_active' => false,
        'account_status' => AccountStatus::Pending,
    ]);

    $code = '654321';
    OtpCode::create([
        'user_id' => $user->id,
        'purpose' => OtpPurpose::Activation,
        'code_hash' => Hash::make($code),
        'channel' => \App\Enums\OtpChannel::Email,
        'expires_at' => now()->addMinutes(10),
        'attempts' => 0,
        'max_attempts' => 5,
    ]);

    $response = $this->postJson('/api/v1/auth/activate', [
        'phone' => $user->phone,
        'code' => $code,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'next_step' => 'kyb',
        ]);
});

test('activation fails with incorrect code and tracks attempts', function () {
    $user = User::factory()->create([
        'is_active' => false,
    ]);

    OtpCode::create([
        'user_id' => $user->id,
        'purpose' => OtpPurpose::Activation,
        'code_hash' => Hash::make('111111'),
        'channel' => \App\Enums\OtpChannel::Email,
        'expires_at' => now()->addMinutes(10),
        'attempts' => 0,
        'max_attempts' => 5,
    ]);

    $response = $this->postJson('/api/v1/auth/activate', [
        'email' => $user->email,
        'code' => '999999',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Invalid verification code.',
        ]);

    $otp = OtpCode::where('user_id', $user->id)->first();
    expect($otp->attempts)->toBe(1);
});

test('activation fails when OTP is expired', function () {
    $user = User::factory()->create(['is_active' => false]);

    OtpCode::create([
        'user_id' => $user->id,
        'purpose' => OtpPurpose::Activation,
        'code_hash' => Hash::make('111111'),
        'channel' => \App\Enums\OtpChannel::Email,
        'expires_at' => now()->subMinute(),
        'attempts' => 0,
        'max_attempts' => 5,
    ]);

    $response = $this->postJson('/api/v1/auth/activate', [
        'email' => $user->email,
        'code' => '111111',
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'message' => 'Verification code has expired. Please request a new one.',
        ]);
});

test('activation locks and invalidates OTP after 5 failed attempts', function () {
    $user = User::factory()->create(['is_active' => false]);

    $otp = OtpCode::create([
        'user_id' => $user->id,
        'purpose' => OtpPurpose::Activation,
        'code_hash' => Hash::make('111111'),
        'channel' => \App\Enums\OtpChannel::Email,
        'expires_at' => now()->addMinutes(10),
        'attempts' => 4,
        'max_attempts' => 5,
    ]);

    $response = $this->postJson('/api/v1/auth/activate', [
        'email' => $user->email,
        'code' => '222222',
    ]);

    $response->assertStatus(422);

    $otp->refresh();
    expect($otp->attempts)->toBe(5)
        ->and($otp->consumed_at)->not->toBeNull();
});

test('resend activation OTP enforces cooldown', function () {
    $user = User::factory()->create(['is_active' => false]);

    OtpCode::create([
        'user_id' => $user->id,
        'purpose' => OtpPurpose::Activation,
        'code_hash' => Hash::make('111111'),
        'channel' => \App\Enums\OtpChannel::Email,
        'expires_at' => now()->addMinutes(10),
        'attempts' => 0,
        'created_at' => now()->subSeconds(20), // 20s ago, cooldown is 60s
    ]);

    $response = $this->postJson('/api/v1/auth/activation/resend', [
        'email' => $user->email,
    ]);

    $response->assertStatus(429)
        ->assertJson([
            'success' => false,
        ]);
});
