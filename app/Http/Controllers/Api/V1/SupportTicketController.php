<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $ticketService
    ) {}

    /**
     * GET /api/v1/support/tickets
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $query = SupportTicket::with('messages')->orderByDesc('id');
        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        $tickets = $query->get()->map(fn (SupportTicket $t) => $this->formatTicket($t));

        return ApiResponse::success(
            message: 'Support tickets retrieved successfully.',
            data: ['tickets' => $tickets]
        );
    }

    /**
     * POST /api/v1/support/tickets
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'protected_payment_id' => ['nullable', 'string', 'max:64'],
            'dispute_case_id' => ['nullable', 'string', 'max:64'],
            'category' => ['required', 'string', 'in:PAYMENT_DELAY,RELEASE_HELP,DISPUTE_ESCALATION,PAYOUT_INQUIRY,OTHER'],
            'priority' => ['nullable', 'string', 'in:LOW,NORMAL,HIGH,URGENT'],
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:3000'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $ticket = $this->ticketService->openTicket($user, $validated);

        return ApiResponse::success(
            message: 'Support ticket opened successfully.',
            data: $this->formatTicket($ticket),
            nextStep: 'await_support_response',
            status: 201
        );
    }

    /**
     * GET /api/v1/support/tickets/{ticketNumber}
     */
    public function show(Request $request, string $ticketNumber): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $ticket = SupportTicket::with('messages')->where('ticket_number', $ticketNumber)->firstOrFail();

        if ($ticket->user_id !== $user->id && ! $user->isAdmin()) {
            throw new AccessDeniedHttpException('You do not have permission to view this support ticket.');
        }

        return ApiResponse::success(
            message: 'Support ticket retrieved successfully.',
            data: $this->formatTicket($ticket)
        );
    }

    /**
     * POST /api/v1/support/tickets/{ticketNumber}/messages
     */
    public function storeMessage(Request $request, string $ticketNumber): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:3000'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $ticket = SupportTicket::where('ticket_number', $ticketNumber)->firstOrFail();

        $this->ticketService->addMessage($ticket, $user, $validated['message']);

        return ApiResponse::success(
            message: 'Reply added to support ticket.',
            data: $this->formatTicket($ticket->fresh(['messages'])),
            status: 201
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTicket(SupportTicket $ticket): array
    {
        return [
            'ticket_number' => $ticket->ticket_number,
            'user_id' => $ticket->user_id,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'first_response_due_at' => $ticket->first_response_due_at?->toIso8601String(),
            'resolution_due_at' => $ticket->resolution_due_at?->toIso8601String(),
            'messages' => $ticket->messages->map(fn ($m) => [
                'id' => $m->id,
                'sender_user_id' => $m->sender_user_id,
                'is_staff_reply' => $m->is_staff_reply,
                'message' => $m->message,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->all(),
            'created_at' => $ticket->created_at?->toIso8601String(),
        ];
    }
}
