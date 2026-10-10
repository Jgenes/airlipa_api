<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->is_active || $user->account_status !== AccountStatus::Active) {
            return ApiResponse::error(
                message: 'Account is not activated. Please complete activation with your OTP.',
                errors: null,
                nextStep: 'verify_activation',
                status: 403
            );
        }

        return $next($request);
    }
}
