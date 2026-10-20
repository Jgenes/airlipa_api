<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ReconciliationException;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function __construct(
        protected LedgerService $ledgerService
    ) {}

    /**
     * Retrieve the authenticated user's / merchant's operational sub-ledger obligations summary (M08, SASDS 3.7.9).
     * GET /api/v1/ledger/obligations-summary
     */
    public function obligationsSummary(Request $request): JsonResponse
    {
        $currency = (string) $request->query('currency', config('protected_payment.default_currency', 'TZS'));
        $summary = $this->ledgerService->getObligationsSummary($request->user(), $currency);

        return ApiResponse::success(
            message: 'Operational sub-ledger obligations summary retrieved.',
            data: $summary,
            nextStep: null,
            status: 200
        );
    }

    /**
     * Admin / Finance view of reconciliation exceptions queue (M09, FR-018).
     * GET /api/v1/admin/reconciliation/exceptions
     */
    public function reconciliationExceptions(): JsonResponse
    {
        $exceptions = ReconciliationException::latest()->get();

        return ApiResponse::success(
            message: 'Reconciliation exception queue retrieved.',
            data: $exceptions,
            nextStep: null,
            status: 200
        );
    }

    /**
     * Posting-rules catalogue: every business event and its defined journal lines.
     * GET /api/v1/ledger/posting-rules
     */
    public function postingRules(): JsonResponse
    {
        return ApiResponse::success(
            message: 'Posting rules catalogue retrieved.',
            data: app(\App\Services\Ledger\PostingRulesEngine::class)->catalogue(),
            nextStep: null,
            status: 200
        );
    }
}
