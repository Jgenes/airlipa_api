<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProtectedPayment\OpenDisputeRequest;
use App\Http\Requests\ProtectedPayment\ResolveDisputeRequest;
use App\Http\Resources\DisputeCaseResource;
use App\Http\Resources\ProtectedPaymentResource;
use App\Http\Responses\ApiResponse;
use App\Models\DisputeCase;
use App\Models\ProtectedPayment;
use App\Services\CaseFilePdfService;
use App\Services\DisputeService;
use App\Services\DisputeTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function __construct(
        protected DisputeService $disputeService,
        protected DisputeTimelineService $timelineService,
        protected CaseFilePdfService $caseFileService
    ) {}

    /**
     * Open a dispute case and atomically freeze the disputed protected balance (FR-014, FR-032).
     * POST /api/v1/protected-payments/{id}/disputes
     */
    public function store(OpenDisputeRequest $request, string $id): JsonResponse
    {
        $payment = ProtectedPayment::where('protected_payment_id', $id)
            ->orWhere('reference', $id)
            ->firstOrFail();

        $dispute = $this->disputeService->openDispute(
            payment: $payment,
            actor: $request->user(),
            data: $request->validated()
        );

        return ApiResponse::success(
            message: 'Dispute case opened. The disputed amount is now frozen and excluded from automatic release or settlement.',
            data: [
                'dispute' => new DisputeCaseResource($dispute),
                'agreement' => new ProtectedPaymentResource($payment->fresh(['feeQuote', 'merchant.businessProfile', 'buyer', 'milestones'])),
            ],
            nextStep: 'submit_dispute_evidence',
            status: 201
        );
    }

    /**
     * Retrieve details of a dispute case (FR-032).
     * GET /api/v1/disputes/{caseId}
     */
    public function show(Request $request, string $caseId): JsonResponse
    {
        $dispute = DisputeCase::with('protectedPayment')
            ->where('case_id', $caseId)
            ->orWhere('case_reference', $caseId)
            ->firstOrFail();

        $user = $request->user();
        $payment = $dispute->protectedPayment;

        if ($user->id !== $payment->buyer_id && $user->id !== $payment->merchant_id && !$user->isAdmin()) {
            return ApiResponse::error(
                message: 'Unauthorized access to this dispute case.',
                errors: null,
                nextStep: null,
                status: 403
            );
        }

        return ApiResponse::success(
            message: 'Dispute case retrieved successfully.',
            data: new DisputeCaseResource($dispute),
            nextStep: null,
            status: 200
        );
    }

    /**
     * Admin / Dispute Analyst resolves a dispute and triggers controlled release/refund execution (P-07, FR-013, FR-014).
     * POST /api/v1/admin/disputes/{caseId}/resolve
     */
    public function resolve(ResolveDisputeRequest $request, string $caseId): JsonResponse
    {
        $dispute = DisputeCase::with('protectedPayment')
            ->where('case_id', $caseId)
            ->orWhere('case_reference', $caseId)
            ->firstOrFail();

        $result = $this->disputeService->resolveDispute(
            dispute: $dispute,
            analyst: $request->user(),
            data: $request->validated(),
            idempotencyKey: $request->resolvedIdempotencyKey()
        );

        return ApiResponse::success(
            message: 'Dispute resolved and financial remedy executed.',
            data: [
                'dispute' => new DisputeCaseResource($result['dispute']),
                'agreement' => new ProtectedPaymentResource($result['agreement']),
            ],
            nextStep: null,
            status: 200
        );
    }

    /**
     * Chronological timeline and chat for a case (Module 7).
     * GET /api/v1/disputes/{caseId}/timeline
     */
    public function timeline(Request $request, string $caseId): JsonResponse
    {
        $dispute = $this->resolveCase($caseId);
        $messages = $this->timelineService->timelineFor($dispute, $request->user());

        return ApiResponse::success(
            message: 'Dispute timeline retrieved.',
            data: $messages->map(fn ($message): array => [
                'id' => $message->id,
                'type' => $message->message_type?->value,
                'role' => $message->author_role,
                'author' => trim((string) ($message->author?->first_name ?? '') . ' ' . (string) ($message->author?->last_name ?? '')) ?: 'Airlipa',
                'body' => $message->body,
                'attachments' => $message->attachments ?? [],
                'is_internal' => (bool) $message->is_internal,
                'at' => $message->created_at?->toIso8601String(),
            ])->values(),
            nextStep: null,
            status: 200
        );
    }

    /**
     * Post a message to the case chat (Module 7).
     * POST /api/v1/disputes/{caseId}/messages
     */
    public function storeMessage(Request $request, string $caseId): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*.name' => ['required_with:attachments', 'string', 'max:255'],
            'attachments.*.url' => ['required_with:attachments', 'string', 'max:2048'],
            'internal' => ['nullable', 'boolean'],
        ]);

        $dispute = $this->resolveCase($caseId);

        $message = $this->timelineService->postMessage(
            dispute: $dispute,
            author: $request->user(),
            body: (string) $validated['body'],
            attachments: (array) ($validated['attachments'] ?? []),
            internal: (bool) ($validated['internal'] ?? false)
        );

        return ApiResponse::success(
            message: 'Message added to the dispute record.',
            data: [
                'id' => $message->id,
                'type' => $message->message_type?->value,
                'body' => $message->body,
                'at' => $message->created_at?->toIso8601String(),
            ],
            nextStep: null,
            status: 201
        );
    }

    /**
     * File the single permitted appeal against an executed decision (Module 7).
     * POST /api/v1/disputes/{caseId}/appeal
     */
    public function appeal(Request $request, string $caseId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $dispute = $this->resolveCase($caseId);

        $updated = $this->disputeService->appealCase($dispute, $request->user(), (string) $validated['reason']);

        return ApiResponse::success(
            message: 'Appeal filed. An independent reviewer will reconsider this case.',
            data: new DisputeCaseResource($updated),
            nextStep: 'await_appeal_review',
            status: 201
        );
    }

    /**
     * Appeals reviewer upholds or escalates (Module 7).
     * POST /api/v1/admin/disputes/{caseId}/appeal-decision
     */
    public function decideAppeal(Request $request, string $caseId): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:UPHOLD,ESCALATE'],
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        $dispute = $this->resolveCase($caseId);

        $updated = $this->disputeService->decideAppeal(
            $dispute,
            $request->user(),
            (string) $validated['decision'],
            (string) $validated['notes']
        );

        return ApiResponse::success(
            message: 'Appeal decision recorded.',
            data: new DisputeCaseResource($updated),
            nextStep: null,
            status: 200
        );
    }

    /**
     * Download the generated PDF case file (Module 7).
     * GET /api/v1/disputes/{caseId}/case-file
     */
    public function caseFile(Request $request, string $caseId)
    {
        $dispute = $this->resolveCase($caseId);

        $this->timelineService->timelineFor($dispute, $request->user());

        $file = $this->caseFileService->generate($dispute);

        return response($this->caseFileService->contents($dispute), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $dispute->case_reference . '.pdf"',
            'X-Case-File-SHA256' => $file['file_hash'],
        ]);
    }

    protected function resolveCase(string $caseId): DisputeCase
    {
        return DisputeCase::with('protectedPayment')
            ->where('case_id', $caseId)
            ->orWhere('case_reference', $caseId)
            ->firstOrFail();
    }
}
