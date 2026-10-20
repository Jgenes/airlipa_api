<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EscrowContract\CancelEscrowContractRequest;
use App\Http\Requests\EscrowContract\ShareEscrowContractRequest;
use App\Http\Requests\EscrowContract\StoreEscrowContractRequest;
use App\Http\Requests\EscrowContract\UpdateEscrowContractRequest;
use App\Http\Resources\EscrowContractResource;
use App\Http\Responses\ApiResponse;
use App\Models\EscrowContract;
use App\Services\EscrowContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class EscrowContractController extends Controller
{
    public function __construct(
        protected EscrowContractService $contractService
    ) {}

    /**
     * List contracts I initiated or was invited to.
     * GET /api/v1/contracts
     */
    public function index(Request $request): JsonResponse
    {
        $contracts = EscrowContract::with(['protectedPayment', 'subledgerAccount'])
            ->where(fn ($query) => $query
                ->where('initiator_id', $request->user()->id)
                ->orWhere('counterparty_user_id', $request->user()->id))
            ->latest()
            ->paginate(20);

        return ApiResponse::success(
            message: 'Escrow contracts retrieved successfully.',
            data: EscrowContractResource::collection($contracts->items())->resolve(),
            nextStep: null,
            status: 200
        );
    }

    /**
     * Create a DRAFT escrow contract (Module 1).
     * POST /api/v1/contracts
     */
    public function store(StoreEscrowContractRequest $request): JsonResponse
    {
        $contract = $this->contractService->createDraft($request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Escrow contract draft created.',
            data: (new EscrowContractResource($contract))->resolve(),
            nextStep: 'share_contract',
            status: 201
        );
    }

    /**
     * GET /api/v1/contracts/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $contract = EscrowContract::with(['versions', 'signatures', 'subledgerAccount', 'protectedPayment'])
            ->whereKey($id)
            ->firstOrFail();

        $this->authorizeParty($contract, $request);

        $relations = ['versions', 'signatures', 'subledgerAccount', 'protectedPayment'];

        if ((int) $contract->initiator_id === (int) $request->user()->id) {
            $relations[] = 'shares';
        }

        return ApiResponse::success(
            message: 'Escrow contract retrieved successfully.',
            data: (new EscrowContractResource($contract->load($relations)))->resolve(),
            nextStep: null,
            status: 200
        );
    }

    /**
     * Update a DRAFT contract before sharing.
     * PATCH /api/v1/contracts/{id}
     */
    public function update(UpdateEscrowContractRequest $request, int $id): JsonResponse
    {
        $contract = EscrowContract::whereKey($id)->firstOrFail();

        $updated = $this->contractService->updateDraft($request->user(), $contract, $request->validated());

        return ApiResponse::success(
            message: 'Escrow contract draft updated.',
            data: (new EscrowContractResource($updated->load('versions')))->resolve(),
            nextStep: 'share_contract',
            status: 200
        );
    }

    /**
     * Share through WhatsApp / SMS / Email / QR / secure link (Module 2).
     * POST /api/v1/contracts/{id}/share
     */
    public function share(ShareEscrowContractRequest $request, int $id): JsonResponse
    {
        $contract = EscrowContract::whereKey($id)->firstOrFail();
        $validated = $request->validated();

        $result = $this->contractService->share(
            $request->user(),
            $contract,
            (array) $validated['channels'],
            $validated['message'] ?? null
        );

        return ApiResponse::success(
            message: 'Escrow contract shared with the counterparty.',
            data: [
                'contract' => (new EscrowContractResource($result['contract']))->resolve(),
                'shares' => collect($result['shares'])->map(fn ($share): array => [
                    'share_id' => $share->share_id,
                    'channel' => $share->channel->value,
                    'status' => $share->status->value,
                    'token' => $share->token,
                    'token_expires_at' => $share->token_expires_at?->toIso8601String(),
                    'payload' => $share->payload,
                    'failure_reason' => $share->failure_reason,
                ])->all(),
            ],
            nextStep: 'counterparty_acceptance',
            status: 200
        );
    }

    /**
     * Deposit QR for the contract's underlying Protected Payment (Module 4).
     * GET /api/v1/contracts/{id}/deposit-qr
     */
    public function depositQr(Request $request, int $id): JsonResponse
    {
        $contract = EscrowContract::with('protectedPayment')->whereKey($id)->firstOrFail();

        $this->authorizeParty($contract, $request);

        if (!$contract->protectedPayment) {
            return ApiResponse::error('This contract has not been accepted yet, so no deposit QR exists.', null, 'accept_contract_first', 422);
        }

        return ApiResponse::success(
            message: 'Dynamic deposit QR generated. Any wallet or bank app can scan and pay.',
            data: app(\App\Services\QrDepositService::class)->buildDepositQr($contract->protectedPayment),
            nextStep: 'scan_and_pay',
            status: 200
        );
    }

    /**
     * Approve a counter-proposal version (both-party approval rule).
     * POST /api/v1/contracts/{id}/versions/{version}/approve
     */
    public function approveVersion(Request $request, int $id, int $version): JsonResponse
    {
        $contract = EscrowContract::whereKey($id)->firstOrFail();

        $updated = $this->contractService->approveVersion($request->user(), $contract, $version);

        return ApiResponse::success(
            message: "Version v{$version} approved and now current.",
            data: (new EscrowContractResource($updated->load('versions')))->resolve(),
            nextStep: 'share_contract',
            status: 200
        );
    }

    /**
     * Cancel before acceptance.
     * POST /api/v1/contracts/{id}/cancel
     */
    public function cancel(CancelEscrowContractRequest $request, int $id): JsonResponse
    {
        $contract = EscrowContract::whereKey($id)->firstOrFail();

        $cancelled = $this->contractService->cancel($request->user(), $contract, $request->validated('reason'));

        return ApiResponse::success(
            message: 'Escrow contract cancelled.',
            data: (new EscrowContractResource($cancelled))->resolve(),
            nextStep: null,
            status: 200
        );
    }

    protected function authorizeParty(EscrowContract $contract, Request $request): void
    {
        $userId = (int) $request->user()->id;

        if ((int) $contract->initiator_id !== $userId && (int) $contract->counterparty_user_id !== $userId) {
            throw new AccessDeniedHttpException('You are not a party to this contract.');
        }
    }
}
