<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Log;
use App\Models\Staff;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Records and Logs are read by period - Year / Month / Week / Range, the
 * dashboard's own control - opening on the current week. (They used to be
 * read a day at a time.)
 *
 * A period has to hold everyone who was at the museum during it, not only
 * the people who registered in it: paging on created_at once put a visitor
 * who signed up last week and came back today on last week's page and never
 * on today's.
 */
class RecordsPeriodTest extends TestCase
{
    use RefreshDatabase;

    private function range($from, $to): string
    {
        return 'period=range&from=' . $from->toDateString() . '&to=' . $to->toDateString();
    }

    public function test_records_open_on_this_week_and_include_a_returning_visitor(): void
    {
        $desk = Staff::factory()->administrator()->create();
        Visitor::factory()->create(['first_name' => 'Newcomer', 'last_visit' => now()]);
        $back = Visitor::factory()->create(['first_name' => 'Returner', 'last_visit' => now()]);
        $back->forceFill(['created_at' => now()->subYear()])->save();

        $this->actingAs($desk)->get('/records?tab=visitors')
            ->assertOk()
            ->assertSee('Newcomer')
            ->assertSee('Returner')
            ->assertSeeInOrder(['class="seg-btn', 'Week'], false)
            ->assertSee('value="week"', false)
            ->assertSee('aria-pressed="true"', false);
    }

    public function test_a_visitor_shows_in_a_period_they_attended_in(): void
    {
        $desk = Staff::factory()->administrator()->create();
        $v = Visitor::factory()->create(['first_name' => 'Regular', 'last_visit' => now()]);
        $v->forceFill(['created_at' => now()->subDays(40)])->save();
        Attendance::create(['visitor_id' => $v->visitor_id, 'method' => 'manual', 'visit_date' => today()->subDays(20)]);

        $day = today()->subDays(20);
        $this->actingAs($desk)->get('/records?tab=visitors&' . $this->range($day, $day))
            ->assertOk()
            ->assertSee('Regular');
    }

    public function test_a_period_with_nobody_in_it_says_so(): void
    {
        $desk = Staff::factory()->administrator()->create();
        Visitor::factory()->create(['first_name' => 'Today', 'last_visit' => now()]);

        $empty = today()->subDays(20);

        $this->actingAs($desk)->get('/records?tab=visitors&' . $this->range($empty, $empty))
            ->assertOk()
            ->assertSee('No visitors in this period.')
            ->assertDontSee('>Today</td>', false)
            // The range pickers open on the period being shown.
            ->assertSee('value="' . $empty->toDateString() . '"', false);
    }

    public function test_the_period_bar_keeps_the_tab_and_filters_that_are_on(): void
    {
        $desk = Staff::factory()->administrator()->create();
        Visitor::factory()->create(['last_visit' => now()]);

        $this->actingAs($desk)->get('/records?tab=groups&vtype=Local&gpay=unpaid&page=2')
            ->assertOk()
            ->assertSee('name="vtype" value="Local"', false)
            ->assertSee('name="gpay" value="unpaid"', false)
            ->assertSee('name="tab" value="groups"', false)
            ->assertDontSee('name="page"', false);
    }

    public function test_logs_read_by_period_with_the_date_on_every_row(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();
        $old = Log::create(['user_name' => 'Desk', 'role' => 'Administrator', 'action' => 'Edit exhibit #4', 'details' => 'Done']);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        Log::create(['user_name' => 'Desk', 'role' => 'Administrator', 'action' => 'Sign in', 'details' => 'Done']);

        // This week: today's entry, not the one from forty days ago.
        $this->actingAs($tourism)->get('/logs')
            ->assertOk()
            ->assertSee('Sign in')
            ->assertDontSee('Edit exhibit #4')
            ->assertSee(now()->format('M j, Y'));

        $this->actingAs($tourism)->get('/logs?' . $this->range(today()->subDays(41), today()))
            ->assertOk()
            ->assertSee('Edit exhibit #4')
            // The report menu starts on the period shown.
            ->assertSee('value="' . today()->subDays(41)->toDateString() . '"', false);
    }

    public function test_the_action_filter_is_in_labelled_sections_exhibits_first(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();
        foreach (['Sign in', 'Mark visitor #3 as paid', 'Save the recognition model', 'Edit exhibit #4'] as $a) {
            Log::create(['user_name' => 'Desk', 'role' => 'Administrator', 'action' => $a, 'details' => 'Done']);
        }

        $this->actingAs($tourism)->get('/logs')
            ->assertOk()
            ->assertSeeInOrder([
                '<optgroup label="Exhibits">', 'Edit exhibit',
                '<optgroup label="Recognition">', 'Save the recognition model',
                '<optgroup label="Visitors &amp; admission">', 'Mark visitor as paid',
                '<optgroup label="Accounts &amp; sign-in">', 'Sign in',
            ], false);
    }

    public function test_the_dashboard_reports_through_the_museums_branded_reports(): void
    {
        $desk = Staff::factory()->administrator()->create();

        $this->actingAs($desk)->get('/dashboard?period=month&year=2026&month=9')
            ->assertOk()
            ->assertSee('Generate Report')
            ->assertSee(route('reports.visitors'), false)
            ->assertSee('value="2026-09-01"', false)
            // Printing the screen (sidebar and all) is gone.
            ->assertDontSee('window.print()', false)
            ->assertDontSee('exportDashboardCSV', false);
    }
}
