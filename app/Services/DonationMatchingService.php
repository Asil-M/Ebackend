<?php

namespace App\Services;

use App\Enums\DonationStatus;
use App\Enums\DonationType;
use App\Enums\DonationCategory;
use App\Enums\DonationResponseStatus;
use App\Enums\MatchStatus;
use App\Models\Donation;
use App\Models\DonationResponse;
use App\Models\MatchedDonation;
use App\Notifications\DomainNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DonationMatchingService
{
    public function match(
        Donation $requestDonation,
        Donation $offeredDonation,
        ?int $teamId = null,
        ?int $reviewResponseId = null
    ): MatchedDonation {
        app(DonationExpirationService::class)->expire($offeredDonation);

        return DB::transaction(function () use (
            $requestDonation,
            $offeredDonation,
            $teamId,
            $reviewResponseId
        ) {
            $request = Donation::lockForUpdate()->findOrFail($requestDonation->id);
            $offer = Donation::lockForUpdate()->findOrFail($offeredDonation->id);

            if (! $this->areEligible($request, $offer, $reviewResponseId)) {
                throw ValidationException::withMessages([
                    'match' => 'Donations are not eligible to match.',
                ]);
            }

            $pairExists = MatchedDonation::where('request_donation_id', $request->id)
                ->where('offered_donation_id', $offer->id)
                ->exists();

            if ($pairExists) {
                throw ValidationException::withMessages([
                    'match' => 'This pair has already been matched.',
                ]);
            }

            $match = MatchedDonation::create([
                'donation_team_id' => $teamId,
                'request_donation_id' => $request->id,
                'offered_donation_id' => $offer->id,
                'matched_quantity' => $this->matchedQuantity($request, $offer),
                'status' => MatchStatus::Matched,
                'matched_at' => now(),
            ]);

            $request->update(['status' => DonationStatus::Matched]);
            $offer->update(['status' => DonationStatus::Matched]);

            return $match;
        });
    }

    public function autoMatch(Donation $donation): ?MatchedDonation
    {
        // An explicit review reservation must never compete with auto-matching.
        if ($donation->status === DonationStatus::AwaitingReview) {
            return null;
        }

        // Keep the relationship check as defense-in-depth for older records.
        if (
            $donation->type === DonationType::Request
            && $donation->responses()
                ->where('status', DonationResponseStatus::Pending->value)
                ->exists()
        ) {
            return null;
        }

        $oppositeType = $donation->type === DonationType::Request
            ? DonationType::Donation
            : DonationType::Request;

        $candidate = Donation::where('type', $oppositeType)
            ->where('category', $donation->category)
            ->where('location', $donation->location)
            ->where('status', DonationStatus::Pending)
            ->where('client_id', '!=', $donation->client_id)
            ->when(
                $oppositeType === DonationType::Request,
                fn ($query) => $query->whereDoesntHave(
                    'responses',
                    fn ($responseQuery) => $responseQuery->where(
                        'status',
                        DonationResponseStatus::Pending->value
                    )
                )
            )
            ->whereNotExists(function ($query) use ($donation) {
                $query->selectRaw(1)
                    ->from('matched_donations')
                    ->where(function ($pairQuery) use ($donation) {
                        $pairQuery
                            ->whereColumn('request_donation_id', 'donations.id')
                            ->where('offered_donation_id', $donation->id)
                            ->orWhere(function ($reverseQuery) use ($donation) {
                                $reverseQuery
                                    ->where('request_donation_id', $donation->id)
                                    ->whereColumn('offered_donation_id', 'donations.id');
                            });
                    });
            })
            ->get()
            ->first(fn (Donation $candidate) => ! DonationExpirationService::isExpired(
                $donation->type === DonationType::Donation ? $donation : $candidate
            ));

        if (! $candidate) {
            return null;
        }

        return $donation->type === DonationType::Request
            ? $this->match($donation, $candidate)
            : $this->match($candidate, $donation);
    }

    public function accept(
        MatchedDonation $matchedDonation,
        int $teamId,
        ?int $excludedResponseId = null
    ): MatchedDonation {
        $matchedDonation->loadMissing('offeredDonation');
        app(DonationExpirationService::class)->expire($matchedDonation->offeredDonation);

        $remainingDonation = null;
        $closedResponses = collect();

        $match = DB::transaction(function () use (
            $matchedDonation,
            $teamId,
            $excludedResponseId,
            &$remainingDonation,
            &$closedResponses
        ) {
            $lockedMatch = MatchedDonation::lockForUpdate()->findOrFail($matchedDonation->id);

            if ($lockedMatch->status !== MatchStatus::Matched) {
                throw ValidationException::withMessages([
                    'match' => 'Only a newly matched pair can be accepted.',
                ]);
            }

            $request = Donation::lockForUpdate()->findOrFail(
                $lockedMatch->request_donation_id
            );
            $offer = Donation::lockForUpdate()->findOrFail(
                $lockedMatch->offered_donation_id
            );

            $matchedQuantity = $lockedMatch->matched_quantity === null
                ? $this->matchedQuantity($request, $offer)
                : (float) $lockedMatch->matched_quantity;
            $requestRemaining = $this->applyMatchedQuantity($request, $matchedQuantity);
            $offerRemaining = $this->applyMatchedQuantity($offer, $matchedQuantity);

            $lockedMatch->update([
                'donation_team_id' => $teamId,
                'matched_quantity' => $matchedQuantity,
                'status' => MatchStatus::Accepted,
                'accepted_at' => now(),
            ]);

            $request->update([
                'details' => $requestRemaining['details'],
                'status' => $requestRemaining['has_remaining']
                    ? ($request->responses()
                        ->where('status', DonationResponseStatus::Pending)
                        ->when(
                            $excludedResponseId !== null,
                            fn ($query) => $query->where('id', '!=', $excludedResponseId)
                        )
                        ->exists()
                            ? DonationStatus::AwaitingReview
                            : DonationStatus::Pending)
                    : DonationStatus::Accepted,
            ]);
            $offer->update([
                'details' => $offerRemaining['details'],
                'status' => $offerRemaining['has_remaining']
                    ? DonationStatus::Pending
                    : DonationStatus::Accepted,
            ]);

            $remainingDonation = $requestRemaining['has_remaining']
                ? $request
                : ($offerRemaining['has_remaining'] ? $offer : null);

            if (! $requestRemaining['has_remaining']) {
                $closedResponses = DonationResponse::with('responder.user')
                    ->where('request_donation_id', $request->id)
                    ->where('status', DonationResponseStatus::Pending)
                    ->when(
                        $excludedResponseId !== null,
                        fn ($query) => $query->where('id', '!=', $excludedResponseId)
                    )
                    ->lockForUpdate()
                    ->get();

                foreach ($closedResponses as $response) {
                    $response->update([
                        'status' => DonationResponseStatus::Rejected,
                        'rejected_at' => now(),
                    ]);
                }
            }

            return $lockedMatch;
        });

        foreach ($closedResponses as $response) {
            $response->responder->user->notify(new DomainNotification([
                'event' => 'help_offer_closed',
                'donation_response_id' => $response->id,
                'request_donation_id' => $response->request_donation_id,
            ]));
        }

        if ($remainingDonation) {
            $this->autoMatch($remainingDonation->fresh());
        }

        return $match;
    }

    private function areEligible(
        Donation $request,
        Donation $offer,
        ?int $reviewResponseId = null
    ): bool
    {
        $hasUnapprovedHelpResponse = $reviewResponseId === null
            && $request->responses()
                ->where('status', DonationResponseStatus::Pending->value)
                ->exists();

        return $request->id !== $offer->id
            && $request->type === DonationType::Request
            && $offer->type === DonationType::Donation
            && $request->client_id !== $offer->client_id
            && $request->status === DonationStatus::Pending
            && $offer->status === DonationStatus::Pending
            && $request->category === $offer->category
            && ! $hasUnapprovedHelpResponse;
    }

    private function matchedQuantity(Donation $request, Donation $offer): float
    {
        return min($this->quantityOf($request), $this->quantityOf($offer));
    }

    private function quantityOf(Donation $donation): float
    {
        $key = $this->quantityKey($donation->category);
        if ($key === null) {
            return 1;
        }

        $quantity = $donation->details[$key] ?? null;
        if (! is_numeric($quantity) || (float) $quantity <= 0) {
            throw ValidationException::withMessages([
                "details.$key" => 'Quantity must be a positive number.',
            ]);
        }

        return (float) $quantity;
    }

    private function quantityKey(DonationCategory $category): ?string
    {
        return match ($category) {
            DonationCategory::Blood => 'units',
            DonationCategory::Money => 'amount',
            DonationCategory::Clothes,
            DonationCategory::Food,
            DonationCategory::Medicine => 'quantity',
            DonationCategory::Other => null,
        };
    }

    private function applyMatchedQuantity(Donation $donation, float $matchedQuantity): array
    {
        $key = $this->quantityKey($donation->category);
        if ($key === null) {
            return ['details' => $donation->details, 'has_remaining' => false];
        }

        $remaining = max(0, $this->quantityOf($donation) - $matchedQuantity);
        if ($donation->category === DonationCategory::Money) {
            $remaining = round($remaining, 2);
        }
        $details = $donation->details;
        $details[$key] = $donation->category === DonationCategory::Money
            ? number_format($remaining, 2, '.', '')
            : (int) $remaining;

        return ['details' => $details, 'has_remaining' => $remaining > 0];
    }
}
