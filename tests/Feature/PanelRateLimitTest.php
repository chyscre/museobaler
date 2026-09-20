<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The panel-wide ceiling: generous enough that no person meets it, low
 * enough that a script with a stolen session cookie cannot walk the
 * records in seconds.
 */
class PanelRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_301st_request_in_a_minute_is_refused_with_the_custom_page(): void
    {
        $staff = Staff::factory()->administrator()->create();

        // Spend the budget without making 300 real requests. The throttle
        // middleware stores its counter under md5(limiter name . key).
        for ($i = 0; $i < 300; $i++) {
            RateLimiter::hit(md5('panel' . 'panel:' . $staff->staff_id), 60);
        }

        $this->actingAs($staff)->get('/records')
            ->assertStatus(429)
            ->assertSee('Slow down a moment');
    }

    public function test_each_account_has_its_own_budget(): void
    {
        $a = Staff::factory()->administrator()->create();
        $b = Staff::factory()->administrator()->create();

        for ($i = 0; $i < 300; $i++) {
            RateLimiter::hit(md5('panel' . 'panel:' . $a->staff_id), 60);
        }

        $this->actingAs($b)->get('/records')->assertOk();
    }
}
