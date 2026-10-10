<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\KybStatus;
use App\Enums\KycStatus;
use App\Enums\OtpPurpose;
use App\Enums\UserType;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Rules\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(
        protected OtpService $otpService
    ) {}

    /**
     * Register a new user.
     *
     * @param array<string, mixed> $data
     * @return array{user: User, next_step: string}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            // Map account_type to UserType enum
            $accountTypeRaw = strtolower((string) ($data['account_type'] ?? 'individual'));
            $userType = $accountTypeRaw === 'business' ? UserType::Business : UserType::Individual;

            $normalizedPhone = PhoneNumber::normalize($data['phone']);

            $user = User::create([
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'phone' => $normalizedPhone,
                'email' => strtolower(trim($data['email'])),
                'user_type' => $userType,
                'password' => $data['password'], // cast handles hashing or Hash::make
            ]);

            // Set explicit security defaults
            $user->account_status = AccountStatus::Pending;
            $user->is_active = false;
            $user->kyc_status = KycStatus::NotStarted;
            $user->kyb_status = KybStatus::NotStarted;
            $user->save();

            // Generate and send 6-digit activation OTP
            $this->otpService->generateAndSend($user, OtpPurpose::Activation);

            AuditLogService::log(
                event: 'user_registered',
                user: $user,
                metadata: [
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'user_type' => $user->user_type->value,
                ]
            );

            return [
                'user' => $user,
                'next_step' => 'verify_activation',
            ];
        });
    }

    /**
     * Find a user by either email or normalized phone number.
     */
    public function findUserByIdentifier(string $identifier): ?User
    {
        $clean = trim($identifier);

        if (filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            return User::where('email', strtolower($clean))->first();
        }

        $normalized = PhoneNumber::normalize($clean);
        return User::where('phone', $normalized)->first();
    }

    /**
     * Activate user account via OTP.
     *
     * @return array{success: bool, message: string, data: array<string, mixed>|null, next_step: string|null, status: int}
     */
    public function activate(string $identifier, string $code): array
    {
        $user = $this->findUserByIdentifier($identifier);

        if (!$user) {
            return [
                'success' => false,
                'message' => 'Invalid activation credentials.',
                'data' => null,
                'next_step' => null,
                'status' => 404,
            ];
        }

        if ($user->is_active && $user->account_status === AccountStatus::Active) {
            $nextStep = $user->isIndividual() ? 'kyc' : 'kyb';
            return [
                'success' => true,
                'message' => 'Account is already activated.',
                'data' => ['user' => $user],
                'next_step' => $nextStep,
                'status' => 200,
            ];
        }

        $verification = $this->otpService->verify($user, OtpPurpose::Activation, $code);

        if (!$verification['success']) {
            return [
                'success' => false,
                'message' => $verification['message'],
                'data' => null,
                'next_step' => 'verify_activation',
                'status' => 422,
            ];
        }

        // Activate user
        $user->forceFill([
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'is_active' => true,
            'account_status' => AccountStatus::Active,
        ])->save();

        AuditLogService::log('account_activated', $user);

        // Issue short-lived ability-scoped Sanctum token (abilities: ['kyc'])
        $expiryMinutes = (int) config('auth_otp.token.kyc_token_expiry_minutes', 60);
        $token = $user->createToken('kyc_token', ['kyc'], now()->addMinutes($expiryMinutes));

        $nextStep = $user->isIndividual() ? 'kyc' : 'kyb';

        return [
            'success' => true,
            'message' => 'Account successfully activated. Please complete identity verification.',
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_in_minutes' => $expiryMinutes,
                'user' => $user,
            ],
            'next_step' => $nextStep,
            'status' => 200,
        ];
    }

    /**
     * Resend activation OTP.
     *
     * @return array{success: bool, message: string, retry_after: int|null, status: int}
     */
    public function resendActivationOtp(string $identifier): array
    {
        $user = $this->findUserByIdentifier($identifier);

        if (!$user) {
            // Anti-enumeration: return friendly message
            return [
                'success' => true,
                'message' => 'If an account matches those details, a new activation code has been dispatched.',
                'retry_after' => null,
                'status' => 200,
            ];
        }

        if ($user->is_active && $user->account_status === AccountStatus::Active) {
            return [
                'success' => false,
                'message' => 'Account is already activated. Please login.',
                'retry_after' => null,
                'status' => 400,
            ];
        }

        $eligibility = $this->otpService->checkResendEligibility($user, OtpPurpose::Activation);

        if (!$eligibility['allowed']) {
            return [
                'success' => false,
                'message' => $eligibility['message'] ?? 'Please wait before requesting a new code.',
                'retry_after' => $eligibility['retry_after'],
                'status' => 429,
            ];
        }

        $this->otpService->generateAndSend($user, OtpPurpose::Activation);

        return [
            'success' => true,
            'message' => 'A new activation code has been dispatched to your email and phone.',
            'retry_after' => null,
            'status' => 200,
        ];
    }

    /**
     * Authenticate user credentials and start 2-step OTP flow.
     *
     * @return array{success: bool, message: string, data: array<string, mixed>|null, next_step: string|null, status: int}
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', strtolower(trim($email)))->first();

        // Timing-safe check if user not found
        if (!$user) {
            Hash::check($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
            AuditLogService::log('login_failed', null, ['email' => $email, 'reason' => 'user_not_found']);
            return [
                'success' => false,
                'message' => 'Invalid credentials',
                'data' => null,
                'next_step' => null,
                'status' => 401,
            ];
        }

        // Check if locked
        if ($user->isLocked()) {
            $retryAfter = (int) $user->locked_until->diffInSeconds(now());
            AuditLogService::log('login_blocked_locked', $user, ['retry_after' => $retryAfter]);
            return [
                'success' => false,
                'message' => "Account is locked due to too many failed attempts. Try again in {$retryAfter} seconds.",
                'data' => ['retry_after' => $retryAfter],
                'next_step' => null,
                'status' => 423,
            ];
        }

        // Verify password
        if (!Hash::check($password, $user->password)) {
            $user->increment('failed_login_attempts');
            $maxFailed = (int) config('auth_otp.lockout.max_failed_logins', 5);

            if ($user->failed_login_attempts >= $maxFailed) {
                $lockMinutes = (int) config('auth_otp.lockout.lockout_minutes', 15);
                $user->forceFill(['locked_until' => now()->addMinutes($lockMinutes)])->save();
                AuditLogService::log('account_locked', $user, ['failed_attempts' => $user->failed_login_attempts]);

                return [
                    'success' => false,
                    'message' => "Account locked due to 5 failed login attempts. Locked for {$lockMinutes} minutes.",
                    'data' => ['retry_after' => $lockMinutes * 60],
                    'next_step' => null,
                    'status' => 423,
                ];
            }

            AuditLogService::log('login_failed', $user, ['reason' => 'invalid_password']);
            return [
                'success' => false,
                'message' => 'Invalid credentials',
                'data' => null,
                'next_step' => null,
                'status' => 401,
            ];
        }

        // Reset failed login attempts on valid password
        if ($user->failed_login_attempts > 0 || $user->locked_until !== null) {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();
        }

        // Check activation
        if (!$user->is_active || $user->account_status !== AccountStatus::Active) {
            return [
                'success' => false,
                'message' => 'Account is not activated. Please verify your account with the OTP sent to your email and phone.',
                'data' => null,
                'next_step' => 'verify_activation',
                'status' => 403,
            ];
        }

        // Check Individual KYC
        if ($user->isIndividual() && $user->kyc_status !== KycStatus::Verified) {
            return [
                'success' => false,
                'message' => 'KYC verification is required before logging in.',
                'data' => null,
                'next_step' => 'kyc',
                'status' => 403,
            ];
        }

        // Check Business KYB
        $verificationNotice = null;
        if ($user->isBusiness()) {
            if ($user->kyb_status === KybStatus::NotStarted) {
                return [
                    'success' => false,
                    'message' => 'Business verification submission is required before logging in.',
                    'data' => null,
                    'next_step' => 'kyb',
                    'status' => 403,
                ];
            }

            if ($user->kyb_status === KybStatus::Pending) {
                $verificationNotice = 'Account verification pending';
            } elseif ($user->kyb_status === KybStatus::Rejected) {
                $businessProfile = $user->businessProfile;
                $reason = $businessProfile?->rejection_reason ?? 'Documentation did not meet compliance criteria';
                $verificationNotice = "Account verification was rejected: {$reason}";
            }
        }

        // Generate 6-digit login OTP (valid for 5 mins)
        $this->otpService->generateAndSend($user, OtpPurpose::Login);

        // Generate signed/encrypted session token
        $sessionToken = $this->createOtpSessionToken($user);

        $responseData = [
            'otp_session_token' => $sessionToken,
            'expires_in_minutes' => (int) config('auth_otp.login.expiry_minutes', 5),
        ];

        if ($verificationNotice !== null) {
            $responseData['verification_notice'] = $verificationNotice;
        }

        return [
            'success' => true,
            'message' => 'Credentials verified. Please enter the verification code sent to your email and phone.',
            'data' => $responseData,
            'next_step' => 'verify_login_otp',
            'status' => 200,
        ];
    }

    /**
     * Create an encrypted/signed OTP session token.
     */
    public function createOtpSessionToken(User $user): string
    {
        $expiryMinutes = (int) config('auth_otp.login.session_token_expiry_minutes', 5);
        $payload = [
            'user_id' => $user->id,
            'purpose' => 'login_otp',
            'expires_at' => now()->addMinutes($expiryMinutes)->timestamp,
            'nonce' => Str::random(16),
        ];

        return Crypt::encryptString(json_encode($payload));
    }

    /**
     * Parse and validate an OTP session token.
     */
    public function validateOtpSessionToken(string $token): ?User
    {
        try {
            $json = Crypt::decryptString($token);
            $payload = json_decode($json, true);

            if (!is_array($payload) || !isset($payload['user_id'], $payload['expires_at'])) {
                return null;
            }

            if (Carbon::createFromTimestamp($payload['expires_at'])->isPast()) {
                return null;
            }

            return User::find($payload['user_id']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Verify login OTP and issue full Sanctum token.
     *
     * @return array{success: bool, message: string, data: array<string, mixed>|null, next_step: string|null, status: int}
     */
    public function verifyLoginOtp(string $otpSessionToken, string $code): array
    {
        $user = $this->validateOtpSessionToken($otpSessionToken);

        if (!$user) {
            return [
                'success' => false,
                'message' => 'Login session has expired or is invalid. Please log in again.',
                'data' => null,
                'next_step' => 'login',
                'status' => 401,
            ];
        }

        $verification = $this->otpService->verify($user, OtpPurpose::Login, $code);

        if (!$verification['success']) {
            return [
                'success' => false,
                'message' => $verification['message'],
                'data' => null,
                'next_step' => 'verify_login_otp',
                'status' => 422,
            ];
        }

        // Revoke older tokens per single/managed device security policy
        $user->tokens()->delete();

        // Record last login
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => Request::ip(),
        ])->save();

        // Issue full Sanctum token (abilities: ['*'], expiry: 60 minutes)
        $expiryMinutes = (int) config('auth_otp.token.full_token_expiry_minutes', 60);
        $token = $user->createToken('auth_token', ['*'], now()->addMinutes($expiryMinutes));

        AuditLogService::log('login_success', $user);

        // Determine next_step and verification notice
        $nextStep = 'dashboard';
        $verificationNotice = null;

        if ($user->isBusiness()) {
            if ($user->kyb_status === KybStatus::Rejected) {
                $nextStep = 'resubmit_kyb';
                $reason = $user->businessProfile?->rejection_reason ?? 'Documentation did not meet compliance criteria';
                $verificationNotice = "Account verification was rejected: {$reason}";
            } elseif ($user->kyb_status === KybStatus::Pending) {
                $verificationNotice = 'Account verification pending';
            }
        }

        $responseData = [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in_minutes' => $expiryMinutes,
            'user' => $user,
        ];

        if ($verificationNotice !== null) {
            $responseData['verification_notice'] = $verificationNotice;
        }

        return [
            'success' => true,
            'message' => 'Login successful.',
            'data' => $responseData,
            'next_step' => $nextStep,
            'status' => 200,
        ];
    }

    /**
     * Resend login OTP.
     *
     * @return array{success: bool, message: string, retry_after: int|null, status: int}
     */
    public function resendLoginOtp(string $otpSessionToken): array
    {
        $user = $this->validateOtpSessionToken($otpSessionToken);

        if (!$user) {
            return [
                'success' => false,
                'message' => 'Login session has expired or is invalid. Please log in again.',
                'retry_after' => null,
                'status' => 401,
            ];
        }

        $eligibility = $this->otpService->checkResendEligibility($user, OtpPurpose::Login);

        if (!$eligibility['allowed']) {
            return [
                'success' => false,
                'message' => $eligibility['message'] ?? 'Please wait before requesting a new code.',
                'retry_after' => $eligibility['retry_after'],
                'status' => 429,
            ];
        }

        $this->otpService->generateAndSend($user, OtpPurpose::Login);

        return [
            'success' => true,
            'message' => 'A new login code has been dispatched to your email and phone.',
            'retry_after' => null,
            'status' => 200,
        ];
    }

    /**
     * Revoke current token.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
        AuditLogService::log('user_logout', $user);
    }

    /**
     * Revoke all tokens across all sessions.
     */
    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
        AuditLogService::log('user_logout_all', $user);
    }

    /**
     * Request password reset token.
     */
    public function forgotPassword(string $email): void
    {
        $user = User::where('email', strtolower(trim($email)))->first();

        if ($user) {
            $token = Str::random(64);
            $tokenHash = Hash::make($token);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => $tokenHash,
                    'created_at' => now(),
                ]
            );

            try {
                Mail::to($user->email)->queue(new PasswordResetMail($user, $token));
            } catch (\Throwable $e) {
                Log::error('[AuthService] Failed to queue password reset mail: ' . $e->getMessage());
            }

            AuditLogService::log('forgot_password_requested', $user);
        }
    }

    /**
     * Reset user password using token.
     *
     * @return array{success: bool, message: string, status: int}
     */
    public function resetPassword(string $email, string $token, string $newPassword): array
    {
        $user = User::where('email', strtolower(trim($email)))->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => 'Invalid or expired password reset token.',
                'status' => 400,
            ];
        }

        $record = DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->first();

        if (!$record) {
            return [
                'success' => false,
                'message' => 'Invalid or expired password reset token.',
                'status' => 400,
            ];
        }

        // Check expiry (60 mins)
        $createdAt = Carbon::parse($record->created_at);
        if ($createdAt->addMinutes(60)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            return [
                'success' => false,
                'message' => 'Password reset token has expired. Please request a new one.',
                'status' => 400,
            ];
        }

        // Verify token hash
        if (!Hash::check($token, $record->token)) {
            return [
                'success' => false,
                'message' => 'Invalid or expired password reset token.',
                'status' => 400,
            ];
        }

        // Update password and revoke all tokens
        $user->update(['password' => $newPassword]);
        $user->tokens()->delete();

        // Delete reset token
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();

        AuditLogService::log('password_reset_success', $user);

        return [
            'success' => true,
            'message' => 'Password has been successfully reset. Please log in with your new password.',
            'status' => 200,
        ];
    }
}
