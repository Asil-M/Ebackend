<?php

namespace App\Http\Controllers\Api;

use App\Enums\SosStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\SosRequestRequest;
use App\Http\Resources\SosRequestResource;
use App\Models\SosRequest;
use App\Notifications\DomainNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SosRequestController extends Controller
{
    /** List SOS requests visible to the authenticated user. */
    public function index(Request $request)
    {
        $query = SosRequest::with(['client.user'])->latest();

        // Normal users see only their own requests; teams can see the full list.
        if ($request->user()->role === UserRole::User) {
            $query->where('client_id', $request->user()->client?->id);
        }

        return SosRequestResource::collection($query->paginate());
    }

    /** Create an SOS request for the authenticated client. */
    public function store(SosRequestRequest $request)
    {
        // An SOS request must belong to a client profile.
        abort_unless($request->user()->client, 403, 'Client profile required.');

        $sosRequest = $request->user()->client->sosRequests()->create(
            $request->validated() + ['status' => SosStatus::Pending]
        );

        return (new SosRequestResource($sosRequest->load(['client.user'])))
            ->response()
            ->setStatusCode(201);
    }

    /** Show one SOS request if the user is allowed to see it. */
    public function show(Request $request, SosRequest $sosRequest): SosRequestResource
    {
        // A normal user cannot view another client's request.
        abort_unless(
            $request->user()->role !== UserRole::User
                || $sosRequest->client_id === $request->user()->client?->id,
            403
        );

        return new SosRequestResource($sosRequest->load(['client.user']));
    }

    /** List pending requests for active SOS teams. */
    public function pending(Request $request)
    {
        return SosRequestResource::collection(
            SosRequest::with(['client.user'])
                ->where('status', SosStatus::Pending)
                ->latest()
                ->paginate(min((int) $request->input('per_page', 15), 100))
        );
    }

    /** Accept a pending SOS request and attach the selected facility. */
    public function accept(Request $request, SosRequest $sosRequest): SosRequestResource
    {
        $validated = $request->validate([
            'service_name' => ['required', 'string'],
            'service_latitude' => ['required', 'numeric', 'between:-90,90'],
            'service_longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        // Locking prevents two SOS teams from accepting the same request concurrently.
        $acceptedRequest = DB::transaction(function () use ($request, $sosRequest, $validated) {
            $lockedRequest = SosRequest::lockForUpdate()->findOrFail($sosRequest->id);

            // HTTP 409 means another action already changed this request's state.
            abort_unless(
                $lockedRequest->status === SosStatus::Pending,
                409,
                'Request is no longer pending.'
            );

            $lockedRequest->update($validated + [
                'accepted_by_sos_team_id' => $request->user()->sosTeam->id,
                'status' => SosStatus::Accepted,
                'accepted_at' => now(),
            ]);

            return $lockedRequest;
        });

        // Notify the client only after the database transaction succeeds.
        $acceptedRequest->loadMissing('client.user');
        $acceptedRequest->client->user->notify(new DomainNotification([
            'event' => 'sos_request_accepted',
            'sos_request_id' => $acceptedRequest->id,
        ]));

        return new SosRequestResource(
            $acceptedRequest->load(['client.user'])
        );
    }

    /** Mark a non-accepted SOS request as failed. */
    public function fail(Request $request, SosRequest $sosRequest): SosRequestResource
    {
        // Use a transaction and row lock so status changes cannot race each other.
        $failedRequest = DB::transaction(function () use ($sosRequest) {
            $lockedRequest = SosRequest::lockForUpdate()->findOrFail($sosRequest->id);

            // An accepted request cannot later be changed to failed.
            abort_if($lockedRequest->status === SosStatus::Accepted, 409);

            $lockedRequest->update([
                'status' => SosStatus::Failed,
                'failed_at' => now(),
            ]);

            return $lockedRequest;
        });

        return new SosRequestResource(
            $failedRequest->load(['client.user'])
        );
    }
}
