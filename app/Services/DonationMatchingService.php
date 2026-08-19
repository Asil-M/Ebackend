<?php

namespace App\Services;

use App\Enums\DonationStatus;
use App\Enums\DonationType;
use App\Enums\DonationCategory;
use App\Enums\MatchStatus;
use App\Models\Donation;
use App\Models\MatchedDonation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DonationMatchingService
{
    public function match(
        Donation $requestDonation,
        Donation $offeredDonation,
        ?int $teamId = null
    ): MatchedDonation {
        return DB::transaction(function () use ($requestDonation, $offeredDonation, $teamId) {
            $request = Donation::lockForUpdate()->findOrFail($requestDonation->id);
            $offer = Donation::lockForUpdate()->findOrFail($offeredDonation->id);

            if (! $this->areEligible($request, $offer)) {
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
        $oppositeType = $donation->type === DonationType::Request
            ? DonationType::Donation
            : DonationType::Request;

        $candidate = Donation::where('type', $oppositeType)
            ->where('category', $donation->category)
            ->where('location', $donation->location)
            ->where('status', DonationStatus::Pending)
            ->where('client_id', '!=', $donation->client_id)
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
            ->first();

        if (! $candidate) {
            return null;
        }

        return $donation->type === DonationType::Request
            ? $this->match($donation, $candidate)
            : $this->match($candidate, $donation);
    }

    public function accept(
        MatchedDonation $matchedDonation,
        int $teamId
    ): MatchedDonation {
        $remainingDonation = null;

        $match = DB::transaction(function () use ($matchedDonation, $teamId, &$remainingDonation) {
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
                    ? DonationStatus::Pending
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

            return $lockedMatch;
        });

        if ($remainingDonation) {
            $this->autoMatch($remainingDonation->fresh());
        }

        return $match;
    }

    private function areEligible(Donation $request, Donation $offer): bool
    {
        return $request->id !== $offer->id
            && $request->type === DonationType::Request
            && $offer->type === DonationType::Donation
            && $request->client_id !== $offer->client_id
            && $request->status === DonationStatus::Pending
            && $offer->status === DonationStatus::Pending
            && $request->category === $offer->category;
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
