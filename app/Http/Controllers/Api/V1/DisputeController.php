<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProtectedPayment\OpenDisputeRequest;
use App\Http\Requests\ProtectedPayment\ResolveDisputeRequest;
use App\Http\Resources\DisputeCaseResource;
use App\Http\Resources\ProtectedPaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\DisputeCase;
use App\Models\ProtectedPayment;
use App\Services\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function __construct(
        protected DisputeService $disputeService
    ) {}

    /**
     * Open a dispute case and atomically freeze the disputed protected balance (FR-014, FR-032).
     * POST /api/v1/protected-payments/{id}/disputes
     */
    public function store(OpenDisputeRequest $request, string $id): JsonResponse
    {
        $payment = ProtectedPayment::where('protected_payment_id', $id)
            ->orWhere('reference', $id)
            ->firstOrFail();

        $dispute = $this->disputeService->openDispute(
            payment: $payment,
            actor: $request->user(),
            data: $request->validated()
        );

        return ApiResponse::success(
            message: 'Dispute case opened. The disputed amount is now frozen and excluded from automatic release or settlement.',
            data: [
                'dispute' => new DisputeCaseResource($dispute),
                'agreement' => new ProtectedPaymentResource($payment->fresh(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones'])),
            ],
            nextStep: 'submit_dispute_evidence',
            status: 201
        );
    }

    /**
     * Retrieve details of a dispute case (FR-032).
     * GET /api/v1/disputes/{caseId}
     */
    public function show(Request $request, string $caseId): JsonResponse
    {
        $dispute = DisputeCase::with('protectedPayment')
            ->where('case_id', $caseId)
            ->orWhere('case_reference', $caseId)
            ->firstOrFail();

        $user = $request->user();
        $payment = $dispute->protectedPayment;

        if ($user->id !== $payment->buyer_id && $user->id !== $payment->merchant_id && !$user->isAdmin()) {
            return ApiResponse::error(
                message: 'Unauthorized access to this dispute case.',
                errors: null,
                nextStep: null,
                status: 403
            );
        }

        return ApiResponse::success(
            message: 'Dispute case retrieved successfully.',
            data: new DisputeCaseResource($dispute),
            nextStep: null,
            status: 200
        );
    }

    /**
     * Admin / Dispute Analyst resolves a dispute and triggers controlled release/refund execution (P-07, FR-013, FR-014).
     * POST /api/v1/admin/disputes/{caseId}/resolve
     */
    public function resolve(ResolveDisputeRequest $request, string $caseId): JsonResponse
    {
        $dispute = DisputeCase::with('protectedPayment')
            ->where('case_id', $caseId)
            ->orWhere('case_reference', $caseId)
            ->firstOrFail();

        $result = $this->disputeService->resolveDispute(
            dispute: $dispute,
            analyst: $request->user(),
            data: $request->validated(),
            idempotencyKey: $request->resolvedIdempotencyKey()
        );

        return ApiResponse::success(
            message: 'Dispute resolved and financial remedy executed.',
            data: [
                'dispute' => new DisputeCaseResource($result['dispute']),
                'agreement' => new ProtectedPaymentResource($result['agreement']),
            ],
            nextStep: null,
            status: 200
        );
    }
}
