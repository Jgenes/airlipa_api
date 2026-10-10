<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Enums\KybStatus;
use App\Enums\KycStatus;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFullyVerified
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->is_active || $user->account_status !== AccountStatus::Active) {
            return ApiResponse::error(
                message: 'Account is not activated. Please verify your account with the OTP sent to your email and phone.',
                errors: null,
                nextStep: 'verify_activation',
                status: 403
            );
        }

        if ($user->isIndividual() && $user->kyc_status !== KycStatus::Verified) {
            return ApiResponse::error(
                message: 'KYC verification required before accessing this resource.',
                errors: null,
                nextStep: 'kyc',
                status: 403
            );
        }

        if ($user->isBusiness() && $user->kyb_status === KybStatus::NotStarted) {
            return ApiResponse::error(
                message: 'Business verification submission required before accessing this resource.',
                errors: null,
                nextStep: 'kyb',
                status: 403
            );
        }

        return $next($request);
    }
}
