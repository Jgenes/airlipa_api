<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kyb\KybRejectRequest;
use App\Http\Resources\BusinessProfileResource;
use App\Http\Responses\ApiResponse;
use App\Models\BusinessProfile;
use App\Services\KybService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminKybController extends Controller
{
    public function __construct(
        protected KybService $kybService
    ) {}

    /**
     * Approve business KYB submission.
     * PATCH /api/v1/admin/kyb/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $profile = BusinessProfile::with('user')->findOrFail($id);

        $this->kybService->approve($profile, $request->user());

        return ApiResponse::success(
            message: 'Business KYB successfully approved.',
            data: new BusinessProfileResource($profile->fresh()),
            nextStep: null,
            status: 200
        );
    }

    /**
     * Reject business KYB submission with reason.
     * PATCH /api/v1/admin/kyb/{id}/reject
     */
    public function reject(KybRejectRequest $request, int $id): JsonResponse
    {
        $profile = BusinessProfile::with('user')->findOrFail($id);

        $this->kybService->reject(
            $profile,
            $request->validated('reason'),
            $request->user()
        );

        return ApiResponse::success(
            message: 'Business KYB successfully rejected.',
            data: new BusinessProfileResource($profile->fresh()),
            nextStep: null,
            status: 200
        );
    }
}
