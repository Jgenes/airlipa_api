<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProtectedPayment\CreatePaymentLinkRequest;
use App\Http\Requests\ProtectedPayment\RevokePaymentLinkRequest;
use App\Http\Resources\ProtectedPaymentLinkResource;
use App\Http\Resources\ProtectedPaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\ProtectedPaymentLink;
use App\Services\ProtectedPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentLinkController extends Controller
{
    public function __construct(
        protected ProtectedPaymentService $protectedPaymentService
    ) {}

    /**
     * List the authenticated merchant's Protected Payment links.
     * GET /api/v1/payment-links
     */
    public function index(Request $request): JsonResponse
    {
        $links = ProtectedPaymentLink::with('merchant.businessProfile')
            ->where('merchant_id', $request->user()->id)
            ->latest()
            ->get();

        return ApiResponse::success(
            message: 'Protected Payment links retrieved successfully.',
            data: ProtectedPaymentLinkResource::collection($links),
            nextStep: null,
            status: 200
        );
    }

    /**
     * Create a new shareable Protected Checkout Link (SASDS 3.4.5.5, 3.6.5).
     * POST /api/v1/payment-links
     */
    public function store(CreatePaymentLinkRequest $request): JsonResponse
    {
        $link = $this->protectedPaymentService->createPaymentLink(
            $request->user(),
            $request->validated()
        );

        return ApiResponse::success(
            message: 'Protected Payment link created successfully.',
            data: new ProtectedPaymentLinkResource($link),
            nextStep: 'share_payment_link',
            status: 201
        );
    }

    /**
     * Read-only public preview of a Protected Payment link (BPA-CH-13, BPA-CH-14).
     * GET /api/v1/payment-links/{token}
     */
    public function preview(string $token): JsonResponse
    {
        $preview = $this->protectedPaymentService->previewPaymentLink($token);

        return ApiResponse::success(
            message: 'Protected Payment link preview retrieved. Opening a link is read-only.',
            data: $preview,
            nextStep: 'checkout_payment_link',
            status: 200
        );
    }

    /**
     * Buyer accepts a Protected Checkout Link to create a Protected Payment agreement.
     * POST /api/v1/payment-links/{token}/checkout
     */
    public function checkout(Request $request, string $token): JsonResponse
    {
        $result = $this->protectedPaymentService->checkoutFromLink(
            $token,
            $request->user(),
            $request->all()
        );

        return ApiResponse::success(
            message: 'Protected Payment agreement created from link. Proceed to authorize provider funding.',
            data: [
                'agreement' => new ProtectedPaymentResource($result['agreement']),
                'handover_otp' => $result['handover_otp'],
                'handover_otp_notice' => 'Keep this 6-digit handover OTP safe. Share it only upon verified delivery or use it to authorize release.',
            ],
            nextStep: 'initiate_payment_intent',
            status: 201
        );
    }

    /**
     * Revoke an active Protected Payment link (BPA-SBV-05).
     * PATCH /api/v1/payment-links/{linkId}/revoke
     */
    public function revoke(RevokePaymentLinkRequest $request, string $linkId): JsonResponse
    {
        $link = ProtectedPaymentLink::where('link_id', $linkId)->firstOrFail();

        $revoked = $this->protectedPaymentService->revokePaymentLink(
            $link,
            $request->user(),
            $request->validated('reason')
        );

        return ApiResponse::success(
            message: 'Protected Payment link revoked successfully.',
            data: new ProtectedPaymentLinkResource($revoked),
            nextStep: null,
            status: 200
        );
    }
}
