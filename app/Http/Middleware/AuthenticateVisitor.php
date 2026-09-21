<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        if ($request->user('visitor') === null) {
            return response()->json([
                'error'   => 'unauthenticated',
                'message' => 'Please sign in to continue.',
            ], 401);
        }

        return $next($request);
    }
}
