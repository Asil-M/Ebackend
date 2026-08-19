<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Donation;
use Illuminate\Database\Eloquent\Factories\Factory;

class DonationFactory extends Factory
{
    protected $model = Donation::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'type' => 'request',
            'category' => 'blood',
            'location' => 'beirut',
            'details' => [
                'blood_type' => 'O+',
                'units' => 1,
            ],
            'status' => 'pending',
        ];
    }
}
