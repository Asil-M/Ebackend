<?php

namespace App\Services;

use App\Enums\DonationCategory;
use App\Enums\DonationStatus;
use App\Enums\DonationType;
use App\Enums\MatchStatus;
use App\Models\Donation;
use App\Models\MatchedDonation;
use App\Notifications\DomainNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DonationExpirationService
{
    private const TIMEZONE = 'Asia/Beirut';

    public static function isExpired(Donation $donation): bool
    {
        if (
            $donation->type !== DonationType::Donation
            || ! in_array($donation->category, [DonationCategory::Food, DonationCategory::Medicine], true)
        ) {
            return false;
        }

        $expirationDate = $donation->details['expiration_date'] ?? null;
        if (! is_string($expirationDate) || $expirationDate === '') {
            return false;
        }

        try {
            $expiration = CarbonImmutable::createFromFormat('!Y-m-d', $expirationDate, self::TIMEZONE);
        } catch (\Throwable) {
            return true;
        }

        return $expiration === false
            || $expiration->format('Y-m-d') !== $expirationDate
            || $expiration->lt(CarbonImmutable::today(self::TIMEZONE));
    }

    public function expireDueDonations(): int
    {
        $expiredCount = 0;

        Donation::where('type', DonationType::Donation)
            ->whereIn('category', [
                DonationCategory::Food->value,
                DonationCategory::Medicine->value,
            ])
            ->whereIn('status', [
                DonationStatus::Pending->value,
                DonationStatus::Matched->value,
            ])
            ->chunkById(100, function ($donations) use (&$expiredCount) {
                foreach ($donations as $donation) {
                    if ($this->expire($donation)) {
                        $expiredCount++;
                    }
                }
            });

        return $expiredCount;
    }

    public function expire(Donation $donation): bool
    {
        $requestToRematch = null;
        $expiredDonation = null;

        DB::transaction(function () use ($donation, &$requestToRematch, &$expiredDonation) {
            $match = MatchedDonation::where('offered_donation_id', $donation->id)
                ->where('status', MatchStatus::Matched)
                ->lockForUpdate()
                ->first();
            if ($match) {
                $requestToRematch = Donation::lockForUpdate()->findOrFail(
                    $match->request_donation_id
                );
            }

            $offer = Donation::lockForUpdate()->findOrFail($donation->id);
            if (
                ! in_array($offer->status, [DonationStatus::Pending, DonationStatus::Matched], true)
                || ! self::isExpired($offer)
            ) {
                $requestToRematch = null;
                return;
            }

            if ($match) {
                $match->update([
                    'status' => MatchStatus::Rejected,
                    'rejected_at' => now(),
                ]);
                $requestToRematch->update(['status' => DonationStatus::Pending]);
            }

            $offer->update(['status' => DonationStatus::Expired]);
            $expiredDonation = $offer;
        });

        if (! $expiredDonation) {
            return false;
        }

        $expiredDonation->loadMissing('client.user');
        $expiredDonation->client->user->notify(new DomainNotification([
            'event' => 'donation_expired',
            'donation_id' => $expiredDonation->id,
        ]));

        if ($requestToRematch) {
            $requestToRematch->loadMissing('client.user');
            $requestToRematch->client->user->notify(new DomainNotification([
                'event' => 'donation_match_expired',
                'request_donation_id' => $requestToRematch->id,
            ]));

            try {
                app(DonationMatchingService::class)->autoMatch($requestToRematch->fresh());
            } catch (ValidationException) {
                // Another process changed the request while it was being rematched.
            }
        }

        return true;
    }
}
