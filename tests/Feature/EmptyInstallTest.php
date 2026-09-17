<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Exhibit;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A museum that has not opened yet.
 *
 * DemoDataSeeder exists so the reports can be judged before the museum has
 * used them, and museum:clear-records exists to take all of it back out
 * again. What is left behind is an install with real exhibits and no traffic
 * whatsoever, which is the state every real deployment starts in and the one
 * that never gets clicked through while building.
 *
 * Two things have to hold here. Every screen has to open - a report that
 * divides by a visitor count is a white page on day one. And nothing may
 * invent a figure to fill the space: a ranking nobody earned, an average of
 * no ratings, a verdict on engagement that has not happened yet. A number on
 * a government screen gets believed.
 */
class EmptyInstallTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The museum's content survives the purge; only the traffic goes. So the
     * honest empty install still has exhibits sitting in it, which is exactly
     * the case that tempts a leaderboard into existence.
     */
    private function withExhibitsButNoTraffic(): void
    {
        $category = Category::create(['name' => 'History']);

        foreach (['Siege of Baler', 'Quezon Family', 'Baler Church'] as $i => $name) {
            Exhibit::create([
                'exhibit_code' => 'EXH-EMPTY-' . $i,
                'name'         => $name,
                'description'  => 'Kept by museum:clear-records — this is real content.',
                'category_id'  => $category->category_id,
                'status'       => 1,
            ]);
        }
    }

    public function test_every_museum_screen_opens_with_nothing_recorded(): void
    {
        $this->withExhibitsButNoTraffic();
        $staff = Staff::factory()->administrator()->create();

        foreach ([
            '/', '/desk', '/desk/poster', '/exhibits', '/tours', '/records',
            '/my/attendance', '/staff-attendance', '/attendance', '/feedback',
            '/logs', '/museum', '/map', '/qr-codes',
            '/reports/logbook', '/reports/visitors', '/reports/feedback',
            '/reports/exhibits', '/attendance/corrections',
            '/dashboard/chart/visitors', '/dashboard/chart/categories',
            '/notifications/poll',
        ] as $path) {
            $this->actingAs($staff)->get($path)
                ->assertOk("{$path} did not open on an empty install");
        }
    }

    public function test_every_tourism_screen_opens_with_nothing_recorded(): void
    {
        $this->withExhibitsButNoTraffic();
        $tourism = Staff::factory()->tourismHead()->create();
        $staff   = Staff::factory()->administrator()->create();

        foreach ([
            '/staff', '/staff-attendance', '/records', '/feedback', '/logs',
            '/attendance/corrections', '/reports/logbook', '/reports/visitors',
            '/reports/feedback', '/reports/exhibits',
            "/staff-attendance/{$staff->staff_id}",
            "/staff-attendance/{$staff->staff_id}/schedule",
            "/reports/dtr/{$staff->staff_id}",
        ] as $path) {
            $this->actingAs($tourism)->get($path)
                ->assertOk("{$path} did not open on an empty install");
        }

        // Streamed downloads, which do not go through assertOk cleanly.
        foreach (['/reports/audit/csv', '/reports/logbook/csv'] as $path) {
            $this->assertSame(
                200,
                $this->actingAs($tourism)->get($path)->baseResponse->getStatusCode(),
                "{$path} did not download on an empty install"
            );
        }
    }

    // -- Nothing may be invented to fill the space ----------------------

    public function test_the_dashboard_ranks_no_exhibit_that_nobody_scanned(): void
    {
        // Both panels rank exhibits, and an exhibit exists whether or not it
        // has ever been scanned. Without a floor they produce a league table
        // of exhibits on nil points - a ranking nobody earned.
        $this->withExhibitsButNoTraffic();
        $staff = Staff::factory()->administrator()->create();

        $page = $this->actingAs($staff)->get('/');

        $page->assertOk();
        $page->assertDontSee('Siege of Baler');
        $page->assertDontSee('Quezon Family');
        $page->assertDontSee('Baler Church');
        $page->assertSee('No scan data yet');
    }

    public function test_no_exhibit_is_accused_of_low_engagement_before_anyone_engages(): void
    {
        $this->withExhibitsButNoTraffic();
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->get('/')
            ->assertOk()
            ->assertDontSee('Low Engagement');
    }

    public function test_no_average_rating_is_shown_when_nobody_has_rated(): void
    {
        // round(null, 1) is 0.0, and a 0.0 star average reads as "everyone
        // hated it" rather than "nobody has said anything".
        $this->withExhibitsButNoTraffic();
        $staff = Staff::factory()->administrator()->create();

        $page = $this->actingAs($staff)->get('/');

        $page->assertOk();
        $page->assertSee('—', false);
        $page->assertDontSee('0.0');
    }
}
