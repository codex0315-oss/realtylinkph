<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The notifications panel: list, read / unread, delete one, clear read.
 *
 * Chat messages (`new_message`) are left out of the list — they live in the
 * Messages panel, counted from the conversations themselves. Without this a
 * busy chat filled the 30-row window and pushed real alerts out of the bell.
 */
class NotificationController extends Controller
{
    /** Types that belong to Messages, not the bell. */
    private const CHAT_TYPES = ['new_message'];

    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->whereNotIn('type', self::CHAT_TYPES)
            ->latest()
            ->limit(60)
            ->get()
            ->map(fn ($n) => [
                'id'         => $n->id,
                'type'       => $n->type,
                'payload'    => $n->data,
                'read_at'    => $n->read_at?->toISOString(),
                'created_at' => $n->created_at?->toISOString(),
            ]);

        return ApiResponse::success($notifications, 'Notifications retrieved.');
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->where('id', $id)->update(['read_at' => now()]);

        return ApiResponse::success(null, 'Notification marked as read.');
    }

    /** Flip one back to unread — "remind me later" from the panel. */
    public function markUnread(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->where('id', $id)->update(['read_at' => null]);

        return ApiResponse::success(null, 'Notification marked as unread.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return ApiResponse::success(null, 'All notifications marked as read.');
    }

    /** Only ever the caller's own row — the relation scopes it. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->where('id', $id)->delete();

        return ApiResponse::success(null, 'Notification deleted.');
    }

    /** Clear every read notification (unread ones are kept). */
    public function clearRead(Request $request): JsonResponse
    {
        $deleted = $request->user()->notifications()
            ->whereNotNull('read_at')
            ->whereNotIn('type', self::CHAT_TYPES)
            ->delete();

        return ApiResponse::success(['deleted' => $deleted], 'Read notifications cleared.');
    }
}
