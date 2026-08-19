<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeamAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\User;
use App\Notifications\DomainNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AdminAccountController extends Controller
{
    /** List user and team accounts. Admin accounts are intentionally excluded. */
    public function index(Request $request)
    {
        $accounts = User::query()
            ->where('role', '!=', UserRole::Admin)
            ->with(['sosTeam', 'donationTeam'])
            ->when(
                $request->filled('role'),
                fn ($query) => $query->where(
                    'role',
                    $request->string('role')->toString()
                )
            )
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim($request->string('search')->toString());
                $terms = preg_split('/\s+/', $search, flags: PREG_SPLIT_NO_EMPTY);
                $query->where(function ($query) use ($search, $terms) {
                    $query->where('id', $search)
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere(function ($query) use ($terms) {
                            foreach ($terms as $term) {
                                $query->where(function ($query) use ($term) {
                                    $query->where('first_name', 'like', "%{$term}%")
                                        ->orWhere('last_name', 'like', "%{$term}%");
                                });
                            }
                        });
                });
            })
            ->latest()
            ->paginate(min($request->integer('per_page', 15), 100));

        return AccountResource::collection($accounts);
    }

    /** Create a team login and its matching team profile in one transaction. */
    public function storeTeam(StoreTeamAccountRequest $request)
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated) {
            $user = User::create(Arr::only($validated, [
                'first_name',
                'last_name',
                'email',
                'phone_number',
                'password',
                'role',
            ]) + ['must_change_password' => true]);

            $teamData = [
                'service_area' => $validated['service_area'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ];

            if ($user->role === UserRole::SosTeam) {
                $user->sosTeam()->create($teamData);
            } else {
                $user->donationTeam()->create($teamData);
            }

            return $user;
        });

        return (new AccountResource(
            $user->load(['sosTeam', 'donationTeam'])
        ))->response()->setStatusCode(201);
    }

    /** Show one user or team account. */
    public function show(User $user): AccountResource
    {
        abort_if($user->role === UserRole::Admin, 404);

        return new AccountResource($user->load(['sosTeam', 'donationTeam']));
    }

    /** Edit account identity fields and, for teams, their service area. */
    public function update(UpdateAccountRequest $request, User $user): AccountResource
    {
        abort_if($user->role === UserRole::Admin, 403, 'Admin accounts cannot be edited here.');

        DB::transaction(function () use ($request, $user) {
            $validated = $request->validated();
            $user->update(Arr::except($validated, ['service_area']));

            if ($request->has('service_area')) {
                $team = $user->role === UserRole::SosTeam
                    ? $user->sosTeam
                    : $user->donationTeam;

                // Normal users have no team profile or service area.
                abort_unless($team, 422, 'Service area applies only to team accounts.');
                $team->update(['service_area' => $validated['service_area']]);
            }
        });

        return new AccountResource(
            $user->refresh()->load(['sosTeam', 'donationTeam'])
        );
    }

    /** Send a direct database notification to a user or team account. */
    public function sendMessage(Request $request, User $user): JsonResponse
    {
        abort_if($user->role === UserRole::Admin, 403, 'Admin accounts cannot be messaged here.');

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:3000'],
        ]);

        $admin = $request->user();
        $user->notify(new DomainNotification([
            'event' => 'admin_message',
            'sender_name' => trim($admin->first_name.' '.$admin->last_name),
            'subject' => $validated['subject'],
            'message' => $validated['message'],
        ]));

        return response()->json(['message' => 'Message sent successfully.'], 201);
    }

    /** Disable login using soft deletion while preserving historical records. */
    public function destroy(User $user)
    {
        abort_if($user->role === UserRole::Admin, 403, 'Admin accounts cannot be deleted here.');

        DB::transaction(function () use ($user) {
            // Revoke existing sessions immediately before disabling the account.
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->noContent();
    }
}
