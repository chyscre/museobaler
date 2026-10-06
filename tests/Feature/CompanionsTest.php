<?php

namespace Tests\Feature;

use App\Models\AdmissionDiscount;
use App\Models\AdmissionPayment;
use App\Models\MuseumInfo;
use App\Models\Staff;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitGroup;
use App\Support\Reports\Earnings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Free companions: the seniors, PWDs and small children a visitor brings
 * along, counted on that visitor's own entry rather than as a party.
 */
class CompanionsTest extends TestCase
{
    use RefreshDatabase;

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private function desk()
    {
        return $this->withHeader('User-Agent', self::LAPTOP)
            ->actingAs(Staff::factory()->administrator()->create());
    }

    private function bearer(Visitor $v): array
    {
        return ['Authorization' => 'Bearer ' . $v->issueToken()['token']];
    }

    private function id(string $name): int
    {
        return (int) AdmissionDiscount::where('name', $name)->value('discount_id');
    }

    private function declare(Visitor $v, array $counts)
    {
        return $this->putJson('/api/v1/visitors/me/companions', ['companions' => $counts], $this->bearer($v));
    }

    public function test_a_visitor_declares_companions_and_each_is_counted_as_a_visitor(): void
    {
        $holder = Visitor::factory()->create(['city' => 'Cabanatuan', 'province' => 'Nueva Ecija']);

        $this->declare($holder, [$this->id('Senior citizen') => 1, $this->id('Child') => 2])
            ->assertOk()
            ->assertJsonPath('headcount', 4)
            ->assertJsonPath('companions.0.name', 'Senior citizen')
            ->assertJsonPath('admission.reference', null)
            ->assertJsonPath('admission.total', 50)
            ->assertJsonCount(3, 'admission.lines');

        $rows = Visitor::where('companion_of', $holder->visitor_id)->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $c) {
            $this->assertSame('express', $c->source);
            $this->assertSame('Tourist', $c->visitor_type);
            $this->assertSame('Cabanatuan', $c->city);
            $this->assertEquals(0, (float) $c->admission_fee);
            $this->assertFalse($c->id_verified);
        }

        // The holder's visit carries the party size and the breakdown.
        $visit = Visit::where('visitor_id', $holder->visitor_id)->firstOrFail();
        $this->assertSame(4, $visit->headcount);
        $this->assertSame(2, collect($visit->companions)->firstWhere('name', 'Child')['count']);
    }

    public function test_declaring_again_replaces_the_list_rather_than_adding_to_it(): void
    {
        $holder = Visitor::factory()->create();

        $this->declare($holder, [$this->id('Child') => 3])->assertOk();
        $this->declare($holder, [$this->id('Child') => 1])->assertOk()->assertJsonPath('headcount', 2);

        $this->assertSame(1, Visitor::where('companion_of', $holder->visitor_id)->count());
        // No orphaned visit rows from the companions that were taken off.
        $this->assertSame(0, Visit::whereNull('visitor_id')->count());
    }

    public function test_only_free_categories_can_be_brought_along(): void
    {
        $half = AdmissionDiscount::create(['name' => 'Student', 'percent_off' => 20, 'active' => true, 'sort_order' => 9]);
        $holder = Visitor::factory()->create();

        $this->declare($holder, [$half->discount_id => 2])->assertOk()->assertJsonPath('headcount', 1);
        $this->assertSame(0, Visitor::where('companion_of', $holder->visitor_id)->count());
    }

    public function test_there_is_a_cap_on_how_many_one_visitor_brings(): void
    {
        $holder = Visitor::factory()->create();

        $this->declare($holder, [$this->id('Child') => 6, $this->id('Senior citizen') => 6])
            ->assertStatus(422)->assertJsonPath('error', 'too_many_companions');
    }

    /** The details form, as the visitor app sends it. */
    private function signUp(array $companions, array $overrides = [])
    {
        \Illuminate\Support\Facades\Mail::fake();
        \App\Support\MailDomain::fakeResolver(fn () => true);

        try {
            return $this->postJson('/api/v1/visitors', $overrides + [
                'first_name' => 'Maria', 'last_name' => 'Santos', 'age' => 34, 'sex' => 'Female',
                'visit_type' => 'Walk-in', 'visitor_type' => 'Tourist',
                'city' => 'Quezon City', 'province' => 'Metro Manila', 'country' => 'Philippines',
                'email' => 'maria@example.org',
                'password' => 'Correct-horse-battery-7', 'password_confirmation' => 'Correct-horse-battery-7',
                'companions' => $companions,
            ]);
        } finally {
            \App\Support\MailDomain::fakeResolver(null);
        }
    }

    public function test_companions_picked_on_the_sign_up_form_are_counted_unchecked(): void
    {
        // Before the emailed code is typed, so there is no session yet: the
        // counts have to ride on the registration itself.
        $this->signUp([$this->id('Senior citizen') => 1, $this->id('Child') => 2])
            ->assertCreated()
            ->assertJsonPath('headcount', 4)
            ->assertJsonMissingPath('token');

        $holder = Visitor::where('email', 'maria@example.org')->firstOrFail();
        $rows = Visitor::where('companion_of', $holder->visitor_id)->get();
        $this->assertCount(3, $rows);
        $this->assertTrue($rows->every(fn ($c) => !$c->id_verified && (float) $c->admission_fee === 0.0));
    }

    public function test_the_sign_up_form_keeps_to_the_same_rules_as_the_waiting_screen(): void
    {
        $this->signUp([$this->id('Child') => 6, $this->id('Senior citizen') => 6])
            ->assertStatus(422)->assertJsonPath('error', 'too_many_companions');
        $this->assertSame(0, Visitor::count());

        // Only free categories; anything else is dropped, as on the waiting screen.
        $half = AdmissionDiscount::create(['name' => 'Student', 'percent_off' => 20, 'active' => true, 'sort_order' => 9]);
        $this->signUp([$half->discount_id => 2])->assertCreated()->assertJsonPath('headcount', 1);
    }

    public function test_mark_paid_checks_the_companions_and_puts_them_on_the_receipt(): void
    {
        $holder = Visitor::factory()->create();
        $this->declare($holder, [$this->id('Senior citizen') => 1, $this->id('PWD') => 1])->assertOk();

        $this->desk()->post(route('visitors.mark-paid', $holder))->assertSessionHas('success');

        $holder->refresh();
        $this->assertSame('cleared', $holder->clearance());
        $this->assertSame(0, Visitor::where('companion_of', $holder->visitor_id)->where('id_verified', false)->count());

        $p = AdmissionPayment::sole();
        $this->assertMatchesRegularExpression('/^MDB-\d{8}-\d{4}$/', $p->reference);
        $this->assertEquals(MuseumInfo::admissionFee(), (float) $p->amount);
        $this->assertSame(3, $p->headcount);
        $this->assertCount(3, $p->visitor_ids);
        $free = collect($p->breakdown['lines'])->where('unit', 0);
        $this->assertSame(['Senior citizen (free)', 'PWD (free)'], $free->pluck('label')->values()->all());

        // And the app's receipt is the ledger entry.
        $this->getJson('/api/v1/visitors/me', $this->bearer($holder))
            ->assertJsonPath('admission.reference', $p->reference)
            ->assertJsonPath('cleared', true);
    }

    public function test_a_free_visit_with_companions_gets_a_zero_peso_transaction_number(): void
    {
        $holder = Visitor::factory()->local()->create();
        $this->declare($holder, [$this->id('Child') => 2])->assertOk()->assertJsonPath('clearance', 'pending_id');

        $this->desk()->post(route('visitors.verify-id', $holder))->assertSessionHas('success');

        $this->assertSame('cleared', $holder->fresh()->clearance());
        $p = AdmissionPayment::sole();
        $this->assertEquals(0, (float) $p->amount);
        $this->assertSame(3, $p->headcount);
        $this->assertNotNull($p->reference);

        // Counted as a transaction; adds nothing to the takings.
        $e = Earnings::build(today(), today());
        $this->assertSame(1, $e['totals']['transactions']);
        $this->assertEquals(0, $e['totals']['collected']);
    }

    public function test_a_free_visit_without_companions_still_gets_no_ledger_row(): void
    {
        $holder = Visitor::factory()->local()->create();
        $this->desk()->post(route('visitors.verify-id', $holder));

        $this->assertSame(0, AdmissionPayment::count());
    }

    public function test_once_let_in_the_visitor_cannot_add_companions_from_the_queue(): void
    {
        $holder = Visitor::factory()->paid()->create();

        $this->declare($holder, [$this->id('Senior citizen') => 4])
            ->assertStatus(422)->assertJsonPath('error', 'already_checked');
    }

    public function test_unchecked_companions_hold_the_entry_until_the_desk_sees_them(): void
    {
        // Paid, but with companions added that nobody has checked.
        $holder = Visitor::factory()->paid()->create();
        $holder->setCompanions([$this->id('Child') => 1]);

        $this->assertSame('pending_id', $holder->fresh()->clearance());

        $this->desk()->post(route('visitors.verify-id', $holder))->assertSessionHas('success');
        $this->assertSame('cleared', $holder->fresh()->clearance());
    }

    public function test_companions_stay_out_of_the_desk_queue_on_their_own(): void
    {
        $holder = Visitor::factory()->create();
        $this->declare($holder, [$this->id('Child') => 2])->assertOk();

        $this->assertSame([$holder->visitor_id], Visitor::pendingClearance()->pluck('visitor_id')->all());
    }

    public function test_the_desk_counts_companions_in_checked_when_registering_someone(): void
    {
        $this->desk()->post('/desk/visitors', [
            'first_name' => 'Ana', 'last_name' => 'Cruz', 'visitor_type' => 'Tourist',
            'companions' => [$this->id('Senior citizen') => 2],
        ])->assertRedirect(route('desk.register'))->assertSessionHas('success');

        $ana = Visitor::where('first_name', 'Ana')->firstOrFail();
        $rows = Visitor::where('companion_of', $ana->visitor_id)->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($c) => $c->id_verified));
        $this->assertSame('Senior citizen · with Ana Cruz', $rows->first()->full_name);
    }

    public function test_joining_a_group_drops_declared_companions_so_nobody_is_counted_twice(): void
    {
        $holder = Visitor::factory()->create();
        $this->declare($holder, [$this->id('Child') => 2])->assertOk();

        $group = VisitGroup::create([
            'join_code' => 'K7PM4X', 'group_type' => 'Family', 'contact_name' => 'Maria',
            'visitor_type' => 'Tourist', 'headcount' => 4, 'local_count' => 0, 'paying_count' => 4,
            'total_fee' => 200, 'payment_status' => 'Unpaid', 'visit_date' => today(),
        ]);
        $holder->joinGroup($group);

        $this->assertSame(0, Visitor::where('companion_of', $holder->visitor_id)->count());
    }

    public function test_records_shows_companions_on_the_holder_and_points_companions_back(): void
    {
        $holder = Visitor::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $this->declare($holder, [$this->id('Child') => 2])->assertOk();

        $this->desk()->get('/records')
            ->assertOk()
            ->assertSee('+2 Child · free')
            ->assertSee('Child · with Ana Cruz')
            ->assertSee('On Ana Cruz');
    }

    public function test_revoking_the_entry_unchecks_the_companions_too(): void
    {
        $holder = Visitor::factory()->local()->create();
        $this->declare($holder, [$this->id('Child') => 1])->assertOk();
        $desk = $this->desk();
        $desk->post(route('visitors.verify-id', $holder));

        $desk->post(route('visitors.revoke', $holder))->assertSessionHas('success');

        $this->assertFalse(Visitor::where('companion_of', $holder->visitor_id)->sole()->id_verified);
    }
}
