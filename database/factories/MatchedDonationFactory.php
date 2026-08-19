<?php

namespace Database\Factories;

use App\Models\Donation;
use App\Models\MatchedDonation;
use Illuminate\Database\Eloquent\Factories\Factory;

class MatchedDonationFactory extends Factory
{
    protected $model = MatchedDonation::class;

    public function definition(): array
    {
        return [
            'request_donation_id' => Donation::factory(),
            'offered_donation_id' => Donation::factory()->state([
                'type' => 'donation',
            ]),
            'matched_quantity' => 1,
            'status' => 'matched',
            'matched_at' => now(),
        ];
    }
}
