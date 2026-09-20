<?php
/**
 * SECURITY: input sanitising for the visitor API.
 *
 * The same rule as App\Http\Middleware\SanitizeInput on the Laravel side,
 * kept in step by hand because this code runs outside Laravel: anything
 * shaped like an HTML tag is removed from every string in the request, and
 * so are control characters. Passwords are left untouched - they are never
 * displayed, and altering one silently would lock the visitor out of the
 * account they just made.
 *
 * Output escaping is still the real XSS defence (the admin panel escapes in
 * Blade, the app escapes in JS). This is the input-side layer for the
 * places that reach: CSV exports opened in Excel, and the one or two spots
 * where the app builds HTML from an exhibit name.
 */

const API_SANITIZE_SKIP = ['password', 'password_confirmation', 'current_password', 'new_password', 'token'];

function apiSanitizeString(string $value): string
{
    $value = preg_replace('/<!--.*?-->/s', '', $value);
    $value = preg_replace('/<\/?[a-zA-Z][^>]*>/', '', $value);

    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
}

/** Clean every string in a decoded request body, recursively. */
function apiSanitize(mixed $data): mixed
{
    if (is_string($data)) {
        return apiSanitizeString($data);
    }

    if (!is_array($data)) {
        return $data;
    }

    foreach ($data as $key => $value) {
        if (is_string($key) && in_array($key, API_SANITIZE_SKIP, true)) {
            continue;
        }
        $data[$key] = apiSanitize($value);
    }

    return $data;
}
