<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\Notifications\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A user's own notifications (FR-NOTIF-01..03).
 *
 * No permission middleware anywhere in this controller, deliberately. These are
 * the caller's own records, and every query is bound to `$request->user()` -
 * there is no notion of "viewing someone else's notifications" to gate. A
 * permission here would be theatre; the ownership binding is the control.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->crmNotifications()->latest('created_at');

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate((int) $request->query('per_page', 25));

        return ApiResponse::paginated(
            $notifications,
            'Notifications retrieved.',
            // Carried in the list response so a client polling for new items
            // does not need a second round trip for the badge count.
            ['unread_count' => $this->notifications->unreadCount($request->user())],
        );
    }

    /** Just the badge number, for a cheap poll. */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ['unread_count' => $this->notifications->unreadCount($request->user())],
        );
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        // 404, not 403: a notification the caller does not own should not be
        // confirmed to exist by the error it returns.
        abort_unless(
            $this->notifications->markRead($notification, $request->user()),
            404,
        );

        return ApiResponse::success(message: 'Notification marked read.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->notifications->markAllRead($request->user());

        return ApiResponse::success(['marked' => $count], 'Notifications marked read.');
    }
}
