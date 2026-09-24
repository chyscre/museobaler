<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestRedirectTest extends TestCase
{
    use RefreshDatabase;


    /**
     * The bare domain is the museum's public face, not a staff door. A
     * visitor who follows a poster instead of scanning a display case still
     * arrives at the visitor app.
     */
    public function test_guests_at_the_bare_domain_get_the_visitor_app(): void
    {
        $this->get('/')->assertRedirect('/visitor/');
    }

    /**
     * The dashboard itself is still behind auth. Moving it off '/' must not
     * have left it reachable.
     */
    public function test_guests_are_redirected_to_login_from_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    /**
     * Signed-in staff are passed on to wherever signing in would have put
     * them, which is not the same screen for both roles: the Tourism office
     * has no access to the dashboard and would meet a 403 there.
     */
    public function test_staff_at_the_bare_domain_go_to_their_own_home(): void
    {
        $this->actingAs(Staff::factory()->administrator()->create())
            ->get('/')->assertRedirect(route('dashboard'));

        $this->actingAs(Staff::factory()->tourismHead()->create())
            ->get('/')->assertRedirect(route('staff-attendance.index'));
    }
}
