<?php

namespace App\Providers;

use App\Models\Visitor;
use App\Services\Gemini;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The AI behind the exhibit form; built from config/services.php.
        $this->app->bind(Gemini::class, fn () => Gemini::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * SECURITY: production must never render a stack trace.
         *
         * APP_DEBUG lives in .env, which is deliberately not in version
         * control, so nothing carries the correct value onto a server - and
         * the template ships true for local work. Left that way in
         * production, Laravel's error page prints file paths, config values
         * and query bindings to whoever triggered the error.
         *
         * Forcing it off beats refusing to boot: a museum that cannot open
         * its panel because of a config typo is a worse failure than one
         * running without debug output. The log line is what tells whoever
         * set the server up that the .env still needs correcting.
         */
        if ($this->app->environment('production') && config('app.debug')) {
            config(['app.debug' => false]);
            Log::warning('APP_DEBUG was true in production and has been forced off. Correct .env on this server.');
        }

        $this->rateLimitAiCalls();

        // Laravel defaults to Tailwind markup; this project styles the
        // Bootstrap-shaped classes instead, so links() rendered unstyled.
        \Illuminate\Pagination\Paginator::defaultView("vendor.pagination.museo");
        \Illuminate\Pagination\Paginator::defaultSimpleView("vendor.pagination.museo");
        $this->rateLimitPanel();
        $this->visitorGuard();
        $this->rateLimitVisitorApi();
        $this->logSecurityEvents();
    }

    /**
     * The `visitor` guard: a bearer token, resolved to a Visitor row.
     *
     * Only the Authorization header is read. The old raw-PHP API also took
     * the token from a query string or the body, for a service worker that
     * no longer exists; a token in a URL ends up in access logs and browser
     * history, so that door is closed.
     */
    private function visitorGuard(): void
    {
        Auth::viaRequest('visitor-token', function (Request $request) {
            return Visitor::findByToken($request->bearerToken());
        });
    }

    /**
     * SECURITY: ceilings on the visitor API.
     *
     * Keyed by address, so every phone behind the museum's Wi-Fi NAT shares
     * one budget - the limits are set for a floor of visitors, not a phone.
     * Keying on anything the client supplies would let an attacker mint
     * unlimited buckets, which defeats the point.
     *
     * Sign-in gets its own, much tighter budget: enough requests for
     * browsing is far too many password guesses. Keyed on the email being
     * targeted as well as the address, so one attacker cannot lock out a
     * museum's worth of visitors behind one NAT, and so spraying one guess
     * across many accounts from one address is still caught.
     */
    private function rateLimitVisitorApi(): void
    {
        RateLimiter::for('visitor-api', fn (Request $request) => Limit::perMinute(60)->by('api:' . $request->ip()));

        // Camera sampling every few seconds, several phones on one address.
        RateLimiter::for('visitor-recognition', fn (Request $request) => Limit::perMinute(120)->by('recog:' . $request->ip()));

        RateLimiter::for('visitor-register', fn (Request $request) => Limit::perMinute(10)->by('register:' . $request->ip()));

        RateLimiter::for('visitor-login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(6)->by('login:acct:' . md5($email)),
                Limit::perMinutes(5, 30)->by('login:ip:' . $request->ip()),
            ];
        });
    }

    /**
     * A ceiling on the admin panel as a whole.
     *
     * Not a defence against a person - nobody clicks 300 times a minute -
     * but against a script that has a session cookie, or a browser tab gone
     * wrong. The busiest legitimate client is a signed-in tab polling the
     * notification bell every ten seconds beside a kiosk syncing once a
     * minute, which is under ten requests a minute. Three hundred leaves
     * room for a spreadsheet of visitors being registered in a hurry and
     * still stops a scraper walking the records pages in seconds.
     *
     * Keyed on the account, falling back to the address for the sign-in
     * pages that have no account yet.
     */
    private function rateLimitPanel(): void
    {
        RateLimiter::for('panel', function (Request $request) {
            $who = $request->user()?->getAuthIdentifier() ?: $request->ip();

            return Limit::perMinute(300)->by("panel:$who");
        });
    }

    /**
     * SECURITY: the events an incident review starts from, on their own
     * channel.
     *
     * The audit log records what signed-in staff did. This records the
     * attempts that never became a sign-in: the wrong password, the locked
     * account, the role that tried a door it has no key to. Those are the
     * lines that tell a brute-force attempt apart from a forgotten password,
     * and they belong in a file that ordinary application noise does not
     * scroll off the end of. See config/logging.php, channel 'security'.
     */
    private function logSecurityEvents(): void
    {
        Event::listen(Failed::class, function (Failed $event) {
            Log::channel('security')->warning('Login failed', [
                'email' => $event->credentials['email'] ?? null,
                'ip'    => request()->ip(),
            ]);
        });

        Event::listen(Login::class, function (Login $event) {
            Log::channel('security')->info('Login', [
                'staff_id' => $event->user->getAuthIdentifier(),
                'role'     => $event->user->role ?? null,
                'ip'       => request()->ip(),
            ]);
        });

        Event::listen(Logout::class, function (Logout $event) {
            Log::channel('security')->info('Logout', [
                'staff_id' => $event->user?->getAuthIdentifier(),
                'ip'       => request()->ip(),
            ]);
        });
    }

    /**
     * A ceiling on the Gemini endpoints.
     *
     * These are the only routes that spend money, and they are the only ones
     * where a stuck retry loop or a staff member leaning on "Generate audio"
     * costs something real - a quota exhausted mid-afternoon takes the
     * feature down for everyone until it resets.
     *
     * Writing one exhibit is four calls: one translate covering all three
     * languages, then a narration each. Twenty a minute is several exhibits
     * a minute, well past the pace of someone actually reading the drafts,
     * and the daily ceiling is roughly seventy-five fully narrated exhibits -
     * more than the museum holds.
     */
    private function rateLimitAiCalls(): void
    {
        RateLimiter::for('ai', function (Request $request) {
            $who = $request->user()?->getAuthIdentifier() ?: $request->ip();

            $tooMany = fn () => response()->json([
                'error' => 'That is a lot of AI requests at once. Wait a moment and try again.',
            ], 429);

            return [
                Limit::perMinute(20)->by("ai-minute:$who")->response($tooMany),
                Limit::perDay(300)->by("ai-day:$who")->response($tooMany),
            ];
        });
    }
}
