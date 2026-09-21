<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing for the visitor API
|--------------------------------------------------------------------------
|
| The visitor app is served from the same host as /api, so an ordinary
| install needs no CORS at all: a same-origin request is never subject to
| it. Everything here exists for the exceptional front end on another
| origin, such as a native wrapper. Anything not listed gets no CORS
| headers and the browser discards the response - the list fails closed.
|
| The old raw-PHP API kept this logic in public/api/_cors.php; the same
| API_ALLOWED_ORIGINS setting feeds it now.
|
*/

$allowed = array_values(array_filter(array_map('trim', explode(',', (string) env('API_ALLOWED_ORIGINS', '')))));

// Where a developer runs a wrapper against a local server. Never in production.
if (env('APP_ENV', 'production') !== 'production') {
    $allowed = array_merge($allowed, ['http://localhost', 'http://127.0.0.1', 'capacitor://localhost', 'ionic://localhost']);
}

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $allowed,

    'allowed_origins_patterns' => [],

    // Authorization carries the visitor's bearer token; a cross-origin caller
    // cannot send it unless it is named here.
    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'ngrok-skip-browser-warning'],

    'exposed_headers' => [],

    'max_age' => 600,

    // Bearer tokens, not cookies: nothing here needs credentials mode.
    'supports_credentials' => false,
];
