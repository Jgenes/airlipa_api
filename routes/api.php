<?php

use App\Http\Controllers\Api\V1\AdminKybController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\KybController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Version 1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ==========================================
    // Public Authentication Endpoints
    // ==========================================
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:register');

        Route::post('activate', [AuthController::class, 'activate'])
            ->middleware('throttle:otp_verify');

        Route::post('activation/resend', [AuthController::class, 'resendActivation'])
            ->middleware('throttle:otp_resend');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:login');

        Route::post('login/verify-otp', [AuthController::class, 'verifyLoginOtp'])
            ->middleware('throttle:otp_verify');

        Route::post('login/resend-otp', [AuthController::class, 'resendLoginOtp'])
            ->middleware('throttle:otp_resend');

        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:5,1');

        Route::post('reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:5,1');
    });

    // ==========================================
    // Document Download Endpoint (Signed/Auth)
    // ==========================================
    Route::get('kyb/document/{id}', [KybController::class, 'downloadDocument'])
        ->name('kyb.document.download');

    // ==========================================
    // KYC & KYB Verification Flow Endpoints
    // Accessible with 'kyc' scoped tokens OR full tokens
    // ==========================================
    Route::middleware(['auth:sanctum', 'ability:kyc,*'])->group(function () {
        // Individual KYC (NIDA Mock)
        Route::prefix('kyc/nida')->group(function () {
            Route::post('initiate', [KycController::class, 'initiate'])
                ->middleware('throttle:5,1');

            Route::post('verify', [KycController::class, 'verify'])
                ->middleware('throttle:5,1');
        });

        // Business KYB
        Route::prefix('kyb')->group(function () {
            Route::post('submit', [KybController::class, 'submit'])
                ->middleware('throttle:5,1');
        });
    });

    // ==========================================
    // Fully Authenticated User Endpoints
    // ==========================================
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);

        // Endpoints requiring verified status (or business with submitted KYB)
        Route::middleware(['ensure.fully.verified'])->group(function () {
            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::get('profile', [ProfileController::class, 'show']);
        });
    });

    // ==========================================
    // Admin Endpoints
    // ==========================================
    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
        Route::patch('kyb/{id}/approve', [AdminKybController::class, 'approve']);
        Route::patch('kyb/{id}/reject', [AdminKybController::class, 'reject']);
    });
});
