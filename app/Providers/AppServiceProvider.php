<?php

namespace App\Providers;

use App\Services\Gemini;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
