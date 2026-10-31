<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || $user->status !== 'active' || !$user->hasRole(...$roles)) {
            Log::warning('RoleMiddleware: Access denied.', [
                'user_id' => $user?->id,
                'role' => $user?->role,
                'required_roles' => $roles,
            ]);

            abort(403);
        }

        return $next($request);
    }
}
