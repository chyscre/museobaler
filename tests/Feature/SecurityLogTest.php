<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The security log: the lines an incident review starts from.
 *
 * The audit log records what signed-in staff did. These are the attempts
 * that never became a sign-in, or were refused after one - the wrong
 * password, the lockout, the role at the wrong door.
 */
class SecurityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_sign_in_is_logged_with_the_email_and_address(): void
    {
        Staff::factory()->create(['email' => 'desk@museobaler.test']);

        Log::shouldReceive('channel')->with('security')->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
            return $message === 'Login failed'
                && $context['email'] === 'desk@museobaler.test'
                && $context['ip'] !== null;
        });

        $this->post('/login', ['email' => 'desk@museobaler.test', 'password' => 'wrong']);
    }

    public function test_a_role_denial_is_logged_with_who_and_where(): void
    {
        $staff = Staff::factory()->administrator()->create();

        Log::shouldReceive('channel')->with('security')->andReturnSelf();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) use ($staff) {
            return $message === 'Role denied'
                && $context['staff_id'] === $staff->staff_id
                && $context['role'] === 'Administrator'
                && $context['path'] === 'staff';
        });

        $this->actingAs($staff)->get('/staff')->assertForbidden();
    }

    public function test_a_lockout_is_logged_every_time_it_refuses(): void
    {
        Staff::factory()->create(['email' => 'desk@museobaler.test']);

        Log::shouldReceive('channel')->with('security')->andReturnSelf();
        // Five failures, then the sixth is refused before it is even tried.
        Log::shouldReceive('warning')->times(5)->with('Login failed', \Mockery::type('array'));
        Log::shouldReceive('warning')->once()->with('Login locked out', \Mockery::on(
            fn (array $c) => $c['email'] === 'desk@museobaler.test' && $c['retry_in'] > 0
        ));

        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['email' => 'desk@museobaler.test', 'password' => 'wrong']);
        }
    }
}
