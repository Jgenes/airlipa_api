<?php

use App\Enums\AccountStatus;
use App\Enums\UserType;
use App\Mail\ActivationOtpMail;
use App\Models\AuditLog;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Sms\LogSmsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    LogSmsDriver::reset();
});

test('user can register successfully as individual', function () {
    Mail::fake();

    $response = $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Daudi',
        'last_name' => 'Mwakasege',
        'email' => 'daudi@example.com',
        'phone' => '0712345678',
        'account_type' => 'individual',
        'password' => 'SecurePass#2026!',
        'password_confirmation' => 'SecurePass#2026!',
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'message' => 'Registration successful. Please verify your account with the OTP sent to your email and phone.',
            'data' => [
                'email' => 'daudi@example.com',
                'phone' => '+255712345678',
            ],
            'errors' => null,
            'next_step' => 'verify_activation',
        ]);

    $user = User::where('email', 'daudi@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->user_type)->toBe(UserType::Individual)
        ->and($user->account_status)->toBe(AccountStatus::Pending)
        ->and($user->is_active)->toBeFalse();

    // Verify OTP was created and hashed
    $otp = OtpCode::where('user_id', $user->id)->first();
    expect($otp)->not->toBeNull()
        ->and($otp->code_hash)->not->toBeEmpty();

    // Verify Mail was queued
    Mail::assertQueued(ActivationOtpMail::class, function ($mail) use ($user) {
        return $mail->user->id === $user->id;
    });

    // Verify SMS was dispatched
    expect(LogSmsDriver::count())->toBe(1);

    // Verify Audit log was created
    expect(AuditLog::where('user_id', $user->id)->where('event', 'user_registered')->exists())->toBeTrue();
});

test('user can register successfully as business', function () {
    Mail::fake();

    $response = $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Ali',
        'last_name' => 'Kibao',
        'email' => 'ali@business.tz',
        'phone' => '+255754112233',
        'account_type' => 'business',
        'password' => 'BusinessKey#9876!',
        'password_confirmation' => 'BusinessKey#9876!',
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'next_step' => 'verify_activation',
        ]);

    $user = User::where('email', 'ali@business.tz')->first();
    expect($user->user_type)->toBe(UserType::Business);
});

test('registration rejects invalid names with numbers or symbols', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'first_name' => 'John123',
        'last_name' => 'Doe$$',
        'email' => 'johndoe@example.com',
        'phone' => '0712345678',
        'account_type' => 'individual',
        'password' => 'Strong#Password123!',
        'password_confirmation' => 'Strong#Password123!',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['first_name', 'last_name']);
});

test('registration rejects password containing user name or email', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Baraka',
        'last_name' => 'Mwita',
        'email' => 'baraka@example.com',
        'phone' => '0712345678',
        'account_type' => 'individual',
        'password' => 'Baraka#Pass1234!',
        'password_confirmation' => 'Baraka#Pass1234!',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('registration rejects weak password', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Baraka',
        'last_name' => 'Mwita',
        'email' => 'mwita@example.com',
        'phone' => '0712345678',
        'account_type' => 'individual',
        'password' => 'simple',
        'password_confirmation' => 'simple',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

test('registration normalizes Tanzanian local phone numbers to E164 and checks uniqueness', function () {
    User::factory()->create([
        'phone' => '+255711223344',
    ]);

    $response = $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Juma',
        'last_name' => 'Selemani',
        'email' => 'juma@example.com',
        'phone' => '0711223344', // Same phone in local format
        'account_type' => 'individual',
        'password' => 'Complex#Pass2026!',
        'password_confirmation' => 'Complex#Pass2026!',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['phone']);
});
