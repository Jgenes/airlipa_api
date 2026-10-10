<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KybStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Get dashboard summary and links.
     * GET /api/v1/dashboard
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $accountSummary = [
            'account_status' => $user->account_status?->value ?? (string) $user->account_status,
            'user_type' => $user->user_type?->value ?? (string) $user->user_type,
            'kyc_status' => $user->kyc_status?->value ?? (string) $user->kyc_status,
            'kyb_status' => $user->kyb_status?->value ?? (string) $user->kyb_status,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];

        $data = [
            'welcome_message' => "Welcome back, {$user->first_name}!",
            'account_summary' => $accountSummary,
            'links' => [
                'profile' => '/api/v1/profile',
            ],
        ];

        $nextStep = null;
        if ($user->isBusiness()) {
            if ($user->kyb_status === KybStatus::Pending) {
                $data['verification_notice'] = 'Account verification pending';
            } elseif ($user->kyb_status === KybStatus::Rejected) {
                $reason = $user->businessProfile?->rejection_reason ?? 'Documentation did not meet compliance criteria';
                $data['verification_notice'] = "Account verification was rejected: {$reason}";
                $nextStep = 'resubmit_kyb';
            }
        }

        return ApiResponse::success(
            message: 'Dashboard data retrieved successfully.',
            data: $data,
            nextStep: $nextStep,
            status: 200
        );
    }
}
