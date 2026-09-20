<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * SECURITY: LoginRateLimiter Middleware
 *
 * Limits the number of login attempts per IP address to prevent brute-force attacks.
 * A brute-force attack is when an attacker systematically tries many passwords
 * until they find the correct one. Without rate limiting, an attacker could try
 * thousands of passwords per second.
 *
 * This middleware allows a maximum of 5 failed attempts per IP per minute.
 * After that, the IP is locked out for 60 seconds.
 *
 * Applied only to the POST /login route in bootstrap/app.php.
 */
class LoginRateLimiter
{
    public function __construct(protected RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'login:' . $request->ip();

        // Already locked out — show remaining time
        if ($this->limiter->tooManyAttempts($key, 5)) {
            $seconds = $this->limiter->availableIn($key);

            // Five wrong passwords in a minute is the shape of a guessing
            // script, not a person. Logged per refused attempt so the
            // security log shows how long the attempt kept going.
            Log::channel('security')->warning('Login locked out', [
                'email'    => $request->input('email'),
                'ip'       => $request->ip(),
                'retry_in' => $seconds,
            ]);

            return back()
                ->withErrors(['email' => 'Too many login attempts. Please wait.'])
                ->with('lockout_seconds', $seconds)
                ->withInput($request->only('email'));
        }

        $response = $next($request);

        if ($response->isRedirect() && session()->has('errors')) {
            // Each hit's own 60s decay window is irrelevant — tooManyAttempts()
            // only cares about the count within the limiter's internal window,
            // so a single hit() per failed attempt is all that's needed.
            $this->limiter->hit($key, 60);
        } else {
            $this->limiter->clear($key);
        }

        return $response;
    }
}
