<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EscrowContract;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\RatingService;
use App\Services\ReportingService;
use App\Services\TradingLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Phase 5 surfaces: analytics, ratings, CSV exports and KYC/AML tier views.
 */
class AdminInsightsController extends Controller
{
    public function __construct(
        protected AnalyticsService $analytics,
        protected RatingService $ratings,
        protected ReportingService $reporting,
        protected TradingLimitService $limits
    ) {}

    /**
     * GET /api/v1/admin/analytics/overview
     */
    public function overview(Request $request): JsonResponse
    {
        $days = min(365, max(1, (int) $request->query('days', 30)));

        return ApiResponse::success(
            message: 'Platform analytics overview generated.',
            data: $this->analytics->overview($days),
            nextStep: null,
            status: 200
        );
    }

    /**
     * GET /api/v1/admin/analytics/timeseries
     */
    public function timeseries(Request $request): JsonResponse
    {
        $days = min(365, max(1, (int) $request->query('days', 30)));

        return ApiResponse::success(
            message: 'Daily analytics series generated.',
            data: $this->analytics->timeseries($days),
            nextStep: null,
            status: 200
        );
    }

    /**
     * GET /api/v1/admin/reports/escrow-register(.csv)
     */
    public function escrowRegister(Request $request)
    {
        $rows = $this->reporting->escrowRegister(
            $request->query('from'),
            $request->query('to')
        );

        if ($request->query('format') === 'csv' || str_ends_with($request->path(), '.csv')) {
            return $this->csvResponse($this->reporting->toCsv($rows), 'escrow-register-' . now()->toDateString() . '.csv');
        }

        return ApiResponse::success(
            message: 'Escrow register generated.',
            data: $rows,
            nextStep: null,
            status: 200
        );
    }

    /**
     * GET /api/v1/admin/reports/settlements(.csv)
     */
    public function settlementRegister(Request $request)
    {
        $rows = $this->reporting->settlementRegister();

        if ($request->query('format') === 'csv' || str_ends_with($request->path(), '.csv')) {
            return $this->csvResponse($this->reporting->toCsv($rows), 'settlements-' . now()->toDateString() . '.csv');
        }

        return ApiResponse::success(
            message: 'Payout and refund settlement register generated.',
            data: $rows,
            nextStep: null,
            status: 200
        );
    }

    /**
     * GET /api/v1/reports/statements.csv
     */
    public function myStatementCsv(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $statement = $this->reporting->generateUserStatement($user, strtoupper((string) $request->query('currency', 'TZS')));

        $rows = [];
        foreach (($statement['transactions'] ?? []) as $transaction) {
            $rows[] = [
                'reference' => $transaction['reference'] ?? null,
                'type' => $transaction['type'] ?? null,
                'status' => $transaction['status'] ?? null,
                'currency' => $transaction['currency'] ?? 'TZS',
                'amount_minor' => $transaction['amount_minor'] ?? 0,
                'fee_minor' => $transaction['fee_minor'] ?? 0,
                'tax_minor' => $transaction['tax_minor'] ?? 0,
                'occurred_at' => $transaction['occurred_at'] ?? null,
            ];
        }

        if ($rows === []) {
            $rows[] = [
                'reference' => 'NO_TRANSACTIONS',
                'type' => null,
                'status' => null,
                'currency' => $statement['currency'] ?? 'TZS',
                'amount_minor' => 0,
                'fee_minor' => 0,
                'tax_minor' => 0,
                'occurred_at' => now()->toIso8601String(),
            ];
        }

        return $this->csvResponse($this->reporting->toCsv($rows), 'statement-' . now()->toDateString() . '.csv');
    }

    /**
     * POST /api/v1/contracts/{id}/rating
     */
    public function rateContract(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'dimensions' => ['nullable', 'array'],
        ]);

        $contract = EscrowContract::with('protectedPayment')->whereKey($id)->firstOrFail();
        $rating = $this->ratings->rate($contract, $request->user(), $validated);

        return ApiResponse::success(
            message: 'Thank you — your rating has been recorded.',
            data: [
                'rating_id' => $rating->rating_id,
                'score' => (int) $rating->score,
                'rater_role' => $rating->rater_role,
                'comment' => $rating->comment,
                'created_at' => $rating->created_at?->toIso8601String(),
            ],
            nextStep: null,
            status: 201
        );
    }

    /**
     * GET /api/v1/users/{userId}/reputation (public)
     */
    public function reputation(int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);

        return ApiResponse::success(
            message: 'Reputation summary retrieved.',
            data: $this->ratings->reputationFor($user),
            nextStep: null,
            status: 200
        );
    }

    /**
     * GET /api/v1/me/trading-limits
     */
    public function myLimits(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            message: 'Your KYC/AML trading tier and limits retrieved.',
            data: $this->limits->summaryFor($user),
            nextStep: null,
            status: 200
        );
    }

    /**
     * PATCH /api/v1/admin/users/{userId}/aml-tier
     * Compliance review: assign tier / risk rating manually.
     */
    public function updateAmlTier(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'kyc_tier' => ['required', 'string', 'in:TIER_0,TIER_1,TIER_2,TIER_3'],
            'aml_risk_rating' => ['nullable', 'string', 'in:LOW,MEDIUM,HIGH'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $user = User::findOrFail($userId);
        $previous = $user->kyc_tier?->value;

        $user->forceFill([
            'kyc_tier' => $validated['kyc_tier'],
            'aml_risk_rating' => $validated['aml_risk_rating'] ?? ($user->aml_risk_rating?->value ?? 'LOW'),
            'tier_assessed_at' => now(),
        ])->save();

        \App\Services\AuditLogService::log('user.aml_tier_changed', $request->user(), [
            'target_user_id' => $user->id,
            'previous_tier' => $previous,
            'new_tier' => $validated['kyc_tier'],
            'risk_rating' => $validated['aml_risk_rating'] ?? null,
            'reason' => $validated['reason'],
        ]);

        return ApiResponse::success(
            message: 'Trading tier updated after compliance review.',
            data: $this->limits->summaryFor($user->fresh()),
            nextStep: null,
            status: 200
        );
    }

    protected function csvResponse(string $csv, string $filename)
    {
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
