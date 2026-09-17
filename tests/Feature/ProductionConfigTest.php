<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The guard on APP_DEBUG.
 *
 * .env is deliberately not in version control, so nothing carries the correct
 * value onto a server, and the template ships APP_DEBUG=true because that is
 * the right setting for local work. A production box brought up from that
 * template renders stack traces - file paths, config values, query bindings -
 * to anyone who can provoke an error, which at a public museum is anyone.
 *
 * The guard forces it off rather than refusing to boot: a panel the staff
 * cannot open is a worse Monday morning than one without debug output.
 */
class ProductionConfigTest extends TestCase
{
    private function debugAfterBootingIn(string $environment, bool $debug): bool
    {
        $this->app->detectEnvironment(fn () => $environment);
        config(['app.debug' => $debug]);

        (new AppServiceProvider($this->app))->boot();

        return (bool) config('app.debug');
    }

    public function test_debug_is_forced_off_in_production(): void
    {
        Log::spy();

        $this->assertFalse($this->debugAfterBootingIn('production', true));

        // Silently correcting it would leave the wrong .env on the server for
        // the next person to deploy from.
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_correctly_configured_production_box_is_left_alone(): void
    {
        Log::spy();

        $this->assertFalse($this->debugAfterBootingIn('production', false));

        Log::shouldNotHaveReceived('warning');
    }

    public function test_local_development_keeps_its_stack_traces(): void
    {
        $this->assertTrue($this->debugAfterBootingIn('local', true));
    }
}
