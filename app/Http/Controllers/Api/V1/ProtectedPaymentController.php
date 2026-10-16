<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProtectedPayment\CreateProtectedPaymentRequest;
use App\Http\Requests\ProtectedPayment\InitiatePaymentIntentRequest;
use App\Http\Requests\ProtectedPayment\RefundProtectedPaymentRequest;
use App\Http\Requests\ProtectedPayment\ReleaseProtectedPaymentRequest;
use App\Http\Requests\ProtectedPayment\SubmitFulfillmentEvidenceRequest;
use App\Http\Resources\FulfillmentEvidenceResource;
use App\Http\Resources\LedgerJournalResource;
use App\Http\Resources\ProtectedPaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\ProtectedPayment;
use App\Services\PaymentOrchestrationService;
use App\Services\PaymentProviders\PaymentProviderAdapterInterface;
use App\Services\ProtectedPaymentService;
use App\Services\ProviderRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProtectedPaymentController extends Controller
{
    public function __construct(
        protected ProtectedPaymentService $protectedPaymentService,
        protected PaymentOrchestrationService $orchestrationService,
        protected ProviderRegistryService $providerRegistry,
        protected PaymentProviderAdapterInterface $providerAdapter
    ) {}

    /**
     * List Protected Payment agreements where the authenticated user is buyer or merchant.
     * GET /api/v1/protected-payments
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = ProtectedPayment::with(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones'])
            ->where(function ($q) use ($user) {
                $q->where('buyer_id', $user->id)
                    ->orWhere('merchant_id', $user->id);
            })
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', strtoupper((string) $request->query('status')));
        }

        return ApiResponse::success(
            message: 'Protected Payment agreements retrieved successfully.',
            data: ProtectedPaymentResource::collection($query->get()),
            nextStep: null,
            status: 200
        );
    }

    /**
     * Create a direct Protected Payment agreement (FR-006, FR-010).
     * POST /api/v1/protected-payments
     */
    public function store(CreateProtectedPaymentRequest $request): JsonResponse
    {
        $result = $this->protectedPaymentService->createDirectAgreement(
            $request->user(),
            $request->validated()
        );

        return ApiResponse::success(
            message: 'Protected Payment agreement recorded. Proceed to initiate provider funding.',
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
     * Get details of a specific Protected Payment agreement (enforces tenant/party isolation FR-035).
     * GET /api/v1/protected-payments/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $payment = $this->resolveAuthorizedPayment($request, $id);

        return ApiResponse::success(
            message: 'Protected Payment agreement retrieved successfully.',
            data: new ProtectedPaymentResource($payment->load(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones'])),
            nextStep: null,
            status: 200
        );
    }

    /**
     * Initiate a payment intent and provider collection attempt (FR-007, FR-008, BPA-CH-03).
     * POST /api/v1/protected-payments/{id}/pay
     */
    public function initiatePayment(InitiatePaymentIntentRequest $request, string $id): JsonResponse
    {
        $payment = $this->resolveAuthorizedPayment($request, $id);

        $idempotencyKey = $request->resolvedIdempotencyKey();
        if ($idempotencyKey === '') {
            return ApiResponse::error(
                message: 'Validation failed. An Idempotency-Key header or idempotency_key field is required for financial commands (BPA-CH-03 / FR-007).',
                errors: ['idempotency_key' => ['The idempotency_key is required.']],
                nextStep: null,
                status: 422
            );
        }

        $result = $this->orchestrationService->initiatePayment(
            payment: $payment,
            buyer: $request->user(),
            data: $request->validated(),
            idempotencyKey: $idempotencyKey
        );

        $statusCode = $result['idempotent_replay'] ? 200 : 202;

        return ApiResponse::success(
            message: $result['idempotent_replay']
                ? 'Idempotent payment request returned existing payment attempt. Do not pay again.'
                : 'Payment instruction submitted to licensed provider. Do not make another payment while verification is in progress.',
            data: [
                'idempotent_replay' => $result['idempotent_replay'],
                'intent_id' => $result['intent']->intent_id,
                'intent_status' => $result['intent']->status->value,
                'attempt_id' => $result['attempt']->attempt_id,
                'provider_code' => $result['attempt']->provider_code,
                'provider_reference' => $result['attempt']->provider_reference,
                'normalized_status' => $result['attempt']->normalized_status,
                'amount_minor' => (int) $result['intent']->amount_minor,
                'currency' => $result['intent']->currency,
                'provider_instructions' => $result['provider_instructions'],
                'agreement' => new ProtectedPaymentResource($payment->fresh(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones'])),
            ],
            nextStep: 'await_provider_confirmation',
            status: $statusCode
        );
    }

    /**
     * Safe interrupted-session / timeout status recovery endpoint (SASDS 3.4.7, 4.3.8, BPA-CH-10).
     * GET /api/v1/protected-payments/{id}/status-recovery
     */
    public function statusRecovery(Request $request, string $id): JsonResponse
    {
        $payment = $this->resolveAuthorizedPayment($request, $id);

        $recovery = $this->orchestrationService->recoverAndCheckStatus($payment);

        return ApiResponse::success(
            message: 'Authoritative payment recovery status retrieved.',
            data: $recovery,
            nextStep: $recovery['recovery_state'] === 'CONFIRMED' ? 'submit_fulfillment_evidence' : 'await_provider_confirmation',
            status: 200
        );
    }

    /**
     * Local/Testing helper to simulate an HMAC-SHA256 signed provider callback for the latest attempt.
     * POST /api/v1/protected-payments/{id}/simulate-provider-callback
     */
    public function simulateProviderCallback(Request $request, string $id): JsonResponse
    {
        if (app()->environment('production')) {
            abort(403, 'Provider callback simulation is disabled in production.');
        }

        $payment = $this->resolveAuthorizedPayment($request, $id);
        $payment->loadMissing('latestPaymentIntent.latestAttempt');

        $intent = $payment->latestPaymentIntent;
        $attempt = $intent?->latestAttempt;

        if (! $intent || ! $attempt) {
            return ApiResponse::error('No payment attempt exists on this agreement to simulate a callback for.', null, null, 422);
        }

        $capability = $this->providerRegistry->getCapability($attempt->provider_code);
        $simulatedStatus = strtoupper((string) $request->input('status', 'SUCCESS'));
        if ($simulatedStatus === 'SUCCEEDED') {
            $simulatedStatus = 'SUCCESS';
        }

        $payload = [
            'event_id' => (string) $request->input('event_id', 'evt_sim_' . Str::lower(Str::random(16))),
            'event_type' => 'ProviderPaymentConfirmed',
            'attempt_id' => $attempt->attempt_id,
            'provider_reference' => $attempt->provider_reference,
            'provider_transaction_id' => $attempt->provider_reference,
            'status' => $simulatedStatus,
            'amount_minor' => (int) $request->input('amount_minor', $intent->amount_minor),
            'currency' => (string) $request->input('currency', $intent->currency),
        ];

        $rawPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $nonce = 'nonce_' . Str::lower(Str::random(12));
        $signature = $this->providerAdapter->signWebhookPayload($capability->webhook_secret, $rawPayload, $timestamp, $nonce);

        $result = $this->orchestrationService->processProviderWebhook(
            providerCode: $capability->provider_code,
            payload: $payload,
            rawPayload: $rawPayload,
            signature: $signature,
            timestamp: $timestamp,
            nonce: $nonce
        );

        return ApiResponse::success(
            message: 'Simulated provider callback signed and processed.',
            data: $result,
            nextStep: $result['protected_payment_status'] === 'FUNDED_PROTECTED' ? 'submit_fulfillment_evidence' : null,
            status: 200
        );
    }

    /**
     * Submit fulfillment / delivery evidence (FR-015, FR-033, SASDS 3.1.4).
     * POST /api/v1/protected-payments/{id}/evidence
     */
    public function submitEvidence(SubmitFulfillmentEvidenceRequest $request, string $id): JsonResponse
    {
        $payment = $this->resolveAuthorizedPayment($request, $id);

        $evidence = $this->protectedPaymentService->submitFulfillmentEvidence(
            payment: $payment,
            actor: $request->user(),
            data: $request->validated(),
            file: $request->file('evidence_file')
        );

        return ApiResponse::success(
            message: 'Fulfillment evidence recorded with tamper-evident hash. Note: Evidence submission informs the workflow and does not automatically release funds.',
            data: [
                'evidence' => new FulfillmentEvidenceResource($evidence),
                'agreement' => new ProtectedPaymentResource($payment->fresh(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones'])),
            ],
            nextStep: 'review_and_release_or_dispute',
            status: 201
        );
    }

    /**
     * Authorize and execute a full or milestone release of Protected Payment funds (FR-011, FR-012).
     * POST /api/v1/protected-payments/{id}/release
     */
    public function release(ReleaseProtectedPaymentRequest $request, string $id): JsonResponse
    {
        $payment = $this->resolveAuthorizedPayment($request, $id);

        $idempotencyKey = $request->resolvedIdempotencyKey() ?: ('rel_' . Str::lower(Str::ulid()));

        $result = $this->protectedPaymentService->releaseProtectedPayment(
            payment: $payment,
            actor: $request->user(),
            data: $request->validated(),
            idempotencyKey: $idempotencyKey
        );

        return ApiResponse::success(
            message: $result['idempotent_replay']
                ? 'Idempotent release command returned existing confirmed release.'
                : 'Protected Payment release authorized and confirmed with licensed provider.',
            data: [
                'idempotent_replay' => $result['idempotent_replay'],
                'agreement' => new ProtectedPaymentResource($result['agreement']),
                'journal' => new LedgerJournalResource($result['journal']),
            ],
            nextStep: 'view_receipt',
            status: 200
        );
    }

    /**
     * Authorize and execute a full or partial customer refund using original quote version (FR-013, BPA-REV-14).
     * POST /api/v1/protected-payments/{id}/refund
     */
    public function refund(RefundProtectedPaymentRequest $request, string $id): JsonResponse
    {
        $payment = $this->resolveAuthorizedPayment($request, $id);

        $idempotencyKey = $request->resolvedIdempotencyKey() ?: ('rfd_' . Str::lower(Str::ulid()));

        $result = $this->protectedPaymentService->refundProtectedPayment(
            payment: $payment,
            actor: $request->user(),
            data: $request->validated(),
            idempotencyKey: $idempotencyKey
        );

        return ApiResponse::success(
            message: $result['idempotent_replay']
                ? 'Idempotent refund command returned existing confirmed refund.'
                : 'Customer refund authorized and executed using original accepted price-book rules.',
            data: [
                'idempotent_replay' => $result['idempotent_replay'],
                'agreement' => new ProtectedPaymentResource($result['agreement']),
                'refund_breakdown' => $result['refund_breakdown'] ?? null,
                'journal' => new LedgerJournalResource($result['journal']),
            ],
            nextStep: 'view_receipt',
            status: 200
        );
    }

    /**
     * Retrieve the canonical verifiable receipt for a Protected Payment (FR-023).
     * GET /api/v1/protected-payments/{id}/receipt
     */
    public function receipt(Request $request, string $id): JsonResponse
    {
        $payment = $this->resolveAuthorizedPayment($request, $id);

        $receipt = $this->protectedPaymentService->generateReceipt($payment);

        return ApiResponse::success(
            message: 'Canonical transaction receipt retrieved.',
            data: $receipt,
            nextStep: null,
            status: 200
        );
    }

    /**
     * Retrieve balanced double-entry ledger journals for a Protected Payment (M08).
     * GET /api/v1/protected-payments/{id}/journals
     */
    public function journals(Request $request, string $id): JsonResponse
    {
        $payment = $this->resolveAuthorizedPayment($request, $id);

        $journals = $payment->journals()->with('postings.account')->orderBy('id')->get();

        return ApiResponse::success(
            message: 'Balanced operational sub-ledger journals retrieved.',
            data: LedgerJournalResource::collection($journals),
            nextStep: null,
            status: 200
        );
    }

    /**
     * Resolve a ProtectedPayment by protected_payment_id or reference and enforce party/tenant isolation (FR-035).
     */
    protected function resolveAuthorizedPayment(Request $request, string $id): ProtectedPayment
    {
        $payment = ProtectedPayment::where('protected_payment_id', $id)
            ->orWhere('reference', $id)
            ->firstOrFail();

        $user = $request->user();
        if (!$user || ($user->id !== $payment->buyer_id && $user->id !== $payment->merchant_id && !$user->isAdmin())) {
            abort(403, 'FR-035 tenant isolation: You are not authorized to access this Protected Payment transaction.');
        }

        return $payment;
    }
}
