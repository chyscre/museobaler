<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\LoginController;
use App\Models\Log;
use App\Models\Staff;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff attendance is outside the study's scope and switched off
 * (config/access.php). Switched off means gone from the panel: no link, no
 * page, no report - and nothing else breaking because it is gone.
 */
class StaffAttendanceOffTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['access.staff_attendance' => false]);
    }

    public function test_every_attendance_address_is_not_found(): void
    {
        $admin   = Staff::factory()->administrator()->create();
        $tourism = Staff::factory()->tourismHead()->create();

        foreach (['/my/attendance', '/attendance/kiosk', '/attendance/kiosk/qr', '/attendance/kiosk/tick',
                  '/staff-attendance', "/staff-attendance/{$admin->staff_id}", '/attendance/corrections',
                  "/reports/dtr/{$admin->staff_id}", '/reports/staff-attendance',
                  "/reports/dtr/export/csv?staff={$admin->staff_id}", '/reports/staff-attendance/export/pdf'] as $url) {
            $this->actingAs($admin)->get($url)->assertNotFound();
        }

        $this->actingAs($admin)->post('/my/attendance/scan')->assertNotFound();
        $this->actingAs($tourism)->get('/staff-attendance')->assertNotFound();
        $this->actingAs($tourism)->get("/staff-attendance/{$admin->staff_id}/schedule")->assertNotFound();
    }

    public function test_neither_panel_links_to_attendance(): void
    {
        $admin   = Staff::factory()->administrator()->create();
        $tourism = Staff::factory()->tourismHead()->create();

        foreach (['/dashboard', '/desk', '/tours', '/museum', '/records', '/logs'] as $url) {
            $page = $this->actingAs($admin)->get($url);
            $page->assertOk();
            $page->assertDontSee('Staff Attendance');
            $page->assertDontSee('My Attendance');
        }

        $this->actingAs($admin)->get('/desk')->assertDontSee('Staff check-in code');

        foreach (['/records', '/staff', '/feedback', '/logs'] as $url) {
            $this->actingAs($tourism)->get($url)->assertOk()->assertDontSee('Staff Attendance');
        }
    }

    public function test_the_tourism_office_lands_on_the_visitor_records(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();

        $this->assertSame(route('records.index'), LoginController::homeFor($tourism));
        $this->actingAs($tourism)->get('/')->assertRedirect(route('records.index'));
    }

    public function test_any_active_museum_staff_member_can_guide(): void
    {
        $guide   = Staff::factory()->administrator()->create(['name' => 'Juan Guide']);
        $visitor = Visitor::factory()->create();

        $this->actingAs($guide)->get('/tours')->assertOk()->assertSee('Juan Guide');

        $this->actingAs($guide)->post('/tours', [
            'guide_staff_id' => $guide->staff_id,
            'tour_type'      => 'Requested',
            'subject'        => "visitor:{$visitor->visitor_id}",
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('tours', ['guide_staff_id' => $guide->staff_id]);
    }

    public function test_the_tourism_office_cannot_be_assigned_as_a_guide(): void
    {
        $admin   = Staff::factory()->administrator()->create();
        $tourism = Staff::factory()->tourismHead()->create();
        $visitor = Visitor::factory()->create();

        $this->actingAs($admin)->post('/tours', [
            'guide_staff_id' => $tourism->staff_id,
            'tour_type'      => 'Requested',
            'subject'        => "visitor:{$visitor->visitor_id}",
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('tours', 0);
    }

    public function test_a_staff_phone_is_sent_to_museum_info_to_set_the_pin(): void
    {
        $admin = Staff::factory()->administrator()->create();

        $this->withHeader('User-Agent', self::PHONE)->actingAs($admin)
            ->get('/dashboard')->assertRedirect(route('museum.index'));

        $this->withHeader('User-Agent', self::PHONE)->actingAs($admin)
            ->get('/museum')->assertOk();
    }

    public function test_old_attendance_entries_leave_the_logs_page(): void
    {
        $admin = Staff::factory()->administrator()->create();

        foreach (['Schedule Updated' => 'Hidden schedule entry', 'Exhibit Updated' => 'Visible exhibit entry'] as $action => $details) {
            Log::create([
                'user_id' => $admin->staff_id, 'user_name' => $admin->name, 'role' => $admin->role,
                'action'  => $action, 'details' => $details, 'ip_address' => '127.0.0.1',
            ]);
        }

        $page = $this->actingAs($admin)->get('/logs');
        $page->assertOk();
        $page->assertSee('Visible exhibit entry');
        $page->assertDontSee('Hidden schedule entry');
    }
}
