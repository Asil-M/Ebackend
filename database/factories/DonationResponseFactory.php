<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Donation;
use App\Models\DonationResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

class DonationResponseFactory extends Factory
{
    protected $model = DonationResponse::class;

    public function definition(): array
    {
        return [
            'request_donation_id' => Donation::factory(),
            'responder_client_id' => Client::factory(),
            'additional_note' => fake()->sentence(),
            'location' => fake()->randomElement(['beirut', 'tripoli', 'south', 'baalbek', 'bekaa', 'mount_lebanon', 'nabatieh']),
            'status' => 'pending',
        ];
    }
}
