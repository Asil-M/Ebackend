<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;

class EnsureTeamIsActive
{
    public function handle(Request $request, Closure $next, string $requiredRole)
    {
        $user = $request->user();

        abort_unless(
            $user?->role->value === $requiredRole,
            403,
            'Incorrect team role.'
        );

        $team = match ($user->role) {
            UserRole::SosTeam => $user->sosTeam,
            UserRole::DonationTeam => $user->donationTeam,
            default => null,
        };

        abort_unless($team?->is_active, 403, 'Team is inactive.');

        return $next($request);
    }
}
