<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProtectedPaymentStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ProtectedPayment;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class MerchantTrustController extends Controller
{
    /**
     * GET /api/v1/merchants/{merchantId}/trust-profile
     * Publicly verifiable Merchant Trust & Verification Badge Profile (M03, FR-004, FR-005).
     */
    public function show(int $merchantId): JsonResponse
    {
        $merchant = User::with('businessProfile')->findOrFail($merchantId);

        $totalAgreements = ProtectedPayment::where('merchant_id', $merchant->id)->count();
        $completedReleased = ProtectedPayment::where('merchant_id', $merchant->id)
            ->where('status', ProtectedPaymentStatus::Released)
            ->count();
        $disputedCount = ProtectedPayment::where('merchant_id', $merchant->id)
            ->where('status', ProtectedPaymentStatus::Disputed)
            ->count();

        return ApiResponse::success(
            message: 'Merchant trust and verification profile retrieved successfully.',
            data: [
                'merchant_id' => $merchant->id,
                'trust_badge' => $merchant->publicTrustSummary(),
                'trading_eligibility' => [
                    'can_accept_protected_payments' => $merchant->isVerifiedForTrading(),
                    'can_receive_settlement_payouts' => $merchant->isVerifiedForTrading(),
                    'verification_level' => $merchant->user_type === UserType::Business ? 'KYB_BUSINESS' : 'KYC_INDIVIDUAL',
                ],
                'track_record' => [
                    'total_protected_agreements' => $totalAgreements,
                    'completed_released_agreements' => $completedReleased,
                    'active_disputed_agreements' => $disputedCount,
                ],
                'member_since' => $merchant->created_at?->toDateString(),
            ],
            nextStep: 'create_protected_payment'
        );
    }
}
