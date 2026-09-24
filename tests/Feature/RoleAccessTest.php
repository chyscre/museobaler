<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two roles, and what actually separates them.
 *
 * Museo de Baler runs on a handful of staff who all do everything, so there
 * is one museum role. The only real boundary is between the museum and the
 * Municipal Tourism Office that oversees it:
 *
 *   Administrator  museum staff - the desk, the exhibits, the records
 *   TourismHead    the head of tourism - accounts, schedules, correction approvals, audit
 *
 * These pin that boundary in both directions, including the one thing
 * Tourism deliberately cannot do.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_museum_staff_cannot_create_staff_accounts(): void
    {
        // Personnel are assigned by the tourism office. If museum staff could
        // create accounts, the tier being overseen would be minting its own
        // overseers and the audit trail would mean nothing.
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->post('/staff', [
            'name' => 'New Person', 'email' => 'new@example.com',
            'password' => 'Password1!', 'role' => 'Administrator',
        ])->assertForbidden();

        $this->assertDatabaseMissing('staff', ['email' => 'new@example.com']);
    }

    public function test_museum_staff_cannot_even_list_staff_accounts(): void
    {
        $this->actingAs(Staff::factory()->administrator()->create())
            ->get('/staff')->assertForbidden();
    }

    public function test_the_tourism_office_can_create_staff_accounts(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();

        $this->actingAs($tourism)->post('/staff', [
            'name' => 'New Person', 'email' => 'new@example.com',
            'password' => 'Password1!', 'role' => 'Administrator',
        ])->assertRedirect(route('staff.index'));

        $this->assertDatabaseHas('staff', ['email' => 'new@example.com', 'role' => 'Administrator']);
    }

    public function test_museum_staff_write_the_exhibits_and_tourism_does_not(): void
    {
        // The one thing that runs the other way: Tourism oversees the museum
        // and reports on it, but does not author its exhibit labels.
        $staff   = Staff::factory()->administrator()->create();
        $tourism = Staff::factory()->tourismHead()->create();

        $payload = ['exhibit_code' => 'EXH-TEST-1', 'name' => 'Test Exhibit'];

        $this->actingAs($tourism)->post('/exhibits', $payload)->assertForbidden();
        $this->assertDatabaseMissing('exhibits', ['exhibit_code' => 'EXH-TEST-1']);

        $this->actingAs($staff)->post('/exhibits', $payload)->assertRedirect(route('exhibits.index'));
        $this->assertDatabaseHas('exhibits', ['exhibit_code' => 'EXH-TEST-1']);
    }

    public function test_the_tourism_panel_holds_only_oversight_screens(): void
    {
        // She oversees the museum; she does not run it. Everything
        // operational is shut off, not merely hidden from her sidebar.
        $tourism = Staff::factory()->tourismHead()->create();

        foreach (['/dashboard', '/exhibits', '/desk', '/tours', '/my/attendance',
                  '/museum', '/map', '/qr-codes', '/attendance'] as $url) {
            $this->actingAs($tourism)->get($url)->assertForbidden();
        }
    }

    public function test_the_tourism_panel_keeps_what_she_actually_needs(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();

        foreach (['/staff', '/staff-attendance', '/attendance/corrections',
                  '/records', '/feedback', '/logs'] as $url) {
            $this->actingAs($tourism)->get($url)->assertOk();
        }
    }

    public function test_signing_in_lands_each_role_somewhere_it_can_reach(): void
    {
        // The dashboard would 403 for the Tourism office, so she is sent to
        // the attendance board instead of into an error page every morning.
        $this->post('/login', [
            'email' => Staff::factory()->tourismHead()->create(['email' => 't@example.com'])->email,
            'password' => 'Password1!',
        ])->assertRedirect(route('staff-attendance.index'));

        $this->post('/logout');

        $this->post('/login', [
            'email' => Staff::factory()->administrator()->create(['email' => 'm@example.com'])->email,
            'password' => 'Password1!',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_signing_out_closes_everything(): void
    {
        foreach (['/dashboard', '/desk', '/records', '/logs', '/staff-attendance'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }
}
