<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\DonationTeam;
use App\Models\SosTeam;
use Illuminate\Http\Request;

class AdminTeamController extends Controller
{
    /** Activate or deactivate an SOS team without deleting its history. */
    public function updateSosTeam(Request $request, SosTeam $team): SosTeam
    {
        $this->ensureAdmin($request);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $team->update($validated);

        return $team;
    }

    /** Activate or deactivate a donation team without deleting its history. */
    public function updateDonationTeam(Request $request, DonationTeam $team): DonationTeam
    {
        $this->ensureAdmin($request);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $team->update($validated);

        return $team;
    }

    /** Stop with HTTP 403 unless the authenticated user is an administrator. */
    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
    }
}
