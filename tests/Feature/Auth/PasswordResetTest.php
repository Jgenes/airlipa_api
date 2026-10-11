<?php

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('forgot password returns generic response and sends email for valid user', function () {
    Mail::fake();

    $user = User::factory()->kycVerified()->create();

    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => $user->email,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'If your email is registered, you will receive password reset instructions shortly.',
        ]);

    Mail::assertSent(PasswordResetMail::class);

    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeTrue();
});

test('forgot password returns generic response for non-existent email', function () {
    Mail::fake();

    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'doesnotexist@example.com',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    Mail::assertNothingSent();
});

test('user can reset password with valid token and all previous tokens are revoked', function () {
    $user = User::factory()->kycVerified()->create([
        'password' => Hash::make('OldPassword#123!'),
    ]);

    $token1 = $user->createToken('device1');

    $plainToken = Str::random(64);
    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make($plainToken),
        'created_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/auth/reset-password', [
        'email' => $user->email,
        'token' => $plainToken,
        'password' => 'BrandNewPassword#2026!',
        'password_confirmation' => 'BrandNewPassword#2026!',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'next_step' => 'login',
        ]);

    // Verify token was deleted
    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();

    // Verify user tokens were revoked
    expect($user->tokens()->count())->toBe(0);

    // Verify password was changed
    $user->refresh();
    expect(Hash::check('BrandNewPassword#2026!', $user->password))->toBeTrue();
});

test('reset password fails with expired token', function () {
    $user = User::factory()->kycVerified()->create();

    $plainToken = Str::random(64);
    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make($plainToken),
        'created_at' => now()->subMinutes(65), // expired > 60 mins
    ]);

    $response = $this->postJson('/api/v1/auth/reset-password', [
        'email' => $user->email,
        'token' => $plainToken,
        'password' => 'BrandNewPassword#2026!',
        'password_confirmation' => 'BrandNewPassword#2026!',
    ]);

    $response->assertStatus(400)
        ->assertJson([
            'success' => false,
            'message' => 'Password reset token has expired. Please request a new one.',
        ]);
});
