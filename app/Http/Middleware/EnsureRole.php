<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY: EnsureRole Middleware
 *
 * Restricts a route to specific staff roles (TourismHead, Administrator).
 * Without this, any authenticated staff account — regardless of role — could
 * reach every route behind ->middleware('auth'), including staff account
 * management and museum settings. This closes that privilege-escalation gap:
 * an Administrator account could otherwise reach TourismHead-only screens.
 *
 * Usage: ->middleware('role:TourismHead') or ->middleware('role:TourismHead,Administrator')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, $roles, true)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
