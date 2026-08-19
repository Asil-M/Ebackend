<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\DomainNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    /** Send an authenticated support message to every administrator. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:3000'],
        ]);

        $sender = $request->user();
        $payload = [
            'event' => 'contact_message',
            'sender_id' => $sender->id,
            'sender_name' => trim($sender->first_name.' '.$sender->last_name),
            'sender_email' => $sender->email,
            'sender_role' => $sender->role->value,
            'subject' => $validated['subject'],
            'message' => $validated['message'],
        ];

        User::query()
            ->where('role', UserRole::Admin)
            ->each(fn (User $admin) =>
                $admin->notify(new DomainNotification($payload))
            );

        return response()->json([
            'message' => 'Your message was sent to the administrators.',
        ], 201);
    }
}
