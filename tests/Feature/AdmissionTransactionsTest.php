<?php

namespace Tests\Feature;

use App\Models\AdmissionDiscount;
use App\Models\AdmissionPayment;
use App\Models\MuseumInfo;
use App\Models\Staff;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Free categories and express entry, transaction numbers, and the visit
 * history that keeps a returning visitor's earlier visits.
 */
class AdmissionTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private float $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fee = MuseumInfo::admissionFee();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function desk(): Staff
    {
        return Staff::factory()->administrator()->create();
    }

    private function category(string $name): AdmissionDiscount
    {
        return AdmissionDiscount::where('name', $name)->firstOrFail();
    }

    // -- Free categories ---------------------------------------------------

    public function test_seniors_pwds_and_young_children_enter_free_out_of_the_box(): void
    {
        $this->assertTrue($this->category('Senior citizen')->isFree());
        $this->assertSame(60, $this->category('Senior citizen')->min_age);
        $this->assertTrue($this->category('PWD')->isFree());
        $this->assertTrue($this->category('Child')->isFree());
        $this->assertSame(7, $this->category('Child')->max_age);

        $this->actingAs($this->desk())->post('/desk/visitors', [
            'first_name' => 'Lola', 'last_name' => 'Reyes', 'visitor_type' => 'Tourist',
            'age' => 72, 'discount_id' => $this->category('Senior citizen')->discount_id,
        ])->assertRedirect();

        $lola = Visitor::where('first_name', 'Lola')->firstOrFail();
        $this->assertEquals(0, (float) $lola->admission_fee);
        $this->assertSame('Free', $lola->payment_status);
    }

    // -- Express entry -----------------------------------------------------

    public function test_express_entry_counts_free_visitors_without_a_name(): void
    {
        $desk  = $this->desk();
        $child = $this->category('Child');

        $this->actingAs($desk)->post('/desk/visitors', [
            'express' => 1, 'express_count' => 3, 'visitor_type' => 'Tourist',
            'discount_id' => $child->discount_id, 'sex' => 'Female', 'city' => 'Cabanatuan',
        ])->assertRedirect(route('desk.register'))->assertSessionHas('success');

        $rows = Visitor::where('source', 'express')->get();
        $this->assertCount(3, $rows);

        foreach ($rows as $v) {
            $this->assertNull($v->first_name);
            $this->assertSame('Express entry · Child', $v->full_name);
            $this->assertSame('Free', $v->payment_status);
            $this->assertSame('Cabanatuan', $v->city);
            $this->assertSame('Female', $v->sex);
            // The desk saw them and their ID: nothing left waiting in Records.
            $this->assertTrue($v->isCleared());
            $this->assertSame(1, $v->visits()->count());
        }

        $this->assertSame(0, Visitor::pendingClearance()->count());
    }

    public function test_express_entry_is_refused_for_a_category_that_is_not_free(): void
    {
        $partial = AdmissionDiscount::create(['name' => 'Student', 'percent_off' => 20, 'active' => true]);

        $this->actingAs($this->desk())->post('/desk/visitors', [
            'express' => 1, 'visitor_type' => 'Tourist', 'discount_id' => $partial->discount_id,
        ])->assertSessionHasErrors('discount_id');

        $this->assertSame(0, Visitor::count());
    }

    public function test_express_entry_checks_an_age_when_one_is_given(): void
    {
        $this->actingAs($this->desk())->post('/desk/visitors', [
            'express' => 1, 'visitor_type' => 'Tourist', 'age' => 12,
            'discount_id' => $this->category('Child')->discount_id,
        ])->assertSessionHasErrors('discount_id');

        $this->assertSame(0, Visitor::count());
    }

    public function test_a_named_registration_still_needs_the_name(): void
    {
        $this->actingAs($this->desk())->post('/desk/visitors', [
            'visitor_type' => 'Tourist', 'discount_id' => $this->category('PWD')->discount_id,
        ])->assertSessionHasErrors(['first_name', 'last_name']);
    }

    // -- Transaction numbers -----------------------------------------------

    public function test_transactions_are_numbered_by_day_and_carry_who_and_what(): void
    {
        $desk = $this->desk();
        Carbon::setTestNow('2026-10-02 09:00');

        $a = Visitor::factory()->create(['visitor_type' => 'Tourist']);
        $b = Visitor::factory()->create(['visitor_type' => 'Foreign']);

        $this->actingAs($desk)->post(route('visitors.mark-paid', $a))
            ->assertSessionHas('success', 'Admission fee marked as paid — transaction MDB-20261002-0001.');
        $this->actingAs($desk)->post(route('visitors.mark-paid', $b));

        $first = AdmissionPayment::where('visitor_id', $a->visitor_id)->firstOrFail();
        $this->assertSame('MDB-20261002-0001', $first->reference);
        $this->assertSame([(int) $a->visitor_id], $first->visitor_ids);
        $this->assertSame(1, (int) $first->headcount);
        $this->assertEquals($this->fee, $first->breakdown['total']);
        $this->assertSame('Full admission', $first->breakdown['lines'][0]['label']);
        $this->assertNotNull($first->visit_id);
        $this->assertSame('MDB-20261002-0002', AdmissionPayment::where('visitor_id', $b->visitor_id)->value('reference'));

        // A new day starts a new sequence.
        Carbon::setTestNow('2026-10-03 09:00');
        $this->actingAs($desk)->post(route('visitors.mark-paid', $a->refresh()));
        $this->assertSame('MDB-20261003-0001', AdmissionPayment::latest('payment_id')->value('reference'));
    }

    public function test_a_group_payment_is_itemised_and_lists_its_joined_members(): void
    {
        $desk   = $this->desk();
        $senior = $this->category('Senior citizen');

        $this->actingAs($desk)->post('/desk/groups', [
            'contact_name' => 'Cruz family', 'group_type' => 'Family', 'visitor_type' => 'Tourist',
            'headcount' => 5, 'local_count' => 1, 'discounts' => [$senior->discount_id => 1],
        ]);
        $group = VisitGroup::latest('group_id')->firstOrFail();

        $member = Visitor::factory()->create(['visitor_type' => 'Tourist']);
        $member->joinGroup($group);

        $this->actingAs($desk)->post(route('desk.groups.paid', $group))->assertSessionHas('success');

        $p = AdmissionPayment::where('group_id', $group->group_id)->firstOrFail();
        $this->assertMatchesRegularExpression('/^MDB-\d{8}-0001$/', $p->reference);
        $this->assertEquals(3 * $this->fee, (float) $p->amount);
        $this->assertSame(5, (int) $p->headcount);
        $this->assertSame([(int) $member->visitor_id], $p->visitor_ids);

        $lines = collect($p->breakdown['lines'])->keyBy('label');
        $this->assertSame(3, $lines['Full admission']['count']);
        $this->assertSame(1, $lines['Senior citizen (free)']['count']);
        $this->assertEquals(0, $lines['Senior citizen (free)']['amount']);
        $this->assertSame(1, $lines['Baler residents']['count']);
        $this->assertEquals((float) $p->amount, array_sum(array_column($p->breakdown['lines'], 'amount')));
    }

    // -- Repeat visits -----------------------------------------------------

    public function test_a_return_visit_adds_to_the_history_without_rewriting_it(): void
    {
        $desk = $this->desk();

        Carbon::setTestNow('2026-09-14 10:00');
        $v = Visitor::factory()->create(['visitor_type' => 'Tourist', 'last_visit' => now()]);
        $this->actingAs($desk)->post(route('visitors.mark-paid', $v));

        // Back a week later as a senior - say the museum has made it 50% off.
        $this->category('Senior citizen')->update(['percent_off' => 50]);
        Carbon::setTestNow('2026-09-21 11:00');
        $v->refresh()->forceFill(['discount_id' => $this->category('Senior citizen')->discount_id])->saveQuietly();
        $v->touchReturning();
        $this->actingAs($desk)->post(route('visitors.mark-paid', $v));

        $visits = Visit::where('visitor_id', $v->visitor_id)->orderBy('visit_date')->get();
        $this->assertCount(2, $visits);

        [$first, $second] = $visits;
        $this->assertSame('2026-09-14', $first->visit_date->toDateString());
        $this->assertSame('Paid', $first->payment_status);
        $this->assertEquals($this->fee, (float) $first->admission_fee);
        $this->assertSame('2026-09-14', $first->paid_at->toDateString());
        $this->assertNull($first->discount_name);
        $this->assertSame('MDB-20260914-0001', $first->payment->reference);

        $this->assertSame('2026-09-21', $second->visit_date->toDateString());
        $this->assertSame('Paid', $second->payment_status);
        $this->assertEquals($this->fee / 2, (float) $second->admission_fee);
        $this->assertSame('Senior citizen', $second->discount_name);
        $this->assertSame('MDB-20260921-0001', $second->payment->reference);
    }

    public function test_a_past_group_member_back_on_their_own_is_not_filed_under_the_group(): void
    {
        $desk = $this->desk();

        Carbon::setTestNow('2026-09-18 10:00');
        $this->actingAs($desk)->post('/desk/groups', [
            'contact_name' => 'Shanti Dope', 'group_type' => 'Group', 'visitor_type' => 'Tourist', 'headcount' => 4,
        ]);
        $group = VisitGroup::latest('group_id')->firstOrFail();
        $v = Visitor::factory()->create(['visitor_type' => 'Tourist', 'visit_type' => 'Solo', 'last_visit' => now()]);
        $v->joinGroup($group);

        // Two weeks later, alone, paying their own way.
        Carbon::setTestNow('2026-10-03 13:00');
        $v->refresh()->touchReturning();
        $this->actingAs($desk)->post(route('visitors.mark-paid', $v))->assertSessionHas('success');

        $visits = Visit::where('visitor_id', $v->visitor_id)->orderBy('visit_date')->get();
        $this->assertCount(2, $visits);
        [$withGroup, $alone] = $visits;

        $this->assertSame($group->group_id, $withGroup->group_id);
        $this->assertSame('Group', $withGroup->visit_type);

        $this->assertNull($alone->group_id);
        $this->assertSame('Walk-in', $alone->visit_type);
        $this->assertSame('Paid', $alone->payment_status);
        $this->assertNull($alone->payment->group_id);

        // Their profile keeps the old party as history, but today says alone.
        $this->assertSame($group->group_id, $v->refresh()->group_id);
        $this->assertSame('Walk-in', $v->visit_type);
    }

    public function test_the_desk_taking_a_fee_from_an_earlier_visitor_starts_todays_visit(): void
    {
        $desk = $this->desk();

        Carbon::setTestNow('2026-09-14 10:00');
        $v = Visitor::factory()->create(['visitor_type' => 'Tourist', 'last_visit' => now()]);
        $this->actingAs($desk)->post(route('visitors.mark-paid', $v));

        // They come back and pay at the counter without signing in to the app.
        Carbon::setTestNow('2026-09-20 10:00');
        $this->actingAs($desk)->post(route('visitors.mark-paid', $v->refresh()))->assertSessionHas('success');

        $this->assertSame(2, Visit::where('visitor_id', $v->visitor_id)->count());
        $this->assertSame('2026-09-14',
            Visit::where('visitor_id', $v->visitor_id)->orderBy('visit_date')->first()->paid_at->toDateString());
    }

    public function test_records_shows_the_history_on_a_returning_visitors_row(): void
    {
        $desk = $this->desk();

        Carbon::setTestNow('2026-09-14 10:00');
        $v = Visitor::factory()->create(['first_name' => 'Ana', 'visitor_type' => 'Tourist', 'last_visit' => now()]);
        $this->actingAs($desk)->post(route('visitors.mark-paid', $v));

        Carbon::setTestNow('2026-09-21 10:00');
        $this->actingAs($desk)->post(route('visitors.mark-paid', $v->refresh()));

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/124.0 Safari/537.36')
            ->actingAs($desk)->get(route('records.index'))
            ->assertOk()
            ->assertSee('2 visits')
            ->assertSee('MDB-20260914-0001')
            ->assertSee('MDB-20260921-0001');
    }
}
