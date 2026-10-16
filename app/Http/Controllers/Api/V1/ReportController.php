<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\LedgerService;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportingService $reportingService,
        private readonly LedgerService $ledgerService
    ) {}

    /**
     * GET /api/v1/reports/statements
     * Generate user/merchant transaction, fee, VAT, and payout statement (M16, FR-021).
     */
    public function userStatement(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $currency = strtoupper((string) $request->query('currency', 'TZS'));

        return ApiResponse::success(
            message: 'Transaction and fee statement generated successfully.',
            data: $this->reportingService->generateUserStatement($user, $currency)
        );
    }

    /**
     * GET /api/v1/admin/reports/trial-balance
     * Generate complete operational sub-ledger trial balance (M08, M16).
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $currency = strtoupper((string) $request->query('currency', 'TZS'));

        return ApiResponse::success(
            message: 'Operational sub-ledger trial balance generated successfully.',
            data: $this->ledgerService->getTrialBalance($currency)
        );
    }

    /**
     * GET /api/v1/admin/reports/safeguarding-proof-pack
     * Generate Regulatory & Safeguarding Compliance Proof Pack (M16, FR-022).
     */
    public function safeguardingProofPack(Request $request): JsonResponse
    {
        $currency = strtoupper((string) $request->query('currency', 'TZS'));

        return ApiResponse::success(
            message: 'Safeguarding and compliance proof pack generated successfully.',
            data: $this->reportingService->generateSafeguardingProofPack($currency)
        );
    }
}
