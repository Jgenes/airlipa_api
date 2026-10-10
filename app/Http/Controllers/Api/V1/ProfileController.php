<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\KybStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Get authenticated user profile details.
     * GET /api/v1/profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['latestKycVerification', 'businessProfile']);

        $nextStep = null;
        if ($user->isBusiness() && $user->kyb_status === KybStatus::Rejected) {
            $nextStep = 'resubmit_kyb';
        }

        return ApiResponse::success(
            message: 'User profile retrieved successfully.',
            data: new ProfileResource($user),
            nextStep: $nextStep,
            status: 200
        );
    }
}
