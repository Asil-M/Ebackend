<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminUser = User::factory()->create([
            'first_name' => 'System',
            'last_name' => 'Admin',
            'email' => 'admin@example.com',
            'phone_number' => '+96170000001',
            'role' => 'admin',
        ]);
        $adminUser->admin()->create();

        $sosUser = User::factory()->create([
            'first_name' => 'SOS',
            'last_name' => 'Team',
            'email' => 'sos@example.com',
            'phone_number' => '+96170000002',
            'role' => 'sos_team',
        ]);
        $sosUser->sosTeam()->create(['service_area' => 'beirut']);

        $donationUser = User::factory()->create([
            'first_name' => 'Donation',
            'last_name' => 'Team',
            'email' => 'donation@example.com',
            'phone_number' => '+96170000003',
            'role' => 'donation_team',
        ]);
        $donationUser->donationTeam()->create(['service_area' => 'beirut']);
    }
}
