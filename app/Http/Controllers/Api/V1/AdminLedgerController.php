<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EscrowContract;
use App\Services\EscrowReconciliationService;
use App\Services\LedgerExplorerService;
use App\Services\PayoutFreezeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLedgerController extends Controller
{
    public function __construct(
        protected LedgerExplorerService $explorer,
        protected EscrowReconciliationService $reconciliation,
        protected PayoutFreezeService $freezeService
    ) {}

    /**
     * GET /api/v1/admin/ledger/accounts
     */
    public function accounts(Request $request): JsonResponse
    {
        return ApiResponse::success(
            message: 'Chart of accounts with live balances retrieved.',
            data: $this->explorer->accounts((string) $request->query('currency', 'TZS')),
            nextStep: 'drill_into_account',
            status: 200
        );
    }

    /**
     * GET /api/v1/admin/ledger/accounts/{accountCode}
     */
    public function accountDetail(Request $request, string $accountCode): JsonResponse
    {
        return ApiResponse::success(
            message: 'Account postings retrieved for drill-down.',
            data: $this->explorer->accountDetail($accountCode, [
                'from' => $request->query('from'),
                'to' => $request->query('to'),
                'limit' => $request->query('limit'),
            ]),
            nextStep: 'open_journal',
            status: 200
        );
    }

    /**
     * GET /api/v1/admin/ledger/journals/{journalId}
     */
    public function journal(Request $request, string $journalId): JsonResponse
    {
        return ApiResponse::success(
            message: 'Journal retrieved with balanced lines and linked objects.',
            data: $this->explorer->journalDetail($journalId),
            nextStep: null,
            status: 200
        );
    }

    /**
     * GET /api/v1/admin/ledger/contracts/{contractId}
     */
    public function contract(Request $request, string $contractId): JsonResponse
    {
        $contract = EscrowContract::with('protectedPayment')
            ->where('contract_id', $contractId)
            ->orWhere('reference', $contractId)
            ->firstOrFail();

        return ApiResponse::success(
            message: 'Contract subledger drill-down retrieved.',
            data: $this->explorer->contractDetail($contract),
            nextStep: null,
            status: 200
        );
    }

    /**
     * GET /api/v1/admin/reconciliation/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        return ApiResponse::success(
            message: 'Reconciliation dashboard retrieved.',
            data: $this->reconciliation->dashboard((string) $request->query('currency', 'TZS')),
            nextStep: null,
            status: 200
        );
    }

    /**
     * POST /api/v1/admin/reconciliation/invariant-check
     */
    public function runInvariantCheck(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $result = $this->reconciliation->runInvariantCheck($user);

        return ApiResponse::success(
            message: $result['healthy']
                ? 'Escrow invariant holds across subledger, GL and partner statement.'
                : 'Escrow invariant breached. Payouts have been frozen and a CRITICAL exception raised.',
            data: $result,
            nextStep: $result['healthy'] ? null : 'investigate_exception',
            status: $result['healthy'] ? 200 : 409
        );
    }

    /**
     * POST /api/v1/admin/payout-freeze
     */
    public function activateFreeze(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'scope' => ['nullable', 'string', 'max:40'],
        ]);

        $state = $this->freezeService->activate(
            $request->user(),
            (string) $validated['reason'],
            [],
            (string) ($validated['scope'] ?? 'GLOBAL')
        );

        return ApiResponse::success(
            message: 'Payouts frozen. No disbursement can complete until this is cleared.',
            data: $state,
            nextStep: null,
            status: 201
        );
    }

    /**
     * DELETE /api/v1/admin/payout-freeze
     */
    public function releaseFreeze(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'scope' => ['nullable', 'string', 'max:40'],
        ]);

        $state = $this->freezeService->release(
            $request->user(),
            (string) $validated['reason'],
            (string) ($validated['scope'] ?? 'GLOBAL')
        );

        return ApiResponse::success(
            message: 'Payout freeze released.',
            data: $state,
            nextStep: null,
            status: 200
        );
    }

    /**
     * POST /api/v1/admin/reconciliation/partner-statements
     * Ingest the licensed partner's pooled-account statement summary.
     */
    public function ingestPartnerStatement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'statement_reference' => ['required', 'string', 'max:80'],
            'provider_code' => ['nullable', 'string', 'max:32'],
            'settlement_account_reference' => ['required', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'size:3'],
            'statement_date' => ['required', 'date'],
            'opening_balance_minor' => ['required', 'integer'],
            'closing_balance_minor' => ['required', 'integer'],
            'total_inflow_minor' => ['nullable', 'integer', 'min:0'],
            'total_outflow_minor' => ['nullable', 'integer', 'min:0'],
            'line_count' => ['nullable', 'integer', 'min:0'],
            'source' => ['nullable', 'string', 'in:MANUAL,API,CSV_IMPORT'],
        ]);

        $statement = \App\Models\PartnerSettlementStatement::updateOrCreate(
            ['statement_reference' => $validated['statement_reference']],
            array_merge($validated, [
                'provider_code' => strtoupper((string) ($validated['provider_code'] ?? 'SELCOM_TZ')),
                'currency' => strtoupper((string) ($validated['currency'] ?? 'TZS')),
                'source' => (string) ($validated['source'] ?? 'MANUAL'),
                'ingested_by_user_id' => $request->user()->id,
            ])
        );

        return ApiResponse::success(
            message: 'Partner statement ingested. Run an invariant check to reconcile it against the ledger.',
            data: $statement,
            nextStep: 'run_invariant_check',
            status: 201
        );
    }
}
