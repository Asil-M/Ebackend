<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next)
    {
        // Only a user with the admin role and an admin profile may continue.
        abort_unless(
            $request->user()?->role === UserRole::Admin
                && $request->user()->admin !== null,
            403,
            'Administrator access is required.'
        );

        return $next($request);
    }
}
