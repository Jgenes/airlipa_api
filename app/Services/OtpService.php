<?php

namespace App\Services;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Mail\ActivationOtpMail;
use App\Mail\LoginOtpMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Sms\SmsServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Request;

class OtpService
{
    public function __construct(
        protected SmsServiceInterface $smsService
    ) {}

    /**
     * Generate and dispatch a new OTP code for the user via Email and SMS.
     *
     * @param User $user
     * @param OtpPurpose $purpose
     * @return array{otp: OtpCode, code: string}
     */
    public function generateAndSend(User $user, OtpPurpose $purpose): array
    {
        $configKey = $purpose === OtpPurpose::Activation ? 'activation' : 'login';
        $expiryMinutes = (int) config("auth_otp.{$configKey}.expiry_minutes", $purpose === OtpPurpose::Activation ? 10 : 5);
        $maxAttempts = (int) config("auth_otp.{$configKey}.max_attempts", 5);

        // Invalidate older unconsumed OTPs for this user and purpose
        $this->invalidateExistingOtps($user, $purpose);

        // Cryptographically secure numeric code
        $code = (string) random_int(100000, 999999);
        $codeHash = Hash::make($code);

        $otp = OtpCode::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => $codeHash,
            'channel' => OtpChannel::Email, // primary channel recorded; both dispatched
            'expires_at' => now()->addMinutes($expiryMinutes),
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'consumed_at' => null,
            'ip_address' => Request::ip() ?? '127.0.0.1',
            'user_agent' => Request::userAgent() ?? 'Unknown',
        ]);

        // Dispatch notifications safely
        $this->dispatchNotifications($user, $purpose, $code, $expiryMinutes);

        AuditLogService::log(
            event: "otp_sent_{$purpose->value}",
            user: $user,
            metadata: ['channel' => 'email_and_sms', 'expires_at' => $otp->expires_at->toIso8601String()]
        );

        return [
            'otp' => $otp,
            'code' => $code,
        ];
    }

    /**
     * Invalidate any previous unconsumed OTPs for this user and purpose.
     */
    public function invalidateExistingOtps(User $user, OtpPurpose $purpose): void
    {
        OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => now(), // marked consumed/invalidated
            ]);
    }

    /**
     * Check if user is eligible for a resend based on cooldown and hourly limit.
     *
     * @return array{allowed: bool, message: string|null, retry_after: int|null}
     */
    public function checkResendEligibility(User $user, OtpPurpose $purpose): array
    {
        $configKey = $purpose === OtpPurpose::Activation ? 'activation' : 'login';
        $cooldownSeconds = (int) config("auth_otp.{$configKey}.resend_cooldown_seconds", 60);
        $maxResendsPerHour = (int) config("auth_otp.{$configKey}.max_resends_per_hour", 3);

        $latestOtp = OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->latest()
            ->first();

        if ($latestOtp) {
            $secondsSinceLast = (int) $latestOtp->created_at->diffInSeconds(now());
            if ($secondsSinceLast < $cooldownSeconds) {
                $retryAfter = $cooldownSeconds - $secondsSinceLast;
                return [
                    'allowed' => false,
                    'message' => "Please wait {$retryAfter} seconds before requesting a new code.",
                    'retry_after' => $retryAfter,
                ];
            }
        }

        $resendsPastHour = OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($resendsPastHour >= $maxResendsPerHour) {
            return [
                'allowed' => false,
                'message' => 'Maximum resend limit reached. Please try again in one hour.',
                'retry_after' => 3600,
            ];
        }

        return ['allowed' => true, 'message' => null, 'retry_after' => null];
    }

    /**
     * Verify an OTP code for the user and purpose.
     *
     * @return array{success: bool, message: string, otp: OtpCode|null}
     */
    public function verify(User $user, OtpPurpose $purpose, string $code): array
    {
        $otp = OtpCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest()
            ->first();

        if (!$otp) {
            AuditLogService::log("otp_failed_{$purpose->value}", $user, ['reason' => 'no_active_otp']);
            return [
                'success' => false,
                'message' => 'No active verification code found. Please request a new one.',
                'otp' => null,
            ];
        }

        if ($otp->isExpired()) {
            AuditLogService::log("otp_failed_{$purpose->value}", $user, ['reason' => 'expired']);
            return [
                'success' => false,
                'message' => 'Verification code has expired. Please request a new one.',
                'otp' => $otp,
            ];
        }

        if ($otp->hasExceededAttempts()) {
            AuditLogService::log("otp_failed_{$purpose->value}", $user, ['reason' => 'max_attempts_exceeded']);
            return [
                'success' => false,
                'message' => 'Maximum attempts exceeded. This code is now invalid. Please request a new one.',
                'otp' => $otp,
            ];
        }

        // Verify hash
        if (!Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            if ($otp->attempts >= $otp->max_attempts) {
                $otp->update(['consumed_at' => now()]);
            }

            AuditLogService::log("otp_failed_{$purpose->value}", $user, [
                'reason' => 'invalid_code',
                'attempts' => $otp->attempts,
            ]);

            return [
                'success' => false,
                'message' => 'Invalid verification code.',
                'otp' => $otp,
            ];
        }

        // Mark consumed on success
        $otp->update(['consumed_at' => now()]);

        AuditLogService::log("otp_verified_{$purpose->value}", $user, ['otp_id' => $otp->id]);

        return [
            'success' => true,
            'message' => 'Code verified successfully.',
            'otp' => $otp,
        ];
    }

    /**
     * Dispatch OTP via email and SMS gracefully.
     */
    protected function dispatchNotifications(User $user, OtpPurpose $purpose, string $code, int $expiryMinutes): void
    {
        // 1. Email
        try {
            if ($purpose === OtpPurpose::Activation) {
                Mail::to($user->email)->queue(new ActivationOtpMail($user, $code, $expiryMinutes));
            } else {
                Mail::to($user->email)->queue(new LoginOtpMail($user, $code, $expiryMinutes));
            }
        } catch (\Throwable $e) {
            Log::error("[OtpService] Failed to queue OTP email: " . $e->getMessage());
        }

        // 2. SMS
        try {
            $smsText = $purpose === OtpPurpose::Activation
                ? "Your Airlipa activation code is {$code}. It expires in {$expiryMinutes} minutes."
                : "Your Airlipa login code is {$code}. It expires in {$expiryMinutes} minutes.";

            $this->smsService->send($user->phone, $smsText);
        } catch (\Throwable $e) {
            Log::error("[OtpService] Failed to dispatch OTP SMS: " . $e->getMessage());
        }
    }
}
