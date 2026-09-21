<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY: the headers every visitor API answer carries.
 *
 * nosniff stops a browser treating JSON as script; no-store keeps a
 * visitor's profile out of shared caches and the back button on a borrowed
 * phone; CORP refuses to be embedded by another site even where CORS would
 * not apply; and the API has no reason to advertise the PHP version.
 *
 * no-store is the default, not the rule: a route that sets its own
 * Cache-Control (the exhibit list, with an ETag so an unchanged answer is a
 * 304) keeps it.
 */
class ApiResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->remove('X-Powered-By');

        if (!$response->headers->has('Cache-Control') || $response->headers->get('Cache-Control') === 'no-cache, private') {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}
