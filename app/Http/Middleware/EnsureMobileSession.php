<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureMobileSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->status !== 'active' || ! $user->hasRole('resident', 'driver/responder', 'admin', 'dispatcher')) {
            $token = $user?->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }
            abort(401, 'This mobile session is no longer available. Please sign in again.');
        }

        if ($user->hasRole('driver/responder')) {
            $user->newQuery()->whereKey($user->getKey())
                ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subSeconds(30)))
                ->update(['last_seen_at' => now()]);
        }

        return $next($request);
    }
}
