<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Exhibit;
use App\Models\Scan;
use App\Models\Staff;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The dashboard reports on one period at a time, and every part of it has to
 * agree on which one: a scan from August must not lift an exhibit up
 * September's ranking, and the trend chart must count the same visitors the
 * tile above it does.
 */
class DashboardPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-16 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function exhibit(string $name, string $category = 'History'): Exhibit
    {
        return Exhibit::create([
            'exhibit_code' => 'EXH-' . md5($name),
            'name'         => $name,
            'description'  => 'Test exhibit.',
            'category_id'  => Category::firstOrCreate(['name' => $category])->category_id,
            'status'       => 1,
        ]);
    }

    private function scan(Exhibit $exhibit, string $at): void
    {
        Scan::create(['exhibit_id' => $exhibit->exhibit_id, 'scanned_at' => $at]);
    }

    public function test_the_total_scans_tile_is_gone(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Total Scans');
    }

    public function test_rankings_and_categories_only_count_scans_inside_the_period(): void
    {
        $church = $this->exhibit('Baler Church', 'Architecture');
        $siege  = $this->exhibit('Siege of Baler');

        $this->scan($church, '2026-08-20 09:00:00');
        $this->scan($church, '2026-08-21 09:00:00');
        $this->scan($siege, '2026-09-10 09:00:00');

        $staff = Staff::factory()->administrator()->create();

        $page = $this->actingAs($staff)->get('/dashboard?period=month&year=2026&month=9');

        $page->assertOk();
        $page->assertSee('September 2026');
        $page->assertSee('Siege of Baler');
        $page->assertDontSee('Architecture');
        // The church sits at the bottom of September with nothing, which is
        // fair now that there is September traffic to compare it against.
        $page->assertSee('Low Engagement');

        $august = $this->actingAs($staff)->get('/dashboard?period=month&year=2026&month=8');
        $august->assertSee('Architecture');
    }

    public function test_the_trend_chart_buckets_by_day_for_a_week(): void
    {
        Visitor::factory()->create(['created_at' => '2026-09-14 08:00:00']);
        Visitor::factory()->count(2)->create(['created_at' => '2026-09-16 08:00:00']);
        Visitor::factory()->create(['created_at' => '2026-09-21 08:00:00']);

        $staff = Staff::factory()->administrator()->create();

        $json = $this->actingAs($staff)
            ->getJson('/dashboard/chart/visitors?period=week&week=2026-09-17')
            ->assertOk()
            ->json();

        $this->assertSame(['Mon 14', 'Tue 15', 'Wed 16', 'Thu 17', 'Fri 18', 'Sat 19', 'Sun 20'], $json['labels']);
        $this->assertSame([1, 0, 2, 0, 0, 0, 0], $json['values']);
    }

    public function test_the_trend_chart_buckets_by_month_for_a_year(): void
    {
        Visitor::factory()->create(['created_at' => '2025-12-31 23:00:00']);
        Visitor::factory()->count(3)->create(['created_at' => '2026-02-10 08:00:00']);

        $staff = Staff::factory()->administrator()->create();

        $json = $this->actingAs($staff)
            ->getJson('/dashboard/chart/visitors?period=year&year=2026')
            ->json();

        $this->assertCount(12, $json['labels']);
        $this->assertSame('Jan', $json['labels'][0]);
        $this->assertSame([0, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], $json['values']);
    }

    public function test_a_backwards_custom_range_is_turned_round(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->get('/dashboard?period=range&from=2026-09-15&to=2026-09-01')
            ->assertOk()
            ->assertSee('Sep 1 – Sep 15, 2026');
    }

    public function test_garbage_in_the_query_string_falls_back_to_this_year(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->get('/dashboard?period=decade&year=abc&week=not-a-date')
            ->assertOk()
            ->assertSee('Showing <strong>2026</strong>', false);

        $this->actingAs($staff)->get('/dashboard?period=week&week=2026-02-31')->assertOk();
        $this->actingAs($staff)->get('/dashboard?period=month&year=2026&month=13')->assertOk();
    }
}
