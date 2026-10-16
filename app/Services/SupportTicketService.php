<?php

namespace App\Services;

use App\Models\DisputeCase;
use App\Models\ProtectedPayment;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SupportTicketService
{
    public function __construct(
        private readonly NotificationOutboxService $outboxService
    ) {}

    /**
     * Open a customer support ticket linked to a ProtectedPayment or DisputeCase (M13, FR-025).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function openTicket(User $user, array $attributes): SupportTicket
    {
        return DB::transaction(function () use ($user, $attributes) {
            $protectedPayment = null;
            if (! empty($attributes['protected_payment_id'])) {
                $protectedPayment = ProtectedPayment::where('protected_payment_id', $attributes['protected_payment_id'])->firstOrFail();
                if ($protectedPayment->buyer_id !== $user->id && $protectedPayment->merchant_id !== $user->id && ! $user->isAdmin()) {
                    throw new AccessDeniedHttpException('You can only link a support ticket to your own Protected Payment transaction.');
                }
            }

            $disputeCase = null;
            if (! empty($attributes['dispute_case_id'])) {
                $disputeCase = DisputeCase::where('case_id', $attributes['dispute_case_id'])->firstOrFail();
            }

            $priority = strtoupper((string) ($attributes['priority'] ?? 'NORMAL'));
            $firstResponseHours = match ($priority) {
                'URGENT' => 2,
                'HIGH' => 4,
                default => 12,
            };

            $ticket = SupportTicket::create([
                'ticket_number' => 'TKT-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6)),
                'user_id' => $user->id,
                'protected_payment_id' => $protectedPayment?->id,
                'dispute_case_id' => $disputeCase?->id,
                'category' => strtoupper((string) ($attributes['category'] ?? 'OTHER')),
                'priority' => $priority,
                'subject' => $attributes['subject'],
                'status' => 'OPEN',
                'first_response_due_at' => now()->addHours($firstResponseHours),
                'resolution_due_at' => now()->addHours($firstResponseHours * 4),
            ]);

            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender_user_id' => $user->id,
                'is_staff_reply' => $user->isAdmin(),
                'message' => $attributes['message'],
            ]);

            $this->outboxService->publishEvent(
                aggregateType: 'SUPPORT_TICKET',
                aggregateId: $ticket->ticket_number,
                eventType: 'SUPPORT_TICKET_OPENED',
                payload: [
                    'ticket_number' => $ticket->ticket_number,
                    'category' => $ticket->category,
                    'priority' => $ticket->priority,
                ],
                recipientUser: $user,
                titleEn: "Support Ticket {$ticket->ticket_number} Received",
                messageEn: "We have received your support request '{$ticket->subject}'. Our team will respond within {$firstResponseHours} hours.",
                titleSw: "Tiketi ya Huduma {$ticket->ticket_number} Imepokelewa",
                messageSw: "Tumepokea ombi lako la msaada '{$ticket->subject}'. Timu yetu itakujibu ndani ya saa {$firstResponseHours}."
            );

            AuditLogService::log('support.ticket.opened', $user, [
                'ticket_number' => $ticket->ticket_number,
                'category' => $ticket->category,
            ]);

            return $ticket->load('messages');
        });
    }

    /**
     * Add a reply message to an existing support ticket (M13).
     */
    public function addMessage(SupportTicket $ticket, User $sender, string $message): SupportTicketMessage
    {
        if ($ticket->user_id !== $sender->id && ! $sender->isAdmin()) {
            throw new AccessDeniedHttpException('You are not authorized to reply to this support ticket.');
        }

        $msg = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_user_id' => $sender->id,
            'is_staff_reply' => $sender->isAdmin(),
            'message' => $message,
        ]);

        if ($sender->isAdmin() && $ticket->status === 'OPEN') {
            $ticket->update(['status' => 'IN_PROGRESS']);
        }

        return $msg;
    }
}
