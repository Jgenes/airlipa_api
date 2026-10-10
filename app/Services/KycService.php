<?php

namespace App\Services;

use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use App\Models\KycVerification;
use App\Models\User;
use App\Services\Nida\NidaServiceInterface;

class KycService
{
    public function __construct(
        protected NidaServiceInterface $nidaService
    ) {}

    /**
     * Check whether the user is currently locked out of KYC verification.
     */
    public function isLockedOut(User $user): bool
    {
        $lockoutHours = (int) config('auth_otp.kyc.lockout_hours', 24);
        $maxAttempts = (int) config('auth_otp.kyc.max_failed_attempts', 3);

        $failedCount = KycVerification::where('user_id', $user->id)
            ->where('status', KycVerificationStatus::Failed)
            ->where('updated_at', '>=', now()->subHours($lockoutHours))
            ->count();

        return $failedCount >= $maxAttempts;
    }

    /**
     * Get remaining lockout seconds if user is locked.
     */
    public function getLockoutRemainingSeconds(User $user): ?int
    {
        $lockoutHours = (int) config('auth_otp.kyc.lockout_hours', 24);

        $latestFailed = KycVerification::where('user_id', $user->id)
            ->where('status', KycVerificationStatus::Failed)
            ->where('updated_at', '>=', now()->subHours($lockoutHours))
            ->latest('updated_at')
            ->first();

        if (!$latestFailed) {
            return null;
        }

        $unlockTime = $latestFailed->updated_at->addHours($lockoutHours);
        if ($unlockTime->isPast()) {
            return null;
        }

        return (int) $unlockTime->diffInSeconds(now());
    }

    /**
     * Initiate NIDA KYC verification.
     *
     * @return array{success: bool, message: string, data: array<string, mixed>|null, next_step: string|null, status: int}
     */
    public function initiate(User $user, string $nidaNumber): array
    {
        if ($user->kyc_status === KycStatus::Verified) {
            return [
                'success' => true,
                'message' => 'Your KYC is already verified.',
                'data' => null,
                'next_step' => 'login',
                'status' => 200,
            ];
        }

        if ($this->isLockedOut($user)) {
            $remaining = $this->getLockoutRemainingSeconds($user) ?? 86400;
            return [
                'success' => false,
                'message' => 'KYC verification is locked for 24 hours due to multiple failed attempts.',
                'data' => ['retry_after' => $remaining],
                'next_step' => null,
                'status' => 423,
            ];
        }

        $cleanNida = trim($nidaNumber);
        $record = $this->nidaService->findByNidaNumber($cleanNida);

        if (!$record) {
            AuditLogService::log('kyc_initiate_failed', $user, [
                'reason' => 'nida_not_found',
                'nida_number' => $cleanNida,
            ]);

            return [
                'success' => false,
                'message' => 'NIDA record not found with the provided identification number.',
                'data' => null,
                'next_step' => null,
                'status' => 404,
            ];
        }

        // Blind index unique check: ensure one NIDA number is only linked to one user
        $nidaHash = hash('sha256', $cleanNida);
        $existingLinked = KycVerification::where('nida_hash', $nidaHash)
            ->where('status', KycVerificationStatus::Verified)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($existingLinked) {
            AuditLogService::log('kyc_duplicate_nida_blocked', $user, ['nida_number' => $cleanNida]);

            return [
                'success' => false,
                'message' => 'This NIDA number is already registered and verified with another account.',
                'data' => null,
                'next_step' => null,
                'status' => 422,
            ];
        }

        // Loose name matching check
        if (!$this->nidaService->looseNameMatches($user->first_name, $user->last_name, $record)) {
            AuditLogService::log('kyc_name_mismatch', $user, [
                'registered_name' => "{$user->first_name} {$user->last_name}",
                'nida_name' => "{$record->first_name} {$record->last_name}",
            ]);

            return [
                'success' => false,
                'message' => 'Identity mismatch: The registered name does not match the NIDA record.',
                'data' => null,
                'next_step' => null,
                'status' => 422,
            ];
        }

        // Generate 3 random questions
        $questions = $this->nidaService->generateQuestions();
        $questionKeys = array_column($questions, 'key');

        $sessionExpiry = (int) config('auth_otp.kyc.session_expiry_minutes', 10);

        // Cancel previous pending sessions for this user
        KycVerification::where('user_id', $user->id)
            ->where('status', KycVerificationStatus::Pending)
            ->update(['status' => KycVerificationStatus::Failed]);

        $verification = KycVerification::create([
            'user_id' => $user->id,
            'nida_number' => $cleanNida, // encrypted by model cast
            'nida_hash' => $nidaHash,
            'questions_asked' => $questionKeys,
            'status' => KycVerificationStatus::Pending,
            'attempts' => 0,
            'expires_at' => now()->addMinutes($sessionExpiry),
        ]);

        AuditLogService::log('kyc_initiated', $user, [
            'kyc_session_id' => $verification->id,
            'nida_number' => $cleanNida,
        ]);

        return [
            'success' => true,
            'message' => 'KYC challenge initiated. Please answer all verification questions.',
            'data' => [
                'kyc_session_id' => $verification->id,
                'expires_in_minutes' => $sessionExpiry,
                'questions' => $questions,
            ],
            'next_step' => 'verify_kyc_answers',
            'status' => 200,
        ];
    }

    /**
     * Verify challenge answers.
     *
     * @param array<int, array{question_key: string, answer: string}> $submittedAnswers
     * @return array{success: bool, message: string, data: array<string, mixed>|null, next_step: string|null, status: int}
     */
    public function verify(User $user, int $kycSessionId, array $submittedAnswers): array
    {
        if ($this->isLockedOut($user)) {
            $remaining = $this->getLockoutRemainingSeconds($user) ?? 86400;
            return [
                'success' => false,
                'message' => 'KYC verification is locked for 24 hours due to multiple failed attempts.',
                'data' => ['retry_after' => $remaining],
                'next_step' => null,
                'status' => 423,
            ];
        }

        $session = KycVerification::where('id', $kycSessionId)
            ->where('user_id', $user->id)
            ->where('status', KycVerificationStatus::Pending)
            ->first();

        if (!$session) {
            return [
                'success' => false,
                'message' => 'Invalid or expired KYC session.',
                'data' => null,
                'next_step' => 'initiate_kyc',
                'status' => 404,
            ];
        }

        if ($session->isExpired()) {
            $session->update(['status' => KycVerificationStatus::Failed]);
            AuditLogService::log('kyc_session_expired', $user, ['kyc_session_id' => $session->id]);

            return [
                'success' => false,
                'message' => 'KYC verification session has expired. Please initiate a new challenge.',
                'data' => null,
                'next_step' => 'initiate_kyc',
                'status' => 422,
            ];
        }

        $record = $this->nidaService->findByNidaNumber($session->nida_number);
        if (!$record) {
            return [
                'success' => false,
                'message' => 'Underlying NIDA record is unavailable.',
                'data' => null,
                'next_step' => null,
                'status' => 500,
            ];
        }

        $isCorrect = $this->nidaService->verifyAnswers($record, $submittedAnswers, $session->questions_asked);

        if (!$isCorrect) {
            $session->increment('attempts');
            $session->update(['status' => KycVerificationStatus::Failed]);

            AuditLogService::log('kyc_verification_failed', $user, [
                'kyc_session_id' => $session->id,
                'attempts' => $session->attempts,
            ]);

            if ($this->isLockedOut($user)) {
                $user->forceFill(['kyc_status' => KycStatus::Failed])->save();
                $remaining = $this->getLockoutRemainingSeconds($user) ?? 86400;

                return [
                    'success' => false,
                    'message' => 'Verification failed. KYC verification is now locked for 24 hours due to 3 failed attempts.',
                    'data' => ['retry_after' => $remaining],
                    'next_step' => null,
                    'status' => 423,
                ];
            }

            return [
                'success' => false,
                'message' => 'One or more answers are incorrect. Please verify your details.',
                'data' => null,
                'next_step' => 'initiate_kyc',
                'status' => 422,
            ];
        }

        // All answers correct!
        $session->update([
            'status' => KycVerificationStatus::Verified,
            'verified_at' => now(),
        ]);

        $user->forceFill([
            'kyc_status' => KycStatus::Verified,
        ])->save();

        AuditLogService::log('kyc_verification_successful', $user, [
            'kyc_session_id' => $session->id,
            'nida_number' => $session->nida_number,
        ]);

        return [
            'success' => true,
            'message' => 'KYC verified. Please login.',
            'data' => null,
            'next_step' => 'login',
            'status' => 200,
        ];
    }
}
