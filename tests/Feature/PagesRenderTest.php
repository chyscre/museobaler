<?php

namespace Tests\Feature;

use App\Models\MuseumInfo;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test: every new screen renders for the role that owns it.
 *
 * Blade errors only surface at render time, so a typo in a view would
 * otherwise stay invisible until someone opened the page in the museum.
 */
class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MuseumInfo::create([
            'name' => 'Museo de Baler', 'latitude' => 15.7583,
            'longitude' => 121.5608, 'geofence_radius_m' => 150,
        ]);
    }

    /** The whole of the Tourism office's panel — six screens plus reports. */
    public static function tourismPages(): array
    {
        return [
            'attendance board' => ['/staff-attendance'],
            'corrections'      => ['/attendance/corrections'],
            'staff list'       => ['/staff'],
            'visitor records'  => ['/records'],
            'feedback'         => ['/feedback'],
            'activity log'     => ['/logs'],
            'daily logbook'    => ['/reports/logbook'],
            'visitor report'   => ['/reports/visitors'],
            'feedback report'  => ['/reports/feedback'],
            'exhibit report'   => ['/reports/exhibits'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tourismPages')]
    public function test_page_renders_for_the_tourism_office(string $url): void
    {
        $this->actingAs(Staff::factory()->tourismHead()->create())
            ->get($url)->assertOk();
    }

    /** The operational screens, which only museum staff reach. */
    public static function museumPages(): array
    {
        return [
            'dashboard'         => ['/dashboard'],
            'front desk'        => ['/desk'],
            'guided tours'      => ['/tours'],
            'own attendance'    => ['/my/attendance'],
            'staff-room screen' => ['/attendance/kiosk'],
            'kiosk tick'        => ['/attendance/kiosk/tick'],
            'exhibits'          => ['/exhibits'],
            'museum info'       => ['/museum'],
            'map'               => ['/map'],
            'visitor check-ins' => ['/attendance'],
            'entrance poster'   => ['/desk/poster'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('museumPages')]
    public function test_page_renders_for_museum_staff(string $url): void
    {
        $this->actingAs(Staff::factory()->administrator()->create())
            ->get($url)->assertOk();
    }

    public function test_per_staff_attendance_and_schedule_render(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();
        $guide   = Staff::factory()->administrator()->create();

        $this->actingAs($tourism)->get("/staff-attendance/{$guide->staff_id}")->assertOk();
        $this->actingAs($tourism)->get("/staff-attendance/{$guide->staff_id}/schedule")->assertOk();
        $this->actingAs($tourism)->get("/reports/dtr/{$guide->staff_id}")->assertOk();
    }

    public function test_only_museum_staff_have_an_attendance_page(): void
    {
        // The head of tourism does not clock in, so she has no record of her
        // own to look at and no scanner to open.
        $this->actingAs(Staff::factory()->administrator()->create())
            ->get('/my/attendance')->assertOk();

        $this->actingAs(Staff::factory()->tourismHead()->create())
            ->get('/my/attendance')->assertForbidden();
    }

    public function test_the_tourism_office_is_not_listed_on_the_attendance_board(): void
    {
        // Asserted on the board data rather than the rendered HTML, because
        // her own role label shows in the sidebar of every page she opens.
        $tourism = Staff::factory()->tourismHead()->create(['name' => 'Tourism Office']);
        $member  = Staff::factory()->administrator()->create(['name' => 'Rosa Villanueva']);

        $board = app(\App\Services\AttendanceStatusService::class)->boardFor(today());
        $names = $board->map(fn ($row) => $row['staff']->name);

        // Listing her would put a permanent "Absent" against her name and
        // skew every count on the board.
        $this->assertContains('Rosa Villanueva', $names);
        $this->assertNotContains('Tourism Office', $names);

        $this->actingAs($tourism)->get('/staff-attendance')->assertOk();
    }

    public function test_the_tourism_office_has_no_dtr_or_schedule(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();

        $this->actingAs($tourism)->get("/staff-attendance/{$tourism->staff_id}")->assertNotFound();
        $this->actingAs($tourism)->get("/staff-attendance/{$tourism->staff_id}/schedule")->assertNotFound();
        $this->actingAs($tourism)->get("/reports/dtr/{$tourism->staff_id}")->assertNotFound();
    }

    public function test_the_rotating_qr_image_is_an_svg_and_is_never_cached(): void
    {
        $response = $this->actingAs(Staff::factory()->administrator()->create())
            ->get('/attendance/kiosk/qr');

        $response->assertOk();
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));

        // A drawn code, not the placeholder Qr falls back to when the QR
        // package is missing - which a phone would fail to scan all day
        // while the screen looked perfectly fine from across the staff room.
        $this->assertStringContainsString('<svg', $response->getContent());
        $this->assertStringNotContainsString('not installed', $response->getContent());

        // A cached image would keep showing a code that has already expired.
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_logbook_csv_downloads(): void
    {
        $response = $this->actingAs(Staff::factory()->administrator()->create())
            ->get('/reports/logbook/csv');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }
}
