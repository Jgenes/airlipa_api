<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isAdmin()) {
            return ApiResponse::error(
                message: 'Forbidden. Admin privileges required.',
                errors: null,
                nextStep: null,
                status: 403
            );
        }

        return $next($request);
    }
}
