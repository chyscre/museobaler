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
 * A party arriving together, seen from both doors.
 *
 * The desk registers a party as one record - a headcount and one payment -
 * and hands it a code. A member who types that code into the app is counted
 * inside the headcount rather than as an extra visitor owing a second fee,
 * and is unlocked when the group is. Before the code existed the two ways in
 * did not know about each other: five people plus two app registrations was
 * seven on the board and two fees owed twice.
 *
 * The visitor API is plain PHP against MySQL and is not exercised here. What
 * is pinned is everything the admin side has to hold for that API to be
 * right: the code, the cap, the group's payment standing in for its members,
 * and the screens that were missing.
 */
class GroupJoinTest extends TestCase
{
    use RefreshDatabase;

    private function registerGroup(Staff $desk, array $overrides = []): VisitGroup
    {
        $this->actingAs($desk)->post('/desk/groups', $overrides + [
            'contact_name' => 'Maria Santos', 'group_type' => 'Group',
            'visitor_type' => 'Tourist', 'headcount' => 5,
        ]);

        return VisitGroup::latest('group_id')->firstOrFail();
    }

    /** What the API's `register` with a group_code produces. */
    private function member(VisitGroup $group, array $overrides = []): Visitor
    {
        return Visitor::create($overrides + [
            'first_name'     => 'Member',
            'last_name'      => 'Of' . $group->group_id,
            'visitor_type'   => 'Tourist',
            'visit_type'     => 'Group',
            'email'          => uniqid('m', true) . '@example.com',
            'auth_provider'  => 'manual',
            'source'         => 'app',
            'group_id'       => $group->group_id,
            'admission_fee'  => 0,
            'payment_status' => 'Free',
            'id_verified'    => false,
            'last_visit'     => now(),
        ]);
    }

    // -- The form as a browser actually submits it ---------------------------

    public function test_the_group_form_works_with_every_optional_box_left_blank(): void
    {
        // A browser posts every field on the form, blank ones included, and
        // Laravel turns blank into null. The desk's default is to leave
        // "Paying heads" empty - it says so on the box - and that null used
        // to override the computed count and crash the insert. Every test
        // before this one omitted the key instead of sending it empty, which
        // is not what a form does.
        $desk = Staff::factory()->administrator()->create();

        $this->actingAs($desk)->post('/desk/groups', [
            'contact_name'  => 'Jun Molina',
            'headcount'     => '3',
            'group_type'    => 'Group',
            'visitor_type'  => 'Tourist',
            'paying_count'  => '',
            'city'          => '',
            'group_name'    => '',
            'contact_phone' => '',
            'province'      => '',
            'country'       => '',
            'notes'         => '',
        ])->assertRedirect(route('desk.register'))->assertSessionHasNoErrors();

        $group = VisitGroup::firstOrFail();

        $this->assertSame(3, $group->paying_count);
        $this->assertEquals(3 * MuseumInfo::admissionFee(), (float) $group->total_fee);
        $this->assertSame('Unpaid', $group->payment_status);
        $this->assertNotNull($group->join_code);
    }

    public function test_a_local_group_with_the_paying_box_blank_is_free(): void
    {
        $desk = Staff::factory()->administrator()->create();

        $this->actingAs($desk)->post('/desk/groups', [
            'contact_name' => 'Ana Reyes', 'headcount' => '4', 'group_type' => 'Family',
            'visitor_type' => 'Local', 'paying_count' => '', 'city' => '',
        ])->assertRedirect(route('desk.register'));

        $group = VisitGroup::firstOrFail();

        $this->assertSame(0, $group->paying_count);
        $this->assertSame('Free', $group->payment_status);
    }

    // -- The code ----------------------------------------------------------

    public function test_registering_a_group_hands_the_desk_a_code_to_read_out(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk);

        $this->assertNotNull($group->join_code);
        $this->assertSame(VisitGroup::JOIN_CODE_LENGTH, strlen($group->join_code));
        // Nothing a party will argue about across a counter.
        $this->assertDoesNotMatchRegularExpression('/[01OI]/', $group->join_code);

        $this->assertSame($group->join_code, session('join_code')['code']);
    }

    public function test_the_code_is_only_good_for_today(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk);

        $this->assertNotNull(VisitGroup::joinable($group->join_code));
        $this->assertNotNull(VisitGroup::joinable(strtolower(" {$group->join_code} ")));

        Carbon::setTestNow(now()->addDay());

        // Yesterday's code admits nobody, however many seats were left.
        $this->assertNull(VisitGroup::joinable($group->join_code));
    }

    public function test_the_code_stops_working_once_the_headcount_has_joined(): void
    {
        // A code that grants free entry cannot be allowed to admit a sixth
        // person on a party of five.
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 2]);

        $this->assertFalse($group->isFull());
        $this->member($group);
        $this->assertFalse($group->fresh()->isFull());
        $this->member($group);
        $this->assertTrue($group->fresh()->isFull());
    }

    // -- The group's payment stands in for its members ----------------------

    public function test_a_member_is_locked_until_the_group_pays_and_unlocked_when_it_does(): void
    {
        $desk   = Staff::factory()->administrator()->create();
        $group  = $this->registerGroup($desk);
        $member = $this->member($group);

        $this->assertFalse($member->isCleared());
        // Same words as any unpaid visitor: the "With …" line on the row is
        // what says the fee is collected once for the party.
        $this->assertSame('Waiting for payment', $member->clearanceLabel());

        $this->actingAs($desk)->post("/desk/groups/{$group->group_id}/paid")->assertRedirect();

        $this->assertTrue($member->fresh()->isCleared());
    }

    public function test_a_local_groups_members_are_cleared_at_once(): void
    {
        // The desk registered the party face to face; that was the ID check.
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['visitor_type' => 'Local']);

        $this->assertSame('Free', $group->payment_status);
        $this->assertTrue($this->member($group, ['visitor_type' => 'Local'])->isCleared());
    }

    public function test_a_group_from_a_previous_visit_has_no_say_today(): void
    {
        // group_id is kept as history. It must not keep somebody unlocked on
        // a later visit they have not paid for.
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk);
        $this->actingAs($desk)->post("/desk/groups/{$group->group_id}/paid");

        $member = $this->member($group);
        $this->assertTrue($member->isCleared());

        Carbon::setTestNow(now()->addDay());
        // The API resets the fee on a new day; mirror that here.
        $member->update(['admission_fee' => MuseumInfo::admissionFee(), 'payment_status' => 'Unpaid']);

        $this->assertFalse($member->fresh()->isCleared());
        $this->assertSame('Waiting for payment', $member->fresh()->clearanceLabel());
    }

    // -- Counted once -------------------------------------------------------

    public function test_members_are_inside_the_headcount_not_on_top_of_it(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk, ['headcount' => 5]);
        $this->member($group);
        $this->member($group);

        // The desk: 5 people, not 7.
        $this->actingAs($desk)->get('/desk')->assertOk()->assertSee('5');
        $this->assertSame(5, $this->headcountOnDesk($desk));

        // The logbook agrees.
        $this->actingAs($desk)->get('/reports/logbook')->assertOk()
            ->assertViewHas('headcount', 5);
    }

    public function test_members_do_not_clog_the_entrance_queue(): void
    {
        // Five members waiting on one payment is one action for the desk, on
        // the group - not five rows in the pending list.
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk);
        $this->member($group);
        $this->member($group);

        $this->assertSame(0, Visitor::pendingClearance()->count());
    }

    // -- The screens that were missing --------------------------------------

    public function test_the_desk_lists_todays_groups_with_their_code_and_a_mark_paid_button(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk);

        $this->actingAs($desk)->get('/desk')->assertOk()
            ->assertSee("Maria Santos's group")
            ->assertSee($group->join_code)
            ->assertSee('Mark Paid');
    }

    public function test_records_has_a_groups_tab_where_a_group_can_finally_be_marked_paid(): void
    {
        // Until this tab existed the route was there and no screen called it:
        // every tourist party stayed Unpaid forever and the logbook's
        // outstanding figure grew with money that had actually been collected.
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk);

        $page = $this->actingAs($desk)->get('/records?tab=groups');
        $page->assertOk()
            ->assertSee("Maria Santos's group")
            ->assertSee(route('desk.groups.paid', $group), false)
            ->assertSee('Mark Paid');

        $this->actingAs($desk)->post(route('desk.groups.paid', $group))->assertRedirect();

        $this->assertSame('Paid', $group->fresh()->payment_status);
        $this->assertNotNull($group->fresh()->paid_at);
        $this->assertDatabaseHas('logs', ['action' => 'Group Payment']);
    }

    public function test_the_tourism_office_sees_groups_but_does_not_collect_money(): void
    {
        $desk    = Staff::factory()->administrator()->create();
        $tourism = Staff::factory()->tourismHead()->create();
        $group   = $this->registerGroup($desk);

        $this->actingAs($tourism)->get('/records?tab=groups')->assertOk()
            ->assertSee("Maria Santos's group")
            ->assertDontSee('Mark Paid');

        $this->actingAs($tourism)->post(route('desk.groups.paid', $group))->assertForbidden();
        $this->assertSame('Unpaid', $group->fresh()->payment_status);
    }

    public function test_a_member_shows_in_records_as_covered_by_their_group(): void
    {
        $desk  = Staff::factory()->administrator()->create();
        $group = $this->registerGroup($desk);
        $this->member($group, ['first_name' => 'Paolo', 'last_name' => 'Lim']);

        $this->actingAs($desk)->get('/records')->assertOk()
            ->assertSee('Paolo Lim')
            ->assertSee("With Maria Santos's group")
            ->assertDontSee('the group pays')
            ->assertSee('Waiting for payment')
            // The desk can settle the party from the member's row.
            ->assertSee(route('desk.groups.paid', $group), false);
    }

    private function headcountOnDesk(Staff $desk): int
    {
        return $this->actingAs($desk)->get('/desk')->viewData('todayHeads');
    }
}
