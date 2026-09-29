<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
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

    /**
     * What a crash actually says out loud once debug is off.
     *
     * The guard above proves the setting is forced; this proves what the
     * setting buys. A museum's error is provoked by ordinary use - a report
     * asked for while the database is restarting - and the answer must carry
     * nothing about the machine: no file paths, no framework internals, no
     * SQL, and not the exception's own message, which is where a driver puts
     * the table and column it could not find.
     *
     * The message is deliberately about what to do next, and the `ref` is a
     * hash of the throwing line, so a report of "ref 4a7c1e9b" can be matched
     * to the log without the person on the phone reading out a stack trace.
     */
    public function test_a_crash_with_debug_off_says_nothing_about_the_server(): void
    {
        config(['app.debug' => false]);

        $boom = fn () => throw new \RuntimeException(
            'SQLSTATE[42S02]: Base table or view not found: museobaler.visitors_secret'
        );

        Route::get('/api/v1/_audit_probe', $boom);
        Route::get('/_audit_probe', $boom);

        $json = $this->getJson('/api/v1/_audit_probe');
        $html = $this->get('/_audit_probe');

        $json->assertStatus(500)
            ->assertJsonPath('error', fn (string $m) => str_contains($m, 'museum server'))
            ->assertJsonPath('ref', fn (string $r) => strlen($r) === 8);

        foreach ([$json->getContent(), $html->getContent()] as $body) {
            foreach ([
                'SQLSTATE', 'visitors_secret', 'RuntimeException', 'ProductionConfigTest',
                '.php', 'vendor', 'C:\\', '/var/www', 'APP_KEY', 'base64:',
            ] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "a 500 must not mention {$leak}");
            }
        }

        $html->assertStatus(500);
    }
}
