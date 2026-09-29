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
 * no-store is the default, not the rule: a route that declares its own
 * policy with cache.headers (the exhibit list, with an ETag so an unchanged
 * answer is a 304) keeps it. Declared, rather than sniffed from the
 * response, because Symfony's own default when nothing was set reads
 * exactly like a deliberate no-cache.
 */
class ApiResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        // Both of these, and neither is redundant. The first clears the header
        // from Symfony's own bag; the second clears the one PHP adds for
        // itself from expose_php, which never appears in that bag at all. The
        // API was answering every request with "X-Powered-By: PHP/8.3.30"
        // while the panel - which has called header_remove() since it was
        // written - answered with none, and the difference was exactly this
        // line. Turning expose_php off on the server is the belt to this
        // braces, but the code should not depend on someone remembering to.
        $response->headers->remove('X-Powered-By');
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }

        if (!$this->routeSetsItsOwnCachePolicy($request)) {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }

    private function routeSetsItsOwnCachePolicy(Request $request): bool
    {
        foreach ($request->route()?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'cache.headers')) {
                return true;
            }
        }

        return false;
    }
}
