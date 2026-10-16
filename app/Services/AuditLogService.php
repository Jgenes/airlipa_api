<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    /**
     * Record an audit log entry with masked PII and request context.
     *
     * @param string $event
     * @param User|int|null $user
     * @param array<string, mixed> $metadata
     * @return AuditLog
     */
    public static function log(string $event, User|int|null $user = null, array $metadata = []): AuditLog
    {
        $userId = $user instanceof User ? $user->id : $user;
        $sanitizedMetadata = self::sanitizeMetadata($metadata);
        $ipAddress = Request::ip() ?? '127.0.0.1';
        $userAgent = Request::userAgent() ?? 'Unknown';
        $correlationId = Request::header('X-Correlation-Id')
            ?? ($sanitizedMetadata['correlation_id'] ?? null);
        $createdAt = now();

        $lastEntry = AuditLog::query()->orderByDesc('id')->first();
        $previousHash = $lastEntry?->entry_hash ?? str_repeat('0', 64);

        $canonicalPayload = json_encode([
            'previous_hash' => $previousHash,
            'user_id' => $userId,
            'event' => $event,
            'ip_address' => $ipAddress,
            'correlation_id' => $correlationId,
            'metadata' => $sanitizedMetadata,
            'created_at' => $createdAt->toIso8601String(),
        ]);

        $entryHash = hash('sha256', (string) $canonicalPayload);

        return AuditLog::create([
            'user_id' => $userId,
            'event' => $event,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'correlation_id' => is_string($correlationId) ? substr($correlationId, 0, 64) : null,
            'metadata' => $sanitizedMetadata,
            'previous_hash' => $previousHash,
            'entry_hash' => $entryHash,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * Verify tamper-evident hash chain across all audit logs (FR-030).
     *
     * @return array<string, mixed>
     */
    public static function verifyHashChain(): array
    {
        $logs = AuditLog::query()->orderBy('id')->get();
        $expectedPreviousHash = str_repeat('0', 64);
        $verifiedCount = 0;

        foreach ($logs as $log) {
            if ($log->previous_hash !== null && $log->previous_hash !== $expectedPreviousHash) {
                return [
                    'chain_valid' => false,
                    'verified_entries' => $verifiedCount,
                    'broken_at_id' => $log->id,
                    'reason' => 'Previous hash link mismatch.',
                ];
            }

            if ($log->entry_hash !== null) {
                $expectedPreviousHash = $log->entry_hash;
            }
            $verifiedCount++;
        }

        return [
            'chain_valid' => true,
            'verified_entries' => $verifiedCount,
            'head_hash' => $expectedPreviousHash,
        ];
    }

    /**
     * Clean and mask sensitive PII (passwords, tokens, OTPs, emails, phones).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitizeMetadata(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);

            // Block secrets completely
            if (in_array($lowerKey, [
                'password',
                'password_confirmation',
                'step_up_password',
                'token',
                'otp',
                'handover_otp',
                'handover_otp_hash',
                'code',
                'code_hash',
                'otp_session_token',
                'webhook_secret',
            ], true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeMetadata($value);
            } elseif (is_string($value)) {
                if (str_contains($lowerKey, 'email')) {
                    $sanitized[$key] = self::maskEmail($value);
                } elseif (str_contains($lowerKey, 'phone')) {
                    $sanitized[$key] = self::maskPhone($value);
                } elseif (str_contains($lowerKey, 'nida')) {
                    $sanitized[$key] = self::maskNida($value);
                } else {
                    $sanitized[$key] = $value;
                }
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***';
        }

        $name = $parts[0];
        $domain = $parts[1];

        $maskedName = mb_strlen($name) <= 2
            ? mb_substr($name, 0, 1) . '*'
            : mb_substr($name, 0, 1) . str_repeat('*', mb_strlen($name) - 2) . mb_substr($name, -1);

        return $maskedName . '@' . $domain;
    }

    public static function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len <= 5) {
            return '***';
        }

        return substr($phone, 0, 4) . str_repeat('*', max(0, $len - 7)) . substr($phone, -3);
    }

    public static function maskNida(string $nida): string
    {
        $len = strlen($nida);
        if ($len < 4) {
            return '********************';
        }

        return str_repeat('*', $len - 4) . substr($nida, -4);
    }
}
