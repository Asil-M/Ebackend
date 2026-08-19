<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureInitialPasswordChanged
{
    public function handle(Request $request, Closure $next)
    {
        abort_if(
            $request->user()?->must_change_password,
            403,
            'You must change your temporary password before continuing.'
        );

        return $next($request);
    }
}
