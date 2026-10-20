<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EscrowContract\AcceptEscrowContractRequest;
use App\Http\Requests\EscrowContract\RejectEscrowContractRequest;
use App\Http\Requests\EscrowContract\RequestContractChangesRequest;
use App\Http\Resources\EscrowContractResource;
use App\Http\Responses\ApiResponse;
use App\Services\ContractShareService;
use App\Services\EscrowContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractShareController extends Controller
{
    public function __construct(
        protected ContractShareService $shareService,
        protected EscrowContractService $contractService
    ) {}

    /**
     * Anonymous read-only contract view via signed token (Module 2).
     * GET /api/v1/contracts/share/{token}
     */
    public function publicShow(string $token): JsonResponse
    {
        $share = $this->shareService->resolveToken($token);
        $this->shareService->recordView($share);

        return ApiResponse::success(
            message: 'Escrow contract retrieved.',
            data: $this->contractService->publicSummary($share->refresh()) + [
                'share' => [
                    'channel' => $share->channel->value,
                    'view_count' => (int) $share->view_count,
                ],
            ],
            nextStep: 'register_or_login_then_accept',
            status: 200
        );
    }

    /**
     * Issue the acceptance OTP to the verified counterparty (Module 3).
     * POST /api/v1/contracts/share/{token}/acceptance-otp
     */
    public function requestAcceptanceOtp(Request $request, string $token): JsonResponse
    {
        $share = $this->shareService->resolveToken($token);
        $this->contractService->requestAcceptanceOtp($share, $request->user());

        return ApiResponse::success(
            message: 'Verification code sent via SMS and email.',
            data: null,
            nextStep: 'accept_contract_with_otp',
            status: 200
        );
    }

    /**
     * Counterparty acceptance with OTP e-signature (Module 3).
     * POST /api/v1/contracts/share/{token}/accept
     */
    public function accept(AcceptEscrowContractRequest $request, string $token): JsonResponse
    {
        $share = $this->shareService->resolveToken($token);

        $result = $this->contractService->accept($share, $request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Contract accepted and signed. Escrow deposit instructions are now active.',
            data: [
                'contract' => (new EscrowContractResource($result['contract']->load(['versions', 'signatures', 'subledgerAccount'])))->resolve(),
                'protected_payment_id' => $result['payment']->protected_payment_id,
                'reference' => $result['payment']->reference,
                'handover_otp' => $result['handover_otp'],
            ],
            nextStep: 'deposit_into_escrow',
            status: 200
        );
    }

    /**
     * Counterparty rejection.
     * POST /api/v1/contracts/share/{token}/reject
     */
    public function reject(RejectEscrowContractRequest $request, string $token): JsonResponse
    {
        $share = $this->shareService->resolveToken($token);

        $contract = $this->contractService->reject($share, $request->user(), $request->validated('reason'));

        return ApiResponse::success(
            message: 'Contract rejected. The initiator has been notified.',
            data: (new EscrowContractResource($contract))->resolve(),
            nextStep: null,
            status: 200
        );
    }

    /**
     * Counter-proposal with versioning (Module 3).
     * POST /api/v1/contracts/share/{token}/request-changes
     */
    public function requestChanges(RequestContractChangesRequest $request, string $token): JsonResponse
    {
        $share = $this->shareService->resolveToken($token);

        $version = $this->contractService->requestChanges($share, $request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Changes proposed. The initiator must approve before the contract can be accepted.',
            data: [
                'version' => (int) $version->version,
                'terms_hash' => $version->terms_hash,
                'status' => $version->status->value,
            ],
            nextStep: 'initiator_approval',
            status: 201
        );
    }
}
