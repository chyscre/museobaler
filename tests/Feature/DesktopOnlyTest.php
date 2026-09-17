<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panel is a desktop tool; a staff phone is a clock-in device.
 *
 * There is exactly one screen that has to work on a phone, and it is not a
 * convenience - clocking in means scanning the rotating code on the staff-room
 * screen, which needs a camera. Everything else in the panel is laid out for a
 * monitor, and handing someone a broken screen is worse than telling them
 * where to find a working one.
 *
 * Tablets are the case that must not be caught by accident: the front desk
 * register is meant to run on a counter tablet.
 */
class DesktopOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE   = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36';
    private const TABLET  = 'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';
    private const LAPTOP  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private function on(string $agent): self
    {
        $this->withHeader('User-Agent', $agent);

        return $this;
    }

    // -- What a phone may do --------------------------------------------

    public function test_a_phone_can_clock_in(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->on(self::PHONE)->actingAs($staff)->get('/my/attendance')->assertOk();
    }

    public function test_the_phone_attendance_page_drops_the_panel_chrome(): void
    {
        // The sidebar leads nowhere a phone is allowed to go, so shrinking it
        // to icons - all the panel stylesheet does at this width - would just
        // be a column of dead links.
        $staff = Staff::factory()->administrator()->create();

        $phone = $this->on(self::PHONE)->actingAs($staff)->get('/my/attendance');
        $phone->assertOk();
        $phone->assertDontSee('sidebar-nav', false);
        $phone->assertSee('Scan to check in');

        $desk = $this->on(self::LAPTOP)->actingAs($staff)->get('/my/attendance');
        $desk->assertOk();
        $desk->assertSee('sidebar-nav', false);
    }

    public function test_a_phone_can_still_set_its_own_password(): void
    {
        // A new account is often handed over and first signed into standing
        // in the staff room, phone in hand.
        $staff = Staff::factory()->administrator()->awaitingPasswordChange()->create();

        $this->on(self::PHONE)->actingAs($staff)->get('/my/password')->assertOk();
    }

    // -- What a phone may not do ----------------------------------------

    public function test_a_phone_is_sent_back_to_attendance(): void
    {
        $staff = Staff::factory()->administrator()->create();

        foreach (['/', '/desk', '/exhibits', '/records', '/reports/visitors'] as $path) {
            $this->on(self::PHONE)->actingAs($staff)->get($path)
                ->assertRedirect(route('my.attendance'));
        }
    }

    public function test_an_android_phone_is_treated_the_same(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->on(self::ANDROID)->actingAs($staff)->get('/exhibits')
            ->assertRedirect(route('my.attendance'));
    }

    public function test_the_tourism_office_has_no_phone_screen_at_all(): void
    {
        // She does not clock in, so redirecting her to attendance would be a
        // loop. She gets told where the panel lives instead.
        $tourism = Staff::factory()->tourismHead()->create();

        $this->on(self::PHONE)->actingAs($tourism)->get('/staff')
            ->assertForbidden()
            ->assertSee('Open this on a computer');
    }

    // -- What must not be caught by it ----------------------------------

    public function test_the_counter_tablet_still_runs_the_front_desk(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->on(self::TABLET)->actingAs($staff)->get('/desk')->assertOk();
    }

    public function test_a_computer_is_untouched(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->on(self::LAPTOP)->actingAs($staff)->get('/')->assertOk();
        $this->on(self::LAPTOP)->actingAs($staff)->get('/exhibits')->assertOk();
    }

    public function test_a_request_with_no_user_agent_is_let_through(): void
    {
        // Health checks, curl, and the test suite's own default. Erring
        // towards desktop is the harmless direction: EnsureRole is what
        // actually decides who may reach what.
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->get('/')->assertOk();
    }

    public function test_it_can_be_switched_off_for_debugging_on_a_phone(): void
    {
        config(['access.desktop_only' => false]);

        $staff = Staff::factory()->administrator()->create();

        $this->on(self::PHONE)->actingAs($staff)->get('/exhibits')->assertOk();
    }

    // -- It is not a security boundary ----------------------------------

    public function test_the_role_boundary_still_does_the_real_work(): void
    {
        // A user-agent is trivially forged, so nothing may depend on this
        // middleware to keep anybody out of anything. Museum staff pretending
        // to be a desktop still cannot reach the Tourism screens.
        $staff = Staff::factory()->administrator()->create();

        $this->on(self::LAPTOP)->actingAs($staff)->get('/staff')->assertForbidden();
    }
}
