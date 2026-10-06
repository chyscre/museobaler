<?php

namespace App\Http\Controllers;

use App\Models\Visitor;
use App\Support\GoogleSignIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * "Continue with Google" for visitors, through Laravel Socialite.
 *
 *   GET  /auth/google            off to Google's consent screen
 *   GET  /auth/google/callback   back from it; leaves a one-time code in the
 *                                app's URL fragment (see GoogleSignIn)
 *   POST /api/v1/visitors/google the app trades that code for a session,
 *                                or - for someone new - a sign-up token
 *
 * Google has already checked the address, so a visitor who comes this way
 * is marked verified and never sees the 6-digit code screen. Staff do not
 * sign in with Google; the admin panel has its own login.
 */
class GoogleAuthController extends Controller
{
    /**
     * Where the round trip's state waits for Google to send the visitor back.
     *
     * Not the session. The session cookie is SameSite=Strict, and the return
     * from accounts.google.com is a cross-site navigation, so the browser
     * leaves it behind: Socialite found no state in a brand-new session and
     * refused every sign-in as "Google sign-in did not work". This cookie is
     * Lax - sent on that top-level GET and on nothing cross-site besides -
     * scoped to /auth/google, and gone after ten minutes or one use, so the
     * callback is still bound to the browser that started it.
     */
    private const STATE_COOKIE = 'google_oauth_state';

    public function redirect(): RedirectResponse
    {
        if (!GoogleSignIn::configured()) {
            return $this->toApp(['google_error' => 'unavailable']);
        }

        $state = Str::random(40);

        return Socialite::driver('google')
            ->stateless()
            ->redirectUrl($this->callbackUrl())
            ->scopes(['openid', 'email', 'profile'])
            ->with(['state' => $state])
            ->redirect()
            ->withCookie(cookie(
                self::STATE_COOKIE, $state, 10, '/auth/google', null,
                config('session.secure'), true, false, 'lax'
            ));
    }

    public function callback(Request $request): RedirectResponse
    {
        if (!GoogleSignIn::configured()) {
            return $this->toApp(['google_error' => 'unavailable']);
        }

        // "Cancel" on Google's screen comes back as ?error=access_denied.
        if ($request->filled('error')) {
            return $this->toApp(['google_error' => 'cancelled']);
        }

        // SECURITY: the callback must come from the trip this browser began.
        // A stale or replayed one - the back button, a reload - has no
        // cookie left to match.
        $expected = $request->cookie(self::STATE_COOKIE);
        $state    = $request->query('state');
        if (!is_string($expected) || !is_string($state) || !hash_equals($expected, $state)) {
            return $this->toApp(['google_error' => 'failed']);
        }

        try {
            $google = Socialite::driver('google')->stateless()->redirectUrl($this->callbackUrl())->user();
        } catch (Throwable $e) {
            report($e);
            return $this->toApp(['google_error' => 'failed']);
        }

        $raw      = $google->getRaw();
        $email    = mb_strtolower(trim((string) $google->getEmail()));
        $googleId = (string) $google->getId();

        // Google accounts can carry an address Google itself has not
        // confirmed. Only a confirmed one is proof of the inbox.
        if ($email === '' || $googleId === '' || !filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return $this->toApp(['google_error' => 'email_unverified']);
        }

        $visitor = Visitor::where('google_id', $googleId)->first()
            ?? Visitor::where('email', $email)->first();

        if ($visitor === null) {
            return $this->toApp(['google' => GoogleSignIn::handOff([
                'kind'          => 'signup',
                'google_signup' => GoogleSignIn::startSignup($email, $googleId),
                'email'         => $email,
                'first_name'    => (string) ($raw['given_name'] ?? ''),
                'last_name'     => (string) ($raw['family_name'] ?? ''),
            ])]);
        }

        // The address is linked to a different Google account already.
        if ($visitor->google_id !== null && $visitor->google_id !== $googleId) {
            return $this->toApp(['google_error' => 'conflict']);
        }

        // SECURITY: an account nobody ever verified may have been registered
        // by someone else, with a password of theirs, in the hope its real
        // owner would turn up and use it. The owner just proved the inbox,
        // so that password - and any session it made - goes.
        if (!$visitor->hasVerifiedEmail()) {
            $visitor->forceFill(['password' => null, 'api_token' => null, 'token_expires_at' => null]);
        }

        $visitor->forceFill(['google_id' => $googleId])->save();
        $visitor->markEmailVerified();

        return $this->toApp(['google' => GoogleSignIn::handOff([
            'kind'       => 'signin',
            'visitor_id' => $visitor->visitor_id,
        ])]);
    }

    /**
     * POST /api/v1/visitors/google - the app hands in the code from the
     * fragment. An existing visitor is signed in, the same answer as a
     * password sign-in; someone new gets what the details form needs.
     */
    public function exchange(Request $request): JsonResponse
    {
        $code   = $request->input('code');
        $answer = is_string($code) ? GoogleSignIn::claim($code) : null;

        $visitor = ($answer['kind'] ?? null) === 'signin'
            ? Visitor::with('group')->find($answer['visitor_id'])
            : null;

        if (($answer['kind'] ?? null) === 'signup') {
            return response()->json([
                'google_signup' => $answer['google_signup'],
                'email'         => $answer['email'],
                'first_name'    => $answer['first_name'],
                'last_name'     => $answer['last_name'],
            ]);
        }

        if ($visitor === null) {
            return response()->json([
                'error'   => 'google_expired',
                'message' => 'That Google sign-in has expired. Please try again.',
            ], 422);
        }

        $visitor->touchReturning();
        $token = $visitor->issueToken();

        return response()->json($visitor->clearancePayload() + [
            'returning'  => true,
            'found'      => true,
            'token'      => $token['token'],
            'expires_at' => $token['expires_at'],
        ]);
    }

    /**
     * Built from this request unless one is configured, so it holds on
     * localhost, the LAN address and a tunnel alike. Whichever it is has to
     * be listed as an authorised redirect URI on the Google OAuth client.
     */
    private function callbackUrl(): string
    {
        return config('services.google.redirect') ?: url('/auth/google/callback');
    }

    /** Back to the app, with the answer in the fragment, which no server ever sees. */
    private function toApp(array $fragment): RedirectResponse
    {
        return redirect()->to(url('/visitor/index.html') . '#' . http_build_query($fragment))
            ->withoutCookie(self::STATE_COOKIE, '/auth/google');
    }
}
