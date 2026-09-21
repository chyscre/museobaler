<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY: identity for the visitor API.
 *
 * The app sends the session token it was handed at sign-in as a bearer
 * token, and the `visitor` guard resolves the account from it (see
 * AppServiceProvider). No token, an unknown one or an expired one is a
 * 401 with the shape the app has always been given, rather than Laravel's
 * default, so the waiting screen and the sign-in flow keep working.
 */
class AuthenticateVisitor
{
    public function handle(Request $request, Closure $next): Response
    {
        // The guard caches whoever it resolved for as long as the guard
        // object lives, which is one request under PHP-FPM and many under
        // a test run or Octane. Identity is decided per request, so start
        // clean: one query, the same cost the raw API paid.
        Auth::guard('visitor')->forgetUser();

        if ($request->user('visitor') === null) {
            return response()->json([
                'error'   => 'unauthenticated',
                'message' => 'Please sign in to continue.',
            ], 401);
        }

        return $next($request);
    }
}
