<?php

namespace Tests\Feature;

use App\Models\MuseumInfo;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Services\AttendanceQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pins the two properties that make staff attendance worth trusting:
 * the code expires in seconds, and the phone has to be at the museum.
 *
 * A daily code alone stops none of the realistic cheating — it can be
 * photographed in the morning and reused all afternoon, or shared with the
 * whole team. These tests exist so that cannot quietly come back.
 */
class StaffAttendanceTest extends TestCase
{
    use RefreshDatabase;

    /** Museo de Baler, near enough for the distance maths to be meaningful. */
    private const MUSEUM_LAT = 15.7583;
    private const MUSEUM_LNG = 121.5608;

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned to a weekday morning so the "check out eight hours later"
        // case stays inside one work_date instead of rolling past midnight
        // whenever the suite happens to run in the evening.
        Carbon::setTestNow(Carbon::create(2026, 9, 9, 8, 0, 0));

        MuseumInfo::create([
            'name'              => 'Museo de Baler',
            'latitude'          => self::MUSEUM_LAT,
            'longitude'         => self::MUSEUM_LNG,
            'geofence_radius_m' => 150,
        ]);
    }

    private function onSite(): array
    {
        return ['latitude' => self::MUSEUM_LAT, 'longitude' => self::MUSEUM_LNG, 'accuracy' => 12];
    }

    private function currentCode(): string
    {
        return app(AttendanceQrService::class)->currentPayload();
    }

    public function test_staff_can_check_in_with_a_current_code_on_site(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $response = $this->actingAs($staff)
            ->postJson('/my/attendance/scan', ['code' => $this->currentCode()] + $this->onSite());

        $response->assertOk()->assertJson(['ok' => true, 'type' => 'in']);

        $this->assertDatabaseHas('staff_attendances', [
            'staff_id' => $staff->staff_id, 'type' => 'in', 'method' => 'qr',
        ]);
    }

    public function test_a_photographed_code_stops_working_once_it_rotates(): void
    {
        $staff = Staff::factory()->administrator()->create();

        // Snapped at 8am, tried again later in the day.
        $code = $this->currentCode();

        Carbon::setTestNow(now()->addMinutes(30));

        $response = $this->actingAs($staff)
            ->postJson('/my/attendance/scan', ['code' => $code] + $this->onSite());

        $response->assertStatus(422);
        $this->assertStringContainsString('expired', $response->json('message'));
        $this->assertDatabaseCount('staff_attendances', 0);

    }

    public function test_a_fresh_code_relayed_off_site_is_refused(): void
    {
        $staff = Staff::factory()->administrator()->create();

        // Code is valid, but the phone is roughly 8km away.
        $response = $this->actingAs($staff)->postJson('/my/attendance/scan', [
            'code'      => $this->currentCode(),
            'latitude'  => self::MUSEUM_LAT + 0.075,
            'longitude' => self::MUSEUM_LNG,
            'accuracy'  => 10,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('at the museum', $response->json('message'));
        $this->assertDatabaseCount('staff_attendances', 0);
    }

    // -- The testing switch -------------------------------------------------
    // ATTENDANCE_GEOFENCE=false lets a developer who is not in Baler test the
    // scan. It must be loud while it is on and impossible to leave on.

    public function test_with_the_geofence_off_an_off_site_scan_is_accepted_and_says_so(): void
    {
        config(['access.geofence' => false]);

        $staff = Staff::factory()->administrator()->create();

        $response = $this->actingAs($staff)->postJson('/my/attendance/scan', [
            'code'      => $this->currentCode(),
            'latitude'  => self::MUSEUM_LAT + 0.075,   // ~8km away
            'longitude' => self::MUSEUM_LNG,
            'accuracy'  => 10,
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        // Never allowed to read like a scan that passed the check.
        $this->assertStringContainsString('Geofence off for testing', $response->json('message'));
        // And the row still records how far away it really was.
        $this->assertGreaterThan(5000, StaffAttendance::first()->distance_m);
    }

    public function test_the_geofence_switch_is_ignored_in_production(): void
    {
        // Whatever is in .env, a live install checks the distance. The
        // service does not even read the setting there.
        config(['access.geofence' => false]);
        $this->app->detectEnvironment(fn () => 'production');

        // Laravel only waives CSRF while the environment reads "testing", so
        // pretending to be production brings it back. It is not what this
        // test is about.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $staff = Staff::factory()->administrator()->create();

        $response = $this->actingAs($staff)->postJson('/my/attendance/scan', [
            'code'      => $this->currentCode(),
            'latitude'  => self::MUSEUM_LAT + 0.075,
            'longitude' => self::MUSEUM_LNG,
            'accuracy'  => 10,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('at the museum', $response->json('message'));
        $this->assertDatabaseCount('staff_attendances', 0);
    }

    public function test_the_attendance_page_warns_while_the_geofence_is_off(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->get('/my/attendance')
            ->assertOk()
            ->assertDontSee('Testing mode');

        config(['access.geofence' => false]);

        $this->actingAs($staff)->get('/my/attendance')
            ->assertOk()
            ->assertSee('Testing mode')
            ->assertSee('ATTENDANCE_GEOFENCE=false');
    }

    public function test_the_geofence_switch_does_not_relax_the_code_check(): void
    {
        // Only the distance is skipped. A stale or forged code is still a
        // stale or forged code.
        config(['access.geofence' => false]);

        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->postJson('/my/attendance/scan', [
            'code' => 'MDB-ATT|2026-09-09|1|forgedsignature',
        ])->assertStatus(422);

        $this->assertDatabaseCount('staff_attendances', 0);
    }

    public function test_a_vague_location_is_refused(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $response = $this->actingAs($staff)->postJson('/my/attendance/scan', [
            'code' => $this->currentCode(),
            'latitude' => self::MUSEUM_LAT, 'longitude' => self::MUSEUM_LNG,
            'accuracy' => 900,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('staff_attendances', 0);
    }

    public function test_yesterdays_code_is_refused(): void
    {
        $staff = Staff::factory()->administrator()->create();
        $qr    = app(AttendanceQrService::class);

        $stale = $qr->payloadFor(today()->subDay(), $qr->currentWindow());

        $response = $this->actingAs($staff)
            ->postJson('/my/attendance/scan', ['code' => $stale] + $this->onSite());

        $response->assertStatus(422);
        $this->assertDatabaseCount('staff_attendances', 0);
    }

    public function test_a_forged_signature_is_refused(): void
    {
        $staff = Staff::factory()->administrator()->create();
        $qr    = app(AttendanceQrService::class);

        $forged = 'MDB-ATT|' . today()->toDateString() . '|' . $qr->currentWindow() . '|deadbeefcafe';

        $response = $this->actingAs($staff)
            ->postJson('/my/attendance/scan', ['code' => $forged] + $this->onSite());

        $response->assertStatus(422);
        $this->assertDatabaseCount('staff_attendances', 0);
    }

    public function test_a_second_scan_soon_after_check_in_does_not_close_the_day(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->postJson('/my/attendance/scan', ['code' => $this->currentCode()] + $this->onSite());
        $second = $this->actingAs($staff)->postJson('/my/attendance/scan', ['code' => $this->currentCode()] + $this->onSite());

        $second->assertOk()->assertJson(['noop' => true]);
        $this->assertDatabaseMissing('staff_attendances', ['staff_id' => $staff->staff_id, 'type' => 'out']);
    }

    public function test_checking_out_after_a_real_gap_records_an_out_row(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->postJson('/my/attendance/scan', ['code' => $this->currentCode()] + $this->onSite());

        Carbon::setTestNow(now()->addHours(8));

        $response = $this->actingAs($staff)
            ->postJson('/my/attendance/scan', ['code' => $this->currentCode()] + $this->onSite());

        $response->assertOk()->assertJson(['ok' => true, 'type' => 'out']);
        $this->assertDatabaseHas('staff_attendances', ['staff_id' => $staff->staff_id, 'type' => 'out']);

    }

    public function test_the_code_carries_no_identity_so_it_only_checks_in_the_scanner(): void
    {
        $maria = Staff::factory()->administrator()->create();
        $ana   = Staff::factory()->administrator()->create();

        // Maria scans; the same code cannot be used to check Ana in, because
        // who is checking in comes from the session, not the QR.
        $code = $this->currentCode();
        $this->actingAs($maria)->postJson('/my/attendance/scan', ['code' => $code] + $this->onSite());

        $this->assertDatabaseHas('staff_attendances', ['staff_id' => $maria->staff_id]);
        $this->assertDatabaseMissing('staff_attendances', ['staff_id' => $ana->staff_id]);
    }

    public function test_a_deactivated_account_cannot_record_attendance(): void
    {
        $staff = Staff::factory()->administrator()->create(['status' => false]);

        $this->actingAs($staff)
            ->postJson('/my/attendance/scan', ['code' => $this->currentCode()] + $this->onSite())
            ->assertStatus(403);

        $this->assertDatabaseCount('staff_attendances', 0);
    }

    public function test_the_staff_room_screen_needs_a_signed_in_account(): void
    {
        // The code is only useful to someone who can also sign in and scan
        // it, but the screen still must not be readable from the open web.
        $this->get('/attendance/kiosk')->assertRedirect('/login');
        $this->get('/attendance/kiosk/qr')->assertRedirect('/login');

        $this->actingAs(Staff::factory()->administrator()->create())
            ->get('/attendance/kiosk')->assertOk();
    }

    public function test_only_the_tourism_office_can_set_a_work_schedule(): void
    {
        // The schedule is what decides whether somebody is late, so museum
        // staff must not be able to move their own goalposts.
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)
            ->get("/staff-attendance/{$staff->staff_id}/schedule")->assertForbidden();

        $this->actingAs($staff)->post("/staff-attendance/{$staff->staff_id}/schedule", [
            'days' => [1 => ['shift_start' => '11:00', 'shift_end' => '17:00']],
        ])->assertForbidden();

        $this->assertDatabaseCount('staff_schedules', 0);

        $this->actingAs(Staff::factory()->tourismHead()->create())
            ->get("/staff-attendance/{$staff->staff_id}/schedule")->assertOk();
    }

    public function test_late_is_measured_against_the_schedule_including_grace(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $staff->schedules()->create([
            'weekday'       => (int) today()->dayOfWeek,
            'shift_start'   => '08:00',
            'shift_end'     => '17:00',
            'grace_minutes' => 15,
        ]);

        StaffAttendance::create([
            'staff_id'   => $staff->staff_id,
            'work_date'  => today(),
            'type'       => 'in',
            'scanned_at' => today()->copy()->setTime(8, 31),
        ]);

        $summary = app(\App\Services\AttendanceStatusService::class)
            ->summarise($staff->fresh(), today(), StaffAttendance::all());

        $this->assertSame('Late', $summary['status']);
        $this->assertSame(16, $summary['late_minutes']); // 8:31 against an 8:15 cutoff
    }
}
