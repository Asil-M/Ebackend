<?php

namespace App\Http\Controllers\Api;

use App\Enums\SosStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\SosRequestRequest;
use App\Http\Resources\SosRequestResource;
use App\Models\SosRequest;
use App\Models\User;
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

        $data = $request->validated();
        $relayMessageId = $data['relay_message_id'] ?? null;
        unset($data['relay_message_id']);
        $values = $data + [
            'client_id' => $request->user()->client->id,
            'status' => SosStatus::Pending,
        ];
        $sosRequest = $relayMessageId
            ? SosRequest::firstOrCreate(['relay_message_id' => $relayMessageId], $values)
            : SosRequest::create($values);

        return (new SosRequestResource($sosRequest->load(['client.user'])))
            ->response()
            ->setStatusCode($sosRequest->wasRecentlyCreated ? 201 : 200);
    }

    /** Store an SOS received by an authenticated nearby relay phone. */
    public function relayStore(Request $request)
    {
        $data = $request->validate([
            'relay_message_id' => ['required', 'string', 'max:100'],
            'sender_email' => ['required', 'email'],
            'type' => ['required', 'in:ambulance,fire,police'],
            'location_name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $sender = User::where('email', $data['sender_email'])->first();
        abort_unless($sender?->client, 422, 'The SOS sender has no client profile.');

        $sosRequest = SosRequest::firstOrCreate(
            ['relay_message_id' => $data['relay_message_id']],
            [
                'client_id' => $sender->client->id,
                'type' => $data['type'],
                'location_name' => $data['location_name'],
                'description' => $data['description'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'status' => SosStatus::Pending,
            ]
        );

        return (new SosRequestResource($sosRequest->load(['client.user'])))
            ->response()
            ->setStatusCode($sosRequest->wasRecentlyCreated ? 201 : 200);
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

    /** Reject an invalid pending request for every SOS team. */
    public function reject(Request $request, SosRequest $sosRequest): SosRequestResource
    {
        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:255',
                'in:Duplicate request,Client cancelled,False request confirmed,Invalid location,Invalid request',
            ],
        ]);

        DB::transaction(function () use ($request, $sosRequest, $validated) {
            $lockedRequest = SosRequest::lockForUpdate()->findOrFail($sosRequest->id);

            abort_unless(
                $lockedRequest->status === SosStatus::Pending,
                409,
                'Request is no longer pending.'
            );

            $lockedRequest->update([
                'status' => SosStatus::Rejected,
                'rejected_by_sos_team_id' => $request->user()->sosTeam->id,
                'rejection_reason' => $validated['reason'],
                'rejected_at' => now(),
            ]);

            return $lockedRequest;
        });

        $sosRequest->refresh()->loadMissing('client.user');
        $sosRequest->client->user->notify(new DomainNotification([
            'event' => 'sos_request_rejected',
            'sos_request_id' => $sosRequest->id,
            'reason' => $validated['reason'],
        ]));

        return new SosRequestResource($sosRequest);
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
