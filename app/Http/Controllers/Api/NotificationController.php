<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** List all notifications for the authenticated user. */
    public function index(Request $request)
    {
        return $this->visibleNotifications($request)
            ->paginate(min($request->integer('per_page', 15), 100));
    }

    /** List only unread notifications for the authenticated user. */
    public function unread(Request $request)
    {
        return $this->visibleNotifications($request)
            ->whereNull('read_at')
            ->paginate(min($request->integer('per_page', 15), 100));
    }

    /** Mark one owned notification as read. */
    public function read(Request $request, string $id)
    {
        $notification = $this->visibleNotifications($request)->findOrFail($id);
        $notification->markAsRead();

        return $notification;
    }

    /** Mark all unread notifications as read. */
    public function readAll(Request $request)
    {
        $this->visibleNotifications($request)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->noContent();
    }

    /** Limit notifications to the domain events relevant to the user's role. */
    private function visibleNotifications(Request $request): MorphMany
    {
        $notifications = $request->user()->notifications();

        if ($request->user()->role === UserRole::Admin) {
            $notifications->where('data->event', 'contact_message');
        }

        return $notifications;
    }
}
