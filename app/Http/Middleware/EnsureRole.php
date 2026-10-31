<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    /** Usage: ->middleware('role:admin') or 'role:admin,dispatcher' */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        abort_unless($request->user()?->hasRole(...$roles), 403);
        return $next($request);
    }
}
