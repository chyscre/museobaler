<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The hand-off between Google's redirect and the visitor app.
 *
 * Google sends the browser back to a web route (GoogleAuthController), but
 * the app signs in with a bearer token over the API. The callback therefore
 * leaves a one-time code in the URL fragment - never the session token
 * itself, which would sit in the browser history - and the app trades the
 * code for its answer at POST /api/v1/visitors/google. The code lives two
 * minutes and works once.
 *
 * Someone Google vouched for who has no museum account yet gets a second,
 * longer-lived value: a sign-up token that stands in for the password on
 * the details form, so the address they register with is the one Google
 * checked and not whatever the form says.
 */
class GoogleSignIn
{
    private const HANDOFF_SECONDS = 120;
    private const SIGNUP_SECONDS  = 1800;

    /** True when the museum has set up a Google OAuth client. */
    public static function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    /**
     * Park an answer for the app and return the code that claims it.
     *
     * @param  array{kind: string}&array<string, mixed>  $answer
     */
    public static function handOff(array $answer): string
    {
        $code = bin2hex(random_bytes(32));
        Cache::put(self::key('handoff', $code), $answer, self::HANDOFF_SECONDS);

        return $code;
    }

    /** @return array<string, mixed>|null */
    public static function claim(string $code): ?array
    {
        return self::pull('handoff', $code);
    }

    /** A token for the details form, standing for this Google identity. */
    public static function startSignup(string $email, string $googleId): string
    {
        $token = bin2hex(random_bytes(32));
        Cache::put(self::key('signup', $token), ['email' => $email, 'google_id' => $googleId], self::SIGNUP_SECONDS);

        return $token;
    }

    /** @return array{email: string, google_id: string}|null */
    public static function pullSignup(string $token): ?array
    {
        return self::pull('signup', $token);
    }

    private static function pull(string $kind, string $value): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $value)) {
            return null;
        }

        $answer = Cache::pull(self::key($kind, $value));

        return is_array($answer) ? $answer : null;
    }

    /** Stored under a hash, so a copy of the cache holds nothing that works. */
    private static function key(string $kind, string $value): string
    {
        return "google-$kind:" . hash('sha256', $value);
    }
}
