<?php

namespace Database\Factories;

use App\Models\DonationTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DonationTeamFactory extends Factory
{
    protected $model = DonationTeam::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => 'donation_team']),
            'service_area' => 'beirut',
            'is_active' => true,
        ];
    }
}
