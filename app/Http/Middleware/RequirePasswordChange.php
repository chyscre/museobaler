<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY: pens an account on the change-password screen until the password
 * it was handed has been replaced.
 *
 * A temporary password is a credential two people know: the Tourism office
 * that generated it and the staff member it was given to. While that is true
 * the audit log cannot attribute anything to anybody, because either of them
 * could have signed in. This middleware makes that window as short as it can
 * be - one sign-in - instead of leaving it open for the life of the account.
 *
 * Signing out is deliberately still allowed. Trapping someone in a screen
 * they cannot leave is how people end up sharing a browser session that is
 * already signed in.
 */
class RequirePasswordChange
{
    /**
     * The only things a not-yet-rotated account may reach.
     *
     * Logout is here for the reason above. The two password routes are the
     * way out. Everything else - the dashboard, the desk, even clocking in -
     * waits until the account belongs to one person.
     */
    private const ALLOWED = [
        'password.edit',
        'password.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->mustChangePassword()) {
            return $next($request);
        }

        if ($request->routeIs(self::ALLOWED)) {
            return $next($request);
        }

        // The attendance scan posts as JSON from the phone, and so do the
        // notification poll and the kiosk tick. Handing any of those an HTML
        // redirect produces a silent, confusing failure rather than a reason.
        if ($request->expectsJson()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Set your own password before using your account.',
            ], 403);
        }

        return redirect()->route('password.edit');
    }
}
