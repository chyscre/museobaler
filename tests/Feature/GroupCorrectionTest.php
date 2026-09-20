<?php

namespace Tests\Feature;

use App\Models\MuseumInfo;
use App\Models\Staff;
use App\Models\Visitor;
use App\Models\VisitGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A local in a party that was charged for everyone.
 *
 * The commonest group here is out-of-town relatives visiting with a local,
 * and locals enter free. The desk used to be asked for "paying heads" - a
 * question no party can answer at a counter - so it defaulted to everyone
 * paying and the local got charged. Once the group was marked Paid nothing
 * could put that right: no edit, no refund, and the ₱50 handed back across
 * the counter never reached the logbook, which went on reporting it as
 * revenue.
 *
 * Now the desk is asked "anyone from Baler?", can correct the answer on the
 * row, and a correction after payment records the refund so the report and
 * the cash drawer agree.
 */
class GroupCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private float $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fee = MuseumInfo::admissionFee();
    }

    private function registerGroup(Staff $desk, array $overrides = []): VisitGroup
    {
        $this->actingAs($desk)->post('/desk/groups', $overrides + [
            'contact_name' => 'Santos family', 'group_type' => 'Family',
            'visitor_type' => 'Tourist', 'headcount' => 5, 'local_count' => 0,
        ]);

        return VisitGroup::latest('group_id')->firstOrFail();
    }

    private function correct(Staff $desk, VisitGroup $group, int $locals)
    {
        return $this->actingAs($desk)->post(route('desk.groups.correct', $group), ['local_count' => $locals]);
    }

    // -- The question at registration ---------------------------------------

    public function test_the_desk_says_how_many_are_from_baler_and_the_fee_follows(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 5, 'local_count' => 2]);

        $this->assertSame(2, $group->local_count);
        $this->assertSame(3, $group->paying_count);
        $this->assertEquals(3 * $this->fee, (float) $group->total_fee);
        $this->assertSame('Unpaid', $group->payment_status);
    }

    public function test_the_old_paying_heads_shape_is_still_understood(): void
    {
        // Anything still posting the previous field gets the same answer.
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 6, 'paying_count' => 4, 'local_count' => null]);

        $this->assertSame(2, $group->local_count);
        $this->assertSame(4, $group->paying_count);
    }

    public function test_a_local_group_is_all_locals_whatever_was_typed(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['visitor_type' => 'Local', 'headcount' => 40, 'local_count' => 3]);

        $this->assertSame(40, $group->local_count);
        $this->assertSame(0, $group->paying_count);
        $this->assertSame('Free', $group->payment_status);
    }

    public function test_more_locals_than_people_is_refused(): void
    {
        $desk = Staff::factory()->administrator()->create();

        $this->actingAs($desk)->post('/desk/groups', [
            'contact_name' => 'Typo', 'group_type' => 'Group',
            'visitor_type' => 'Tourist', 'headcount' => 3, 'local_count' => 7,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseCount('visit_groups', 0);
    }

    // -- Correcting before payment ------------------------------------------

    public function test_correcting_an_unpaid_group_just_reprices_it(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 5]);
        $this->assertEquals(5 * $this->fee, (float) $group->total_fee);

        $this->correct($desk, $group, 2)->assertRedirect()->assertSessionHas('success');

        $group->refresh();
        $this->assertSame(2, $group->local_count);
        $this->assertEquals(3 * $this->fee, (float) $group->total_fee);
        $this->assertSame('Unpaid', $group->payment_status);
        $this->assertEquals(0, (float) $group->refunded_amount);
        $this->assertDatabaseHas('logs', ['action' => 'Group Corrected']);
    }

    public function test_correcting_to_all_locals_makes_it_free(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 4]);

        $this->correct($desk, $group, 4);

        $group->refresh();
        $this->assertEquals(0, (float) $group->total_fee);
        $this->assertSame('Free', $group->payment_status);
    }

    // -- Correcting after payment: the money has to be accounted for ---------

    public function test_a_local_found_after_payment_becomes_a_recorded_refund(): void
    {
        // The case that started this: five charged, one of them from Baler,
        // already marked Paid. ₱50 goes back across the counter - and the
        // system knows.
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 5]);
        $this->actingAs($desk)->post(route('desk.groups.paid', $group));

        $this->correct($desk, $group, 1)->assertSessionHas('success');

        $group->refresh();
        $this->assertSame('Paid', $group->payment_status, 'what is owed has been paid');
        $this->assertEquals(4 * $this->fee, (float) $group->total_fee, 'owes for four now');
        $this->assertEquals(1 * $this->fee, (float) $group->refunded_amount);
        $this->assertNotNull($group->refunded_at);
        $this->assertSame($desk->staff_id, $group->refunded_by);

        $this->assertEquals(5 * $this->fee, $group->grossCollected(), 'what actually came in over the counter');

        $log = \App\Models\Log::where('action', 'Group Refund')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString(number_format($this->fee, 2), $log->details);
    }

    public function test_a_second_correction_adds_to_the_refund(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 5]);
        $this->actingAs($desk)->post(route('desk.groups.paid', $group));

        $this->correct($desk, $group, 1);
        $this->correct($desk, $group, 2);

        $group->refresh();
        $this->assertEquals(3 * $this->fee, (float) $group->total_fee);
        $this->assertEquals(2 * $this->fee, (float) $group->refunded_amount);
        $this->assertEquals(5 * $this->fee, $group->grossCollected());
    }

    public function test_a_local_who_turns_out_not_to_be_one_puts_the_group_back_to_unpaid(): void
    {
        // Registered with two locals, paid for three. Then the "locals" have
        // no ID. The two extra fees are owed; the group is Unpaid for them.
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 5, 'local_count' => 2]);
        $this->actingAs($desk)->post(route('desk.groups.paid', $group));

        $this->correct($desk, $group, 0)->assertSessionHas('success');

        $group->refresh();
        $this->assertSame('Unpaid', $group->payment_status);
        $this->assertEquals(5 * $this->fee, (float) $group->total_fee);
        $this->assertEquals(0, (float) $group->refunded_amount);
        $this->assertStringContainsString('Collect a further', session('success'));
    }

    public function test_the_logbook_shows_collected_refunded_and_net(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 5]);
        $this->actingAs($desk)->post(route('desk.groups.paid', $group));
        $this->correct($desk, $group, 1);

        $page = $this->actingAs($desk)->get('/reports/logbook');

        $page->assertOk()
            ->assertViewHas('collected', 5 * $this->fee)
            ->assertViewHas('refunded', 1 * $this->fee)
            ->assertViewHas('net', 4 * $this->fee)
            ->assertSee('Refunded');
    }

    public function test_the_logbook_says_nothing_about_refunds_when_there_were_none(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 5]);
        $this->actingAs($desk)->post(route('desk.groups.paid', $group));

        $this->actingAs($desk)->get('/reports/logbook')->assertOk()->assertDontSee('Refunded');
    }

    // -- Guard rails --------------------------------------------------------

    public function test_yesterdays_group_cannot_be_corrected_from_the_desk(): void
    {
        // Counted, banked, and in a report somebody has read. Changing it is
        // the Tourism office's call, not a button.
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 5]);
        $this->actingAs($desk)->post(route('desk.groups.paid', $group));

        Carbon::setTestNow(now()->addDay());

        $this->correct($desk, $group, 1)->assertSessionHas('error');

        $group->refresh();
        $this->assertSame(0, $group->local_count);
        $this->assertEquals(0, (float) $group->refunded_amount);
    }

    public function test_the_tourism_office_cannot_correct_a_group(): void
    {
        $desk    = Staff::factory()->administrator()->create();
        $tourism = Staff::factory()->tourismHead()->create();
        $group   = $this->registerGroup($desk, ['headcount' => 5]);

        $this->actingAs($tourism)->post(route('desk.groups.correct', $group), ['local_count' => 1])
            ->assertForbidden();
    }

    public function test_correcting_above_the_headcount_is_refused(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 3]);

        $this->correct($desk, $group, 4)->assertSessionHas('error');
        $this->assertSame(0, $group->fresh()->local_count);
    }

    // -- The system notices what the desk did not ---------------------------

    public function test_a_local_who_joins_in_the_app_is_flagged_when_the_group_is_paying_for_them(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 5, 'local_count' => 0]);

        Visitor::create([
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'visitor_type' => 'Local',
            'visit_type' => 'Family', 'email' => 'ana.reyes@example.com', 'auth_provider' => 'manual',
            'source' => 'app', 'group_id' => $group->group_id, 'city' => 'Baler',
            'admission_fee' => 0, 'payment_status' => 'Free', 'id_verified' => false, 'last_visit' => now(),
        ]);

        $this->assertSame(1, $group->unaccountedLocals());

        $this->actingAs($desk)->get('/desk')->assertOk()
            ->assertSee('1 local joined in the app');

        // Once the desk records that one local, the flag goes away.
        $this->correct($desk, $group, 1);
        $this->assertSame(0, $group->fresh()->unaccountedLocals());
        $this->actingAs($desk)->get('/desk')->assertOk()->assertDontSee('joined in the app');
    }

    public function test_the_desk_shows_a_correction_box_on_each_group_row(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 5]);

        $this->actingAs($desk)->get('/desk')->assertOk()
            ->assertSee(route('desk.groups.correct', $group), false)
            ->assertSee('Correct');
    }
}
