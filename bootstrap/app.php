<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureFullyVerified;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Apply JSON header and security headers to API group
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'force.json' => ForceJsonResponse::class,
            'security.headers' => SecurityHeaders::class,
            'ensure.active' => EnsureAccountActive::class,
            'ensure.fully.verified' => EnsureFullyVerified::class,
            'admin' => EnsureAdmin::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('Validation failed.', $e->errors(), null, 422);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('Unauthenticated.', null, 'login', 401);
            }
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $message = $e->getMessage() ?: 'This action is unauthorized.';
                return ApiResponse::error($message, null, null, 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('The requested resource was not found.', null, null, 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException|TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('Too many requests. Please slow down and try again later.', null, null, 429);
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (($request->is('api/*') || $request->expectsJson()) && !config('app.debug')) {
                return ApiResponse::error('An internal server error occurred.', null, null, 500);
            }
        });
    })->create();
