<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('security headers middleware attaches all required security headers', function () {
    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => 'test@example.com',
    ]);

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('force json middleware ensures API returns JSON formatted envelopes even on non-accept requests', function () {
    $response = $this->get('/api/v1/profile'); // Plain GET without Accept header

    $response->assertHeader('Content-Type', 'application/json')
        ->assertStatus(401)
        ->assertJson([
            'success' => false,
            'message' => 'Unauthenticated.',
            'next_step' => 'login',
        ]);
});

test('audit log masks sensitive PII and excludes secrets', function () {
    $user = User::factory()->create([
        'email' => 'john.doe@example.com',
        'phone' => '+255712345678',
    ]);

    $log = AuditLogService::log('security_test_event', $user, [
        'email' => 'john.doe@example.com',
        'phone' => '+255712345678',
        'nida' => '19900101123450000001',
        'password' => 'SuperSecret#123!',
        'token' => 'plain_text_token_12345',
        'code' => '999888',
        'ip_address' => '192.168.1.1',
    ]);

    $metadata = $log->metadata;

    // Verify secrets are completely excluded
    expect($metadata)->not->toHaveKey('password')
        ->and($metadata)->not->toHaveKey('token')
        ->and($metadata)->not->toHaveKey('code');

    // Verify PII is masked
    expect($metadata['email'])->not->toBe('john.doe@example.com')
        ->and(str_contains($metadata['email'], '*'))->toBeTrue()
        ->and($metadata['phone'])->not->toBe('+255712345678')
        ->and(str_contains($metadata['phone'], '*'))->toBeTrue()
        ->and($metadata['nida'])->not->toBe('19900101123450000001')
        ->and(str_contains($metadata['nida'], '*'))->toBeTrue();
});

test('a failed audit log write does not break the request that triggered it', function () {
    $user = User::factory()->create();

    Log::spy();

    // Metadata that cannot be JSON encoded forces the insert to fail before it
    // reaches the database — the same class of failure a stale `audit_logs`
    // schema produced on the login endpoint.
    $log = AuditLogService::log('login_success', $user, ['note' => "\xB1\x31"]);

    expect($log)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);

    Log::shouldHaveReceived('critical')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Audit log write failed')
            && $context['event'] === 'login_success'
            && $context['user_id'] === $user->id);
});
