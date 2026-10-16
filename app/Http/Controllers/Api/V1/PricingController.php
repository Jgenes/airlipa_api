<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProtectedPayment\CreateFeeQuoteRequest;
use App\Http\Resources\FeeQuoteResource;
use App\Http\Responses\ApiResponse;
use App\Models\FeeQuote;
use App\Models\FeeRule;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    public function __construct(
        protected PricingService $pricingService
    ) {}

    /**
     * Get the current active price book schedule (SASDS 3.5.5).
     * GET /api/v1/pricing/schedules/current
     */
    public function currentSchedule(): JsonResponse
    {
        $this->pricingService->ensureDefaultFeeRulesExist();

        $rules = FeeRule::where('status', 'ACTIVE')->orderBy('id')->get();

        return ApiResponse::success(
            message: 'Current approved price book schedule retrieved successfully.',
            data: [
                'pricing_version' => config('protected_payment.pricing_version'),
                'terms_version' => config('protected_payment.terms_version'),
                'currency' => config('protected_payment.default_currency', 'TZS'),
                'rules' => $rules,
            ],
            nextStep: 'create_quote',
            status: 200
        );
    }

    /**
     * Generate an immutable itemized fee quote (SASDS 3.5.5, FR-019).
     * POST /api/v1/pricing/quotes
     */
    public function createQuote(CreateFeeQuoteRequest $request): JsonResponse
    {
        $quote = $this->pricingService->createQuote(
            $request->user(),
            $request->validated()
        );

        return ApiResponse::success(
            message: 'Fee quote generated successfully. Please review itemized fees and disclosures before accepting.',
            data: new FeeQuoteResource($quote),
            nextStep: 'accept_quote',
            status: 201
        );
    }

    /**
     * Retrieve a specific fee quote by quote_id.
     * GET /api/v1/pricing/quotes/{quoteId}
     */
    public function showQuote(Request $request, string $quoteId): JsonResponse
    {
        $quote = FeeQuote::where('quote_id', $quoteId)->firstOrFail();
        $user = $request->user();

        if ($user->id !== $quote->merchant_id && $user->id !== $quote->buyer_id && $user->id !== $quote->created_by_user_id && !$user->isAdmin()) {
            return ApiResponse::error(
                message: 'Unauthorized access to this fee quote.',
                errors: null,
                nextStep: null,
                status: 403
            );
        }

        return ApiResponse::success(
            message: 'Fee quote retrieved successfully.',
            data: new FeeQuoteResource($quote),
            nextStep: $quote->status === 'CREATED' ? 'accept_quote' : 'create_protected_payment',
            status: 200
        );
    }

    /**
     * Explicitly accept a fee quote prior to transaction creation or funding (BPA-REV-03).
     * POST /api/v1/pricing/quotes/{quoteId}/accept
     */
    public function acceptQuote(Request $request, string $quoteId): JsonResponse
    {
        $quote = FeeQuote::where('quote_id', $quoteId)->firstOrFail();

        $accepted = $this->pricingService->acceptQuote($quote, $request->user());

        return ApiResponse::success(
            message: 'Fee quote accepted and locked.',
            data: new FeeQuoteResource($accepted),
            nextStep: 'create_protected_payment',
            status: 200
        );
    }
}
