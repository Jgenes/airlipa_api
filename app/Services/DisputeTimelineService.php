<?php

namespace App\Services;

use App\Enums\DisputeMessageType;
use App\Models\DisputeCase;
use App\Models\DisputeMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Dispute timeline and chat (Module 7).
 *
 * Every case carries a chronological, evidence-bearing record: system events
 * (freeze, decision, appeal, execution) are TIMELINE rows, party/admin
 * conversation is MESSAGE rows, and mediator decisions are DECISION rows.
 * Staff-only notes are flagged is_internal and hidden from parties.
 */
class DisputeTimelineService
{
    public function postMessage(DisputeCase $dispute, User $author, string $body, array $attachments = [], bool $internal = false): DisputeMessage
    {
        $role = $this->roleFor($dispute, $author, $internal);

        if ($role === null) {
            throw new AccessDeniedHttpException('Only the parties to this dispute or an authorized administrator can post messages.');
        }

        if (trim($body) === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'body' => ['A message body is required.'],
            ]);
        }

        return DB::transaction(function () use ($dispute, $author, $body, $attachments, $internal, $role): DisputeMessage {
            $message = DisputeMessage::create([
                'dispute_case_id' => $dispute->id,
                'author_user_id' => $author->id,
                'message_type' => DisputeMessageType::Message,
                'author_role' => $role,
                'body' => trim($body),
                'attachments' => $attachments === [] ? null : $attachments,
                'is_internal' => $internal,
            ]);

            AuditLogService::log('dispute.message_posted', $author, [
                'case_id' => $dispute->case_id,
                'message_id' => $message->id,
                'internal' => $internal,
            ]);

            return $message;
        });
    }

    /**
     * System timeline entry (freeze, decision, appeal, execution, case file).
     *
     * @param array<string, mixed> $metadata
     */
    public function recordEvent(DisputeCase $dispute, string $type, string $body, array $metadata = [], ?User $actor = null): DisputeMessage
    {
        return DisputeMessage::create([
            'dispute_case_id' => $dispute->id,
            'author_user_id' => $actor?->id,
            'message_type' => match (strtoupper($type)) {
                'DECISION' => DisputeMessageType::Decision,
                'APPEAL' => DisputeMessageType::Appeal,
                'TIMELINE' => DisputeMessageType::Timeline,
                default => DisputeMessageType::System,
            },
            'author_role' => $actor ? $this->roleFor($dispute, $actor, false) : 'SYSTEM',
            'body' => $body,
            'is_internal' => false,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * Full chronological timeline (internal notes filtered for parties).
     *
     * @return Collection<int, DisputeMessage>
     */
    public function timelineFor(DisputeCase $dispute, User $viewer): Collection
    {
        $isStaff = $viewer->isAdmin();

        if (!$isStaff && $viewer->id !== $dispute->opened_by_user_id
            && $viewer->id !== $dispute->protectedPayment?->buyer_id
            && $viewer->id !== $dispute->protectedPayment?->merchant_id) {
            throw new AccessDeniedHttpException('Only the parties to this dispute or an authorized administrator can view its timeline.');
        }

        return DisputeMessage::where('dispute_case_id', $dispute->id)
            ->when(!$isStaff, fn ($query) => $query->where('is_internal', false))
            ->with('author:id,first_name,last_name,user_type')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    protected function roleFor(DisputeCase $dispute, User $author, bool $internal): ?string
    {
        if ($author->isAdmin()) {
            return 'MEDIATOR';
        }

        $payment = $dispute->protectedPayment;

        if ($payment && $author->id === $payment->buyer_id) {
            return 'BUYER';
        }

        if ($payment && $author->id === $payment->merchant_id) {
            return 'SELLER';
        }

        return null;
    }
}
