<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kyc\KycInitiateRequest;
use App\Http\Requests\Kyc\KycVerifyRequest;
use App\Http\Responses\ApiResponse;
use App\Services\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycController extends Controller
{
    public function __construct(
        protected KycService $kycService
    ) {}

    /**
     * Initiate NIDA KYC challenge questions.
     * POST /api/v1/kyc/nida/initiate
     */
    public function initiate(KycInitiateRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isBusiness()) {
            return ApiResponse::error(
                message: 'NIDA KYC is designated for Individual accounts only. Business accounts must submit KYB verification.',
                errors: null,
                nextStep: 'kyb',
                status: 400
            );
        }

        $result = $this->kycService->initiate(
            $user,
            $request->validated('nida_number')
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
     * Verify submitted answers to NIDA KYC challenge questions.
     * POST /api/v1/kyc/nida/verify
     */
    public function verify(KycVerifyRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isBusiness()) {
            return ApiResponse::error(
                message: 'NIDA KYC is designated for Individual accounts only.',
                errors: null,
                nextStep: 'kyb',
                status: 400
            );
        }

        $result = $this->kycService->verify(
            $user,
            (int) $request->validated('kyc_session_id'),
            $request->validated('answers')
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
}
