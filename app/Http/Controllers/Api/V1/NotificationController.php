<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /api/v1/notifications
     * Retrieve bilingual EN/SW user notifications (M12, FR-024).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $lang = strtolower((string) $request->query('lang', 'en')) === 'sw' ? 'sw' : 'en';

        $notifications = UserNotification::where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (UserNotification $n) => [
                'notification_id' => $n->notification_id,
                'event_type' => $n->event_type,
                'language' => $lang,
                'title' => $lang === 'sw' ? $n->title_sw : $n->title_en,
                'message' => $lang === 'sw' ? $n->message_sw : $n->message_en,
                'title_en' => $n->title_en,
                'message_en' => $n->message_en,
                'title_sw' => $n->title_sw,
                'message_sw' => $n->message_sw,
                'metadata' => $n->metadata,
                'is_read' => $n->is_read,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return ApiResponse::success(
            message: 'Notifications retrieved successfully.',
            data: [
                'language' => $lang,
                'unread_count' => UserNotification::where('user_id', $user->id)->where('is_read', false)->count(),
                'notifications' => $notifications,
            ]
        );
    }

    /**
     * PATCH /api/v1/notifications/{notificationId}/read
     */
    public function markAsRead(Request $request, string $notificationId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $notification = UserNotification::where('notification_id', $notificationId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return ApiResponse::success(
            message: 'Notification marked as read.',
            data: [
                'notification_id' => $notification->notification_id,
                'is_read' => true,
                'read_at' => $notification->read_at?->toIso8601String(),
            ]
        );
    }
}
