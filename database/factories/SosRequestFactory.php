<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\SosRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class SosRequestFactory extends Factory
{
    protected $model = SosRequest::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'type' => 'ambulance',
            'status' => 'pending',
            'location_name' => 'Beirut',
            'description' => 'Emergency',
            'latitude' => 33.8938,
            'longitude' => 35.5018,
        ];
    }
}
