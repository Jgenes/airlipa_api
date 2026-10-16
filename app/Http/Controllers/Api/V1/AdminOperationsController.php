<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ApprovalRequest;
use App\Models\ReconciliationException;
use App\Models\ReconciliationRun;
use App\Models\RiskAlert;
use App\Services\AuditLogService;
use App\Services\GovernanceService;
use App\Services\ReconciliationService;
use App\Services\RiskEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOperationsController extends Controller
{
    public function __construct(
        private readonly ReconciliationService $reconciliationService,
        private readonly RiskEngineService $riskEngineService,
        private readonly GovernanceService $governanceService
    ) {}

    /**
     * POST /api/v1/admin/reconciliation/runs
     * Ingest provider statement lines and execute a 3-way reconciliation run (M09, FR-020, FR-034).
     */
    public function executeReconciliationRun(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_code' => ['required', 'string', 'max:32'],
            'statement_date' => ['required', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3'],
            'statement_lines' => ['required', 'array', 'min:1'],
            'statement_lines.*.provider_reference' => ['required', 'string', 'max:96'],
            'statement_lines.*.attempt_reference' => ['nullable', 'string', 'max:64'],
            'statement_lines.*.direction' => ['nullable', 'string', 'in:INFLOW,OUTFLOW,REFUND'],
            'statement_lines.*.amount_minor' => ['required', 'integer', 'min:1'],
        ]);

        /** @var \App\Models\User $admin */
        $admin = $request->user();

        $run = $this->reconciliationService->executeRun(
            providerCode: $validated['provider_code'],
            statementDate: $validated['statement_date'],
            statementLines: $validated['statement_lines'],
            actor: $admin,
            currency: $validated['currency'] ?? 'TZS'
        );

        return ApiResponse::success(
            message: '3-way reconciliation run completed.',
            data: [
                'run_reference' => $run->run_reference,
                'provider_code' => $run->provider_code,
                'statement_date' => $run->statement_date,
                'currency' => $run->currency,
                'total_lines' => $run->total_lines,
                'matched_lines' => $run->matched_lines,
                'exception_lines' => $run->exception_lines,
                'statement_total_minor' => $run->statement_total_minor,
                'ledger_clearing_balance_minor' => $run->ledger_clearing_balance_minor,
                'protected_obligation_balance_minor' => $run->protected_obligation_balance_minor,
                'variance_minor' => $run->variance_minor,
                'status' => $run->status,
                'lines' => $run->statementLines,
            ],
            status: 201
        );
    }

    /**
     * GET /api/v1/admin/reconciliation/exceptions
     */
    public function listReconciliationExceptions(): JsonResponse
    {
        $exceptions = ReconciliationException::orderByDesc('id')->get();

        return ApiResponse::success(
            message: 'Reconciliation exceptions retrieved successfully.',
            data: ['exceptions' => $exceptions]
        );
    }

    /**
     * PATCH /api/v1/admin/reconciliation/exceptions/{exceptionId}/resolve
     */
    public function resolveReconciliationException(Request $request, string $exceptionId): JsonResponse
    {
        $validated = $request->validate([
            'resolution_notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        /** @var \App\Models\User $admin */
        $admin = $request->user();
        $exception = ReconciliationException::where('exception_id', $exceptionId)->firstOrFail();

        $resolved = $this->reconciliationService->resolveException($exception, $admin, $validated['resolution_notes']);

        return ApiResponse::success(
            message: 'Reconciliation exception resolved.',
            data: ['exception' => $resolved]
        );
    }

    /**
     * GET /api/v1/admin/risk/alerts
     */
    public function listRiskAlerts(): JsonResponse
    {
        $alerts = RiskAlert::orderByDesc('id')->get();

        return ApiResponse::success(
            message: 'Risk alerts retrieved successfully.',
            data: ['alerts' => $alerts]
        );
    }

    /**
     * PATCH /api/v1/admin/risk/alerts/{alertCode}/review
     */
    public function reviewRiskAlert(Request $request, string $alertCode): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:CLEARED,CONFIRMED_FRAUD'],
            'review_notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        /** @var \App\Models\User $admin */
        $admin = $request->user();
        $alert = RiskAlert::where('alert_code', $alertCode)->firstOrFail();

        $reviewed = $this->riskEngineService->reviewAlert($alert, $admin, $validated['status'], $validated['review_notes']);

        return ApiResponse::success(
            message: 'Risk alert reviewed.',
            data: ['alert' => $reviewed]
        );
    }

    /**
     * POST /api/v1/admin/governance/approvals
     * Maker initiates a Dual-Control Approval Request (M17, P-05, FR-036).
     */
    public function createApprovalRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action_type' => ['required', 'string', 'in:FEE_RULE_UPSERT,RECONCILIATION_EXCEPTION_RESOLVE,PROVIDER_STATUS_CHANGE'],
            'target_type' => ['required', 'string', 'max:64'],
            'target_identifier' => ['required', 'string', 'max:64'],
            'proposed_payload' => ['required', 'array'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        /** @var \App\Models\User $maker */
        $maker = $request->user();

        $approval = $this->governanceService->createApprovalRequest($maker, $validated);

        return ApiResponse::success(
            message: 'Dual-control approval request created and awaiting checker decision.',
            data: ['approval_request' => $approval],
            nextStep: 'await_checker_approval',
            status: 201
        );
    }

    /**
     * POST /api/v1/admin/governance/approvals/{approvalCode}/decide
     * Checker approves or rejects a Dual-Control Request (enforces Maker != Checker per P-05).
     */
    public function decideApprovalRequest(Request $request, string $approvalCode): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:APPROVE,REJECT'],
            'notes' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        /** @var \App\Models\User $checker */
        $checker = $request->user();
        $approval = ApprovalRequest::where('approval_code', $approvalCode)->firstOrFail();

        $decided = $this->governanceService->decideApprovalRequest(
            approval: $approval,
            checker: $checker,
            decision: $validated['decision'],
            notes: $validated['notes']
        );

        return ApiResponse::success(
            message: "Dual-control approval request {$decided->status}.",
            data: ['approval_request' => $decided]
        );
    }

    /**
     * GET /api/v1/admin/audit-logs/verify-chain
     * Verify tamper-evident SHA-256 hash chain across all audit logs (FR-030).
     */
    public function verifyAuditChain(): JsonResponse
    {
        return ApiResponse::success(
            message: 'Audit log hash chain verification completed.',
            data: AuditLogService::verifyHashChain()
        );
    }
}
