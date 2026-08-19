<?php

namespace Database\Factories;

use App\Models\SosTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SosTeamFactory extends Factory
{
    protected $model = SosTeam::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => 'sos_team']),
            'service_area' => 'beirut',
            'is_active' => true,
        ];
    }
}
