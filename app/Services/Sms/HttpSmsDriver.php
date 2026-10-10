<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpSmsDriver implements SmsServiceInterface
{
    public function __construct(
        protected ?string $apiUrl = null,
        protected ?string $apiKey = null,
        protected ?string $senderId = null
    ) {
        $this->apiUrl = $apiUrl ?? (string) config('services.sms.url', env('SMS_URL', ''));
        $this->apiKey = $apiKey ?? (string) config('services.sms.key', env('SMS_KEY', ''));
        $this->senderId = $senderId ?? (string) config('services.sms.sender', env('SMS_SENDER', 'AIRLIPA'));
    }

    /**
     * Send an SMS message via external HTTP API.
     */
    public function send(string $to, string $message): bool
    {
        if (empty($this->apiUrl) || empty($this->apiKey)) {
            Log::warning('[HttpSmsDriver] SMS provider credentials missing, falling back to log.');
            Log::info("[HttpSmsDriver] SMS to {$to}: {$message}");
            return true;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(10)
                ->retry(2, 200)
                ->post($this->apiUrl, [
                    'from' => $this->senderId,
                    'to' => $to,
                    'message' => $message,
                ]);

            if ($response->failed()) {
                Log::error('[HttpSmsDriver] SMS send failed: ' . $response->body());
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[HttpSmsDriver] Exception while sending SMS: ' . $e->getMessage());
            return false;
        }
    }
}
