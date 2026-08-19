<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** List all notifications for the authenticated user. */
    public function index(Request $request)
    {
        return $request->user()->notifications()
            ->paginate(min($request->integer('per_page', 15), 100));
    }

    /** List only unread notifications for the authenticated user. */
    public function unread(Request $request)
    {
        return $request->user()->unreadNotifications()
            ->paginate(min($request->integer('per_page', 15), 100));
    }

    /** Mark one owned notification as read. */
    public function read(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return $notification;
    }

    /** Mark all unread notifications as read. */
    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->noContent();
    }
}
