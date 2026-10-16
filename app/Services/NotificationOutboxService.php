<?php

namespace App\Services;

use App\Models\OutboxEvent;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class NotificationOutboxService
{
    /**
     * Record a transactional outbox event and create bilingual EN/SW user notifications (M12, FR-024, FR-028).
     * Never represents PENDING or UNKNOWN states as completed/paid.
     *
     * @param  array<string, mixed>  $payload
     */
    public function publishEvent(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload,
        ?User $recipientUser = null,
        ?string $titleEn = null,
        ?string $messageEn = null,
        ?string $titleSw = null,
        ?string $messageSw = null
    ): OutboxEvent {
        $correlationId = Request::header('X-Correlation-Id')
            ?? ($payload['correlation_id'] ?? ('corr_' . Str::lower(Str::random(24))));

        $event = OutboxEvent::create([
            'event_id' => (string) Str::uuid(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => 'PUBLISHED',
            'correlation_id' => is_string($correlationId) ? substr($correlationId, 0, 64) : null,
            'published_at' => now(),
        ]);

        if ($recipientUser && $titleEn && $messageEn) {
            UserNotification::create([
                'notification_id' => (string) Str::uuid(),
                'user_id' => $recipientUser->id,
                'outbox_event_id' => $event->id,
                'event_type' => $eventType,
                'title_en' => $titleEn,
                'message_en' => $messageEn,
                'title_sw' => $titleSw ?? $titleEn,
                'message_sw' => $messageSw ?? $messageEn,
                'metadata' => $payload,
                'is_read' => false,
            ]);
        }

        return $event;
    }
}
