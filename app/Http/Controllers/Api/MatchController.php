<?php

namespace App\Http\Controllers\Api;

use App\Enums\DonationStatus;
use App\Enums\MatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MatchedDonationResource;
use App\Models\Donation;
use App\Models\MatchedDonation;
use App\Notifications\DomainNotification;
use App\Services\DonationMatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MatchController extends Controller
{
    /** List donation matches for an active donation team. */
    public function index(Request $request)
    {
        $matches = MatchedDonation::with([
            'requestDonation.client.user',
            'offeredDonation.client.user',
        ])
            ->when(
                $request->input('status') === MatchStatus::Matched->value,
                fn ($query) => $query->where('status', MatchStatus::Matched->value)
            )
            ->latest()
            ->paginate(min((int) $request->input('per_page', 15), 100));

        return MatchedDonationResource::collection($matches);
    }

    /** Manually match one request with one offered donation. */
    public function store(Request $request, DonationMatchingService $matchingService)
    {
        $validated = $request->validate([
            'request_donation_id' => ['required', 'integer', 'exists:donations,id'],
            'offered_donation_id' => [
                'required',
                'integer',
                'different:request_donation_id',
                'exists:donations,id',
            ],
        ]);

        // The service applies type, ownership, state, category, and pair-history rules.
        $match = $matchingService->match(
            Donation::findOrFail($validated['request_donation_id']),
            Donation::findOrFail($validated['offered_donation_id']),
            $request->user()->donationTeam->id
        );

        return (new MatchedDonationResource(
            $match->load([
                'requestDonation.client.user',
                'offeredDonation.client.user',
            ])
        ))->response()->setStatusCode(201);
    }

    /** Accept a pending match and mark both donations as accepted. */
    public function accept(
        Request $request,
        MatchedDonation $matchedDonation,
        DonationMatchingService $matchingService
    ): MatchedDonationResource
    {
        $match = $matchingService->accept(
            $matchedDonation,
            $request->user()->donationTeam->id
        );

        // Notify both clients after the transaction commits successfully.
        foreach ([$match->requestDonation, $match->offeredDonation] as $donation) {
            $donation->client->user->notify(new DomainNotification([
                'event' => 'donation_match_accepted',
                'matched_donation_id' => $match->id,
            ]));
        }

        return new MatchedDonationResource(
            $match->load([
                'requestDonation.client.user',
                'offeredDonation.client.user',
            ])
        );
    }

    /** Reject a match while preserving its pair history. */
    public function reject(Request $request, MatchedDonation $matchedDonation): MatchedDonationResource
    {
        // Rejection and donation status restoration must happen atomically.
        $match = DB::transaction(function () use ($request, $matchedDonation) {
            $lockedMatch = MatchedDonation::lockForUpdate()->findOrFail($matchedDonation->id);

            // A previously accepted or rejected match cannot be rejected again.
            abort_unless($lockedMatch->status === MatchStatus::Matched, 409);

            $lockedMatch->update([
                'donation_team_id' => $request->user()->donationTeam->id,
                'status' => MatchStatus::Rejected,
                'rejected_at' => now(),
            ]);

            // The pair record remains rejected, while both donations become available again.
            $requestDonation = Donation::findOrFail(
                $lockedMatch->request_donation_id
            );
            $requestDonation->update([
                'status' => $requestDonation->responses()
                    ->where('status', 'pending')
                    ->exists()
                        ? DonationStatus::AwaitingReview
                        : DonationStatus::Pending,
            ]);
            Donation::whereKey($lockedMatch->offered_donation_id)
                ->update(['status' => DonationStatus::Pending]);

            return $lockedMatch;
        });

        return new MatchedDonationResource(
            $match->load([
                'requestDonation.client.user',
                'offeredDonation.client.user',
            ])
        );
    }
}
