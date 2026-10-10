<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

class LogSmsDriver implements SmsServiceInterface
{
    /**
     * Stored messages in-memory for testing and assertions.
     *
     * @var array<int, array{to: string, message: string, timestamp: string}>
     */
    public static array $sentMessages = [];

    /**
     * Send SMS by logging it.
     */
    public function send(string $to, string $message): bool
    {
        $entry = [
            'to' => $to,
            'message' => $message,
            'timestamp' => now()->toIso8601String(),
        ];

        self::$sentMessages[] = $entry;

        Log::info('[SMS LOG DRIVER] SMS dispatched successfully', $entry);

        return true;
    }

    /**
     * Reset in-memory sent messages.
     */
    public static function reset(): void
    {
        self::$sentMessages = [];
    }

    /**
     * Get the count of sent messages.
     */
    public static function count(): int
    {
        return count(self::$sentMessages);
    }
}
