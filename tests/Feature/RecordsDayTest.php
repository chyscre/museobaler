<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Staff;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Records visitor tab is read a day at a time, and a day's page has to
 * hold everyone who was at the museum that day - not only the people who
 * happened to register on it. It used to page on created_at, so a visitor
 * who signed up last week and came back today appeared on last week's page
 * and never on today's.
 */
class RecordsDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_todays_page_includes_a_returning_visitor(): void
    {
        $desk = Staff::factory()->administrator()->create();
        Visitor::factory()->create(['first_name' => 'Newcomer', 'last_visit' => now()]);
        $back = Visitor::factory()->create(['first_name' => 'Returner', 'last_visit' => now()]);
        $back->forceFill(['created_at' => now()->subDays(6)])->save();

        $this->actingAs($desk)->get('/records?tab=visitors')
            ->assertOk()
            ->assertSee('Newcomer')
            ->assertSee('Returner')
            ->assertSee('2 visitors');
    }

    public function test_a_visitor_shows_on_each_day_they_attended(): void
    {
        $desk = Staff::factory()->administrator()->create();
        $v = Visitor::factory()->create(['first_name' => 'Regular', 'last_visit' => now()]);
        $v->forceFill(['created_at' => now()->subDays(10)])->save();
        Attendance::create(['visitor_id' => $v->visitor_id, 'method' => 'manual', 'visit_date' => today()->subDays(4)]);

        $this->actingAs($desk)->get('/records?tab=visitors&day=' . today()->subDays(4)->toDateString())
            ->assertOk()
            ->assertSee('Regular');
    }

    public function test_a_picked_date_with_nobody_on_it_says_so_rather_than_jumping(): void
    {
        $desk = Staff::factory()->administrator()->create();
        Visitor::factory()->create(['first_name' => 'Today', 'last_visit' => now()]);

        $empty = today()->subDays(20);

        $this->actingAs($desk)->get('/records?tab=visitors&day=' . $empty->toDateString())
            ->assertOk()
            ->assertSee($empty->format('l, j F Y'))
            ->assertSee('0 visitors')
            ->assertDontSee('>Today</td>', false)
            // The calendar opens on the day being shown, not on today.
            ->assertSee('value="' . $empty->toDateString() . '"', false);
    }

    public function test_the_day_bar_has_a_calendar_and_no_arrows(): void
    {
        $desk = Staff::factory()->administrator()->create();
        Visitor::factory()->create(['last_visit' => now()]);
        Visitor::factory()->create(['last_visit' => now()->subDay(), 'created_at' => now()->subDay()]);

        $this->actingAs($desk)->get('/records?tab=visitors')
            ->assertOk()
            ->assertSee('Pick a date')
            ->assertDontSee('rel="next"', false)
            ->assertDontSee('rel="prev"', false);
    }

    public function test_a_future_date_is_read_as_today(): void
    {
        $desk = Staff::factory()->administrator()->create();
        Visitor::factory()->create(['first_name' => 'Present', 'last_visit' => now()]);

        $this->actingAs($desk)->get('/records?tab=visitors&day=' . today()->addDays(5)->toDateString())
            ->assertOk()
            ->assertSee('Present')
            ->assertSee('max="' . today()->toDateString() . '"', false);
    }

    public function test_the_calendar_keeps_the_filters_that_are_on(): void
    {
        $desk = Staff::factory()->administrator()->create();
        Visitor::factory()->create(['last_visit' => now()]);

        $this->actingAs($desk)->get('/records?tab=visitors&vtype=Local&page=2')
            ->assertOk()
            ->assertSee('name="vtype" value="Local"', false)
            ->assertSee('name="tab" value="visitors"', false)
            ->assertDontSee('name="page"', false);
    }
}
