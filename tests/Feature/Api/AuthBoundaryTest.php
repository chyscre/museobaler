<?php

namespace Tests\Feature\Api;

use App\Models\Staff;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The wall between the two populations.
 *
 * Staff sign in with a session and a role; visitors carry a bearer token.
 * Neither credential may open the other side's doors: a visitor's token
 * must not reach the panel, and a staff session must not count as a
 * cleared visitor. The two guards read different things, and these pin
 * that they never overlap.
 */
class AuthBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(Visitor $v): array
    {
        return ['Authorization' => 'Bearer ' . $v->issueToken()['token']];
    }

    public function test_a_visitor_token_opens_nothing_in_the_panel(): void
    {
        $v = Visitor::factory()->paid()->create();
        $h = $this->bearer($v);

        foreach (['/dashboard', '/exhibits', '/desk', '/records', '/staff', '/museum', '/logs', '/qr-codes'] as $path) {
            $this->get($path, $h)->assertRedirect('/login');
        }

        // Nor the panel's write actions.
        $this->post('/desk/visitors', ['first_name' => 'X', 'last_name' => 'Y'], $h)->assertRedirect('/login');
        $this->post("/visitors/{$v->visitor_id}/mark-paid", [], $h)->assertRedirect('/login');
        $this->assertSame('Unpaid', Visitor::factory()->create()->payment_status);
    }

    public function test_a_visitor_token_cannot_read_the_panels_media_proxies(): void
    {
        $v = Visitor::factory()->paid()->create();

        $this->get('/exhibit-image/anything.jpg', $this->bearer($v))->assertRedirect('/login');
        $this->get('/training-image/anything.jpg', $this->bearer($v))->assertRedirect('/login');
    }

    public function test_a_staff_session_is_not_a_visitor(): void
    {
        $admin = Staff::factory()->administrator()->create();

        // Signed in to the panel, but the visitor guard reads a bearer token
        // and nothing else: no token, no visitor.
        $this->actingAs($admin)->getJson('/api/v1/exhibits')->assertStatus(401);
        $this->actingAs($admin)->getJson('/api/v1/visitors/me')->assertStatus(401);
        $this->actingAs($admin)->postJson('/api/v1/scans', ['exhibit_id' => 1])->assertStatus(401);
    }

    public function test_the_visitor_guard_ignores_a_token_anywhere_but_the_header(): void
    {
        $v   = Visitor::factory()->paid()->create();
        $raw = $v->issueToken()['token'];

        // The old API accepted ?token= and a body field. Those put the
        // token in access logs and browser history; this one does not.
        $this->getJson('/api/v1/visitors/me?token=' . $raw)->assertStatus(401);
        $this->postJson('/api/v1/scans', ['exhibit_id' => 1, 'token' => $raw])->assertStatus(401);
        $this->getJson('/api/v1/visitors/me', ['Authorization' => 'Bearer ' . $raw])->assertOk();
    }

    public function test_one_visitors_token_never_reaches_another_visitors_record(): void
    {
        $me    = Visitor::factory()->paid()->create(['first_name' => 'Me']);
        $other = Visitor::factory()->paid()->create(['first_name' => 'Other']);

        $this->getJson('/api/v1/visitors/me?visitor_id=' . $other->visitor_id, $this->bearer($me))
            ->assertOk()->assertJsonPath('first_name', 'Me');
    }

    public function test_the_gate_is_reevaluated_on_every_request(): void
    {
        // Cleared, then un-cleared by the desk (a new day, fee due again):
        // the same token gets 403 on its next request, not on its next login.
        $v = Visitor::factory()->paid()->create();
        $h = $this->bearer($v);

        $this->getJson('/api/v1/exhibits', $h)->assertOk();

        $v->forceFill(['payment_status' => 'Unpaid'])->save();

        $this->getJson('/api/v1/exhibits', $h)->assertStatus(403)->assertJsonPath('clearance', 'pending_payment');
    }

    public function test_a_disabled_visitor_token_dies_with_sign_out_even_if_the_phone_kept_it(): void
    {
        $v   = Visitor::factory()->paid()->create();
        $h   = $this->bearer($v);

        $this->postJson('/api/v1/visitors/logout', [], $h)->assertOk();
        $this->getJson('/api/v1/exhibits', $h)->assertStatus(401);
    }
}
