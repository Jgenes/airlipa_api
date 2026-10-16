<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProtectedPayment\UpdateProviderCapabilityRequest;
use App\Http\Responses\ApiResponse;
use App\Services\PaymentOrchestrationService;
use App\Services\ProviderRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderWebhookController extends Controller
{
    public function __construct(
        protected ProviderRegistryService $providerRegistry,
        protected PaymentOrchestrationService $orchestrationService
    ) {}

    /**
     * List approved active payment providers from M18 Partner & Product Governance registry.
     * GET /api/v1/providers
     */
    public function listProviders(): JsonResponse
    {
        $providers = $this->providerRegistry->getActiveProviders();

        return ApiResponse::success(
            message: 'Approved licensed payment provider capabilities retrieved.',
            data: $providers,
            nextStep: null,
            status: 200
        );
    }

    /**
     * Receive and process an authenticated provider webhook callback (FR-009, FR-026, BPA-DEF-04).
     * POST /api/v1/webhooks/providers/{providerCode}
     */
    public function handleWebhook(Request $request, string $providerCode): JsonResponse
    {
        $rawPayload = (string) $request->getContent();
        $signature = (string) $request->header('X-Provider-Signature', '');
        $timestamp = (string) $request->header('X-Provider-Timestamp', '');
        $nonce = (string) $request->header('X-Provider-Nonce', '');

        $result = $this->orchestrationService->processProviderWebhook(
            providerCode: $providerCode,
            payload: $request->all(),
            rawPayload: $rawPayload,
            signature: $signature,
            timestamp: $timestamp,
            nonce: $nonce
        );

        $status = $result['processing_status'] === 'MISMATCH_BLOCKED' ? 202 : 200;

        return ApiResponse::success(
            message: $result['duplicate_ignored']
                ? 'Webhook callback already processed idempotently.'
                : 'Provider webhook callback verified and processed.',
            data: $result,
            nextStep: null,
            status: $status
        );
    }

    /**
     * Admin M18 emergency control: suspend or reactivate a provider capability (FR-037, FR-M18-06).
     * PATCH /api/v1/admin/providers/{providerCode}/status
     */
    public function updateCapabilityStatus(UpdateProviderCapabilityRequest $request, string $providerCode): JsonResponse
    {
        $capability = $this->providerRegistry->updateCapabilityStatus(
            providerCode: $providerCode,
            isActive: (bool) $request->validated('is_active'),
            admin: $request->user(),
            reason: (string) $request->validated('reason')
        );

        return ApiResponse::success(
            message: $capability->is_active
                ? "Provider capability '{$capability->provider_code}' activated."
                : "Provider capability '{$capability->provider_code}' suspended. Open transactions remain traceable.",
            data: $capability,
            nextStep: null,
            status: 200
        );
    }
}
