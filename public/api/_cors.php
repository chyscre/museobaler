<?php
/**
 * SECURITY: CORS allow-list for the visitor API.
 *
 * Every endpoint here used to answer `Access-Control-Allow-Origin: *`, either
 * outright or as the fallback when an origin was not recognised. That let any
 * website on the internet call this API from a visitor's browser and read the
 * reply — profiles, exhibit data, feedback submission, the lot.
 *
 * The allow-list now fails closed: an origin that is not recognised gets no
 * CORS headers at all, and the browser refuses the response.
 *
 * Nothing in normal use needs an entry. The PWA is served from the same host
 * as this API in every deployment — localhost, the LAN IP, and the Cloudflare
 * tunnel alike — and same-origin requests are not subject to CORS. The list
 * exists for a genuinely separate front end, such as a native app wrapper:
 * set API_ALLOWED_ORIGINS in .env to a comma-separated list of exact origins.
 */

require_once __DIR__ . '/_env.php';

/** Origins allowed while developing, when APP_ENV is not production. */
const API_DEV_ORIGINS = [
    'http://localhost',
    'http://127.0.0.1',
    'capacitor://localhost',
    'ionic://localhost',
];

function apiOriginAllowed(string $origin): bool
{
    if ($origin === '') return false;

    // The scheme a proxy terminated TLS for, so the tunnel's https origin
    // still matches the http request Apache actually received behind it.
    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $https     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwarded === 'https';
    $host      = $_SERVER['HTTP_HOST'] ?? '';

    if ($host !== '' && $origin === ($https ? 'https' : 'http') . "://$host") {
        return true;
    }

    foreach (explode(',', (string) apiEnv('API_ALLOWED_ORIGINS', '')) as $allowed) {
        $allowed = trim($allowed);
        if ($allowed !== '' && hash_equals($allowed, $origin)) return true;
    }

    if (apiEnv('APP_ENV', 'production') !== 'production'
        && in_array($origin, API_DEV_ORIGINS, true)) {
        return true;
    }

    return false;
}

/**
 * Send the CORS headers for this endpoint, then answer a preflight.
 *
 * Call this first in every endpoint, before any other output. Preflight
 * requests are terminated here so each endpoint does not repeat the check.
 */
function apiCors(string $methods = 'GET, POST, OPTIONS'): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (apiOriginAllowed($origin)) {
        header("Access-Control-Allow-Origin: $origin");
        // Without this a shared cache could hand one origin's allowed
        // response to a request from another.
        header('Vary: Origin');
        header("Access-Control-Allow-Methods: $methods");
        // Authorization carries the visitor's bearer token; a cross-origin
        // caller cannot send it unless it is named here.
        header('Access-Control-Allow-Headers: Content-Type, Authorization, ngrok-skip-browser-warning');
        header('Access-Control-Max-Age: 600');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
