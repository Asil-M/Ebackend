<?php

namespace App\Http\Controllers\Api;

use App\Enums\DonationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DonationRequest;
use App\Http\Resources\DonationResource;
use App\Models\Donation;
use App\Services\DonationMatchingService;
use Illuminate\Http\Request;

class DonationController extends Controller
{
    /** List the community donation/request feed. */
    public function index(Request $request)
    {
        return DonationResource::collection(
            Donation::with(['client.user'])
                ->latest()
                ->paginate(min((int) $request->input('per_page', 15), 100))
        );
    }

    /** Create a donation or request and attempt automatic matching. */
    public function store(DonationRequest $request, DonationMatchingService $matchingService)
    {
        // Donation records require an existing client profile.
        abort_unless($request->user()->client, 403, 'Client profile required.');

        $donation = $request->user()->client->donations()->create(
            $request->validated() + ['status' => DonationStatus::Pending]
        );

        // Look for a compatible pending record after creation.
        $matchingService->autoMatch($donation);

        return (new DonationResource(
            $donation->refresh()->load(['client.user'])
        ))
            ->response()
            ->setStatusCode(201);
    }

    /** Show one donation after confirming ownership. */
    public function show(Request $request, Donation $donation): DonationResource
    {
        $this->ensureOwner($request, $donation);

        return new DonationResource($donation->load(['client.user']));
    }

    /** Update a pending donation owned by the authenticated client. */
    public function update(DonationRequest $request, Donation $donation): DonationResource
    {
        $this->ensureOwner($request, $donation);
        // Matched, accepted, and failed records are historical and cannot be edited.
        abort_unless($donation->status === DonationStatus::Pending, 409);

        $donation->update($request->validated());

        return new DonationResource($donation->load(['client.user']));
    }

    /** Delete a pending donation owned by the authenticated client. */
    public function destroy(Request $request, Donation $donation)
    {
        $this->ensureOwner($request, $donation);
        abort_unless($donation->status === DonationStatus::Pending, 409);

        $donation->delete();

        return response()->noContent();
    }

    /** Stop with HTTP 403 if a user tries to access another client's record. */
    private function ensureOwner(Request $request, Donation $donation): void
    {
        abort_unless(
            $donation->client_id === $request->user()->client?->id,
            403
        );
    }
}
