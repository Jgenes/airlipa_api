<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ActivateRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendActivationOtpRequest;
use App\Http\Requests\Auth\ResendLoginOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyLoginOtpRequest;
use App\Http\Responses\ApiResponse;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService
    ) {}

    /**
     * Register a new Individual or Business user.
     * POST /api/v1/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return ApiResponse::success(
            message: 'Registration successful. Please verify your account with the OTP sent to your email and phone.',
            data: [
                'email' => $result['user']->email,
                'phone' => $result['user']->phone,
            ],
            nextStep: $result['next_step'],
            status: 201
        );
    }

    /**
     * Activate account using OTP code.
     * POST /api/v1/auth/activate
     */
    public function activate(ActivateRequest $request): JsonResponse
    {
        $result = $this->authService->activate(
            $request->getIdentifier(),
            $request->validated('code')
        );

        if (!$result['success']) {
            return ApiResponse::error(
                message: $result['message'],
                errors: null,
                nextStep: $result['next_step'],
                status: $result['status']
            );
        }

        return ApiResponse::success(
            message: $result['message'],
            data: $result['data'],
            nextStep: $result['next_step'],
            status: $result['status']
        );
    }

    /**
     * Resend account activation OTP code.
     * POST /api/v1/auth/activation/resend
     */
    public function resendActivation(ResendActivationOtpRequest $request): JsonResponse
    {
        $result = $this->authService->resendActivationOtp($request->getIdentifier());

        if (!$result['success']) {
            return ApiResponse::error(
                message: $result['message'],
                errors: null,
                nextStep: 'verify_activation',
                status: $result['status'],
                data: $result['retry_after'] ? ['retry_after' => $result['retry_after']] : null
            );
        }

        return ApiResponse::success(
            message: $result['message'],
            data: null,
            nextStep: 'verify_activation',
            status: $result['status']
        );
    }

    /**
     * Authenticate credentials and send login OTP.
     * POST /api/v1/auth/login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->validated('email'),
            $request->validated('password')
        );

        if (!$result['success']) {
            return ApiResponse::error(
                message: $result['message'],
                errors: null,
                nextStep: $result['next_step'],
                status: $result['status'],
                data: $result['data']
            );
        }

        return ApiResponse::success(
            message: $result['message'],
            data: $result['data'],
            nextStep: $result['next_step'],
            status: $result['status']
        );
    }

    /**
     * Verify login OTP and issue bearer Sanctum token.
     * POST /api/v1/auth/login/verify-otp
     */
    public function verifyLoginOtp(VerifyLoginOtpRequest $request): JsonResponse
    {
        $result = $this->authService->verifyLoginOtp(
            $request->validated('otp_session_token'),
            $request->validated('code')
        );

        if (!$result['success']) {
            return ApiResponse::error(
                message: $result['message'],
                errors: null,
                nextStep: $result['next_step'],
                status: $result['status']
            );
        }

        return ApiResponse::success(
            message: $result['message'],
            data: $result['data'],
            nextStep: $result['next_step'],
            status: $result['status']
        );
    }

    /**
     * Resend login OTP code.
     * POST /api/v1/auth/login/resend-otp
     */
    public function resendLoginOtp(ResendLoginOtpRequest $request): JsonResponse
    {
        $result = $this->authService->resendLoginOtp($request->validated('otp_session_token'));

        if (!$result['success']) {
            return ApiResponse::error(
                message: $result['message'],
                errors: null,
                nextStep: 'verify_login_otp',
                status: $result['status'],
                data: $result['retry_after'] ? ['retry_after' => $result['retry_after']] : null
            );
        }

        return ApiResponse::success(
            message: $result['message'],
            data: null,
            nextStep: 'verify_login_otp',
            status: $result['status']
        );
    }

    /**
     * Logout and revoke current access token.
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return ApiResponse::success(
            message: 'Successfully logged out.',
            data: null,
            nextStep: 'login',
            status: 200
        );
    }

    /**
     * Logout and revoke all access tokens across all devices.
     * POST /api/v1/auth/logout-all
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $this->authService->logoutAll($request->user());

        return ApiResponse::success(
            message: 'Successfully logged out from all devices.',
            data: null,
            nextStep: 'login',
            status: 200
        );
    }

    /**
     * Request a password reset link.
     * POST /api/v1/auth/forgot-password
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->authService->forgotPassword($request->validated('email'));

        return ApiResponse::success(
            message: 'If your email is registered, you will receive password reset instructions shortly.',
            data: null,
            nextStep: 'reset_password',
            status: 200
        );
    }

    /**
     * Reset password using token.
     * POST /api/v1/auth/reset-password
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $result = $this->authService->resetPassword(
            $request->validated('email'),
            $request->validated('token'),
            $request->validated('password')
        );

        if (!$result['success']) {
            return ApiResponse::error(
                message: $result['message'],
                errors: null,
                nextStep: null,
                status: $result['status']
            );
        }

        return ApiResponse::success(
            message: $result['message'],
            data: null,
            nextStep: 'login',
            status: $result['status']
        );
    }
}
