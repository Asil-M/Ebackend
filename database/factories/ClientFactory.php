<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date_of_birth' => '1995-01-01',
            'blood_type' => 'O+',
            'emergency_contact_number' => '+96170000000',
            'emergency_contact_relation' => 'parent',
            'allergies' => null,
            'medical_conditions' => null,
        ];
    }
}
