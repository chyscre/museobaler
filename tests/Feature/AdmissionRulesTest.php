<?php

namespace Tests\Feature;

use App\Models\AdmissionDiscount;
use App\Models\MuseumHall;
use App\Models\MuseumInfo;
use App\Models\Staff;
use App\Models\Visitor;
use App\Models\VisitGroup;
use App\Support\Admission;
use App\Support\MailDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Who enters free, and who pays less, is set on the Museum Info page.
 *
 * It used to be one rule written into the code - Baler residents free,
 * everyone else the flat fee - and the museum expects to change it: free for
 * every Aurora resident, free or discounted for senior citizens, PWDs,
 * young children. Each of those is now a setting an administrator changes,
 * and the desk, the visitor API and the About line all follow it.
 */
class AdmissionRulesTest extends TestCase
{
    use RefreshDatabase;

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private Staff $admin;

    protected function setUp(): void
    {
        parent::setUp();
        MailDomain::fakeResolver(fn () => true);
        $this->admin = Staff::factory()->administrator()->create();
    }

    protected function tearDown(): void
    {
        MailDomain::fakeResolver(null);
        parent::tearDown();
    }

    private function staff()
    {
        return $this->withHeader('User-Agent', self::LAPTOP)->actingAs($this->admin);
    }

    /** Save the Museum Info page with these admission settings. */
    private function saveRules(array $discounts, string $scope = 'baler', float $fee = 50)
    {
        return $this->staff()->post('/museum', [
            'name'           => 'Museo de Baler',
            'admission_fee'  => $fee,
            'resident_scope' => $scope,
            'discounts'      => json_encode($discounts),
        ]);
    }

    private function senior(array $overrides = []): array
    {
        return $overrides + ['name' => 'Senior citizen', 'proof' => 'Senior citizen ID', 'percent_off' => 20, 'min_age' => 60, 'max_age' => '', 'active' => true];
    }

    private function child(array $overrides = []): array
    {
        return $overrides + ['name' => 'Child', 'proof' => 'Birth certificate', 'percent_off' => 100, 'min_age' => '', 'max_age' => 6, 'active' => true];
    }

    private function discount(string $name): AdmissionDiscount
    {
        return AdmissionDiscount::where('name', $name)->firstOrFail();
    }

    private function signUp(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Maria', 'last_name' => 'Santos', 'age' => 34, 'sex' => 'Female',
            'visit_type' => 'Solo', 'visitor_type' => 'Tourist',
            'city' => 'Quezon City', 'province' => 'Metro Manila', 'country' => 'Philippines',
            'email' => 'maria@example.org',
            'password' => 'Correct-horse-battery-7', 'password_confirmation' => 'Correct-horse-battery-7',
        ];
    }

    // -- The settings page ------------------------------------------------------

    public function test_an_administrator_sets_the_discounts_and_who_counts_as_local(): void
    {
        $this->saveRules([$this->senior(), $this->child()], 'aurora', 80)
            ->assertRedirect(route('museum.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('aurora', Admission::scope());
        $this->assertSame(['Senior citizen', 'Child'], Admission::discounts()->pluck('name')->all());
        $this->assertSame(
            'Aurora residents enter free with a valid ID · Senior citizen (60 and over): 20% off · Child (6 and under): Free · Visitors ₱80.00',
            MuseumInfo::first()->admission
        );

        $this->staff()->get('/museum')->assertOk()
            ->assertSee('Senior citizen ID')
            ->assertSee('All of Aurora');
    }

    public function test_a_paused_discount_is_kept_but_cannot_be_claimed(): void
    {
        $this->saveRules([$this->senior(['active' => false])]);

        $this->assertDatabaseHas('admission_discounts', ['name' => 'Senior citizen', 'active' => false]);
        $this->assertTrue(Admission::discounts()->isEmpty());
        $this->assertSame('Baler residents enter free with a valid ID · Visitors ₱50.00', MuseumInfo::first()->admission);
    }

    public function test_a_discount_taken_off_the_page_is_removed_and_its_visitors_keep_what_they_paid(): void
    {
        $this->saveRules([$this->senior()]);
        $this->staff()->post('/desk/visitors', [
            'first_name' => 'Lola', 'last_name' => 'Cruz', 'visitor_type' => 'Tourist', 'age' => 70,
            'discount_id' => $this->discount('Senior citizen')->discount_id,
        ]);

        $this->saveRules([]);

        $this->assertDatabaseCount('admission_discounts', 0);
        $this->assertDatabaseHas('visitors', [
            'first_name' => 'Lola', 'discount_id' => null,
            'discount_name' => 'Senior citizen', 'discount_percent' => 20, 'admission_fee' => 40,
        ]);
    }

    public function test_a_bad_discount_row_is_refused_by_name_and_nothing_is_saved(): void
    {
        // The defaults (senior, PWD, child) are there from the migration.
        $before = \App\Models\AdmissionDiscount::count();

        $this->saveRules([$this->senior(), $this->child(['percent_off' => 0])], 'aurora')
            ->assertSessionHasErrors(['discounts' => 'Child: Say how much comes off, from 1% to 100% (free).']);

        $this->assertDatabaseCount('admission_discounts', $before);
        $this->assertSame('baler', Admission::scope());

        $this->saveRules([$this->senior(['min_age' => 70, 'max_age' => 60])])
            ->assertSessionHasErrors('discounts');

        $this->saveRules([$this->senior(), $this->senior(['name' => 'senior citizen '])])
            ->assertSessionHasErrors(['discounts' => 'senior citizen is listed twice.']);
    }

    /**
     * The Save button used to submit the form without running the script
     * that writes the hall list into it, and an empty list read as "delete
     * every hall". A save that did not send a list must leave them alone.
     */
    public function test_a_save_that_did_not_send_the_lists_keeps_the_halls_and_discounts(): void
    {
        $hall = MuseumHall::create(['name' => 'Hall A', 'floor' => MuseumHall::FLOORS[0], 'sort_order' => 1]);
        $this->saveRules([$this->senior()]);

        $this->staff()->post('/museum', ['name' => 'Museo de Baler', 'admission_fee' => 50, 'halls' => '', 'discounts' => ''])
            ->assertSessionHasNoErrors();

        $this->assertModelExists($hall);
        $this->assertSame(1, AdmissionDiscount::count());
    }

    public function test_the_visitor_app_is_told_the_rules(): void
    {
        $this->saveRules([$this->senior()], 'aurora');

        $rules = $this->getJson('/api/v1/museum')->assertOk()->json('info.admission_rules');

        $this->assertSame('aurora', $rules['resident_scope']);
        $this->assertContains('Casiguran', $rules['towns']);
        $this->assertSame('Senior citizen', $rules['discounts'][0]['name']);
        $this->assertSame(20, $rules['discounts'][0]['percent_off']);
        $this->assertSame('60 and over', $rules['discounts'][0]['age_range']);
    }

    // -- One visitor at the desk -------------------------------------------------

    public function test_a_discount_takes_its_share_off_and_the_desk_checks_the_id_when_collecting(): void
    {
        $this->saveRules([$this->senior()]);

        $this->staff()->post('/desk/visitors', [
            'first_name' => 'Lola', 'last_name' => 'Cruz', 'visitor_type' => 'Tourist', 'age' => 68,
            'discount_id' => $this->discount('Senior citizen')->discount_id,
        ])->assertSessionHas('success', 'Lola Cruz registered. Collect PHP 40.00 (Senior citizen) - check their Senior citizen ID.');

        $visitor = Visitor::where('first_name', 'Lola')->firstOrFail();
        $this->assertSame('40.00', $visitor->admission_fee);
        $this->assertSame('Unpaid', $visitor->payment_status);
        $this->assertSame('pending_payment', $visitor->clearance());

        $this->staff()->post(route('visitors.mark-paid', $visitor))->assertSessionHas('success');
        $this->assertSame('cleared', $visitor->fresh()->clearance());
    }

    public function test_a_free_discount_waits_for_an_id_check_like_a_local(): void
    {
        $this->saveRules([$this->child()]);

        $this->staff()->post('/desk/visitors', [
            'first_name' => 'Tonton', 'last_name' => 'Reyes', 'visitor_type' => 'Tourist', 'age' => 5,
            'discount_id' => $this->discount('Child')->discount_id,
        ])->assertSessionHas('success', 'Tonton Reyes registered. Child - check their Birth certificate, no fee.');

        $visitor = Visitor::where('first_name', 'Tonton')->firstOrFail();
        $this->assertSame('Free', $visitor->payment_status);
        $this->assertSame('pending_id', $visitor->clearance());

        // There is nothing to collect, so the only way in is the ID check.
        $this->staff()->post(route('visitors.mark-paid', $visitor))->assertSessionHas('error');
        $this->staff()->post(route('visitors.verify-id', $visitor))
            ->assertSessionHas('success', 'Child ID verified — free admission granted.');
        $this->assertSame('cleared', $visitor->fresh()->clearance());
    }

    public function test_a_claim_that_does_not_fit_the_age_range_is_refused(): void
    {
        $this->saveRules([$this->senior()]);
        $id = $this->discount('Senior citizen')->discount_id;

        $this->staff()->post('/desk/visitors', [
            'first_name' => 'Young', 'last_name' => 'Cruz', 'visitor_type' => 'Tourist', 'age' => 30, 'discount_id' => $id,
        ])->assertSessionHasErrors(['discount_id' => 'Senior citizen is for ages 60 and over.']);

        $this->staff()->post('/desk/visitors', [
            'first_name' => 'Ageless', 'last_name' => 'Cruz', 'visitor_type' => 'Tourist', 'discount_id' => $id,
        ])->assertSessionHasErrors(['discount_id' => "Senior citizen is for ages 60 and over. Enter the visitor's age."]);

        $this->assertDatabaseMissing('visitors', ['last_name' => 'Cruz']);
    }

    public function test_a_paused_discount_cannot_be_claimed_at_the_desk(): void
    {
        $this->saveRules([$this->senior(['active' => false])]);

        $this->staff()->post('/desk/visitors', [
            'first_name' => 'Lola', 'last_name' => 'Cruz', 'visitor_type' => 'Tourist', 'age' => 70,
            'discount_id' => $this->discount('Senior citizen')->discount_id,
        ])->assertSessionHasErrors('discount_id');
    }

    public function test_a_local_never_carries_a_discount(): void
    {
        $this->saveRules([$this->senior()]);

        $this->staff()->post('/desk/visitors', [
            'first_name' => 'Lolo', 'last_name' => 'Bautista', 'visitor_type' => 'Local', 'age' => 75,
            'discount_id' => $this->discount('Senior citizen')->discount_id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('visitors', ['first_name' => 'Lolo', 'discount_id' => null, 'admission_fee' => 0]);
    }

    // -- A party at the desk ----------------------------------------------------

    public function test_a_party_pays_full_price_for_some_the_discount_for_others_and_nothing_for_locals(): void
    {
        $this->saveRules([$this->senior(), $this->child()]);

        $this->staff()->post('/desk/groups', [
            'contact_name' => 'Santos family', 'group_type' => 'Family', 'visitor_type' => 'Tourist',
            'headcount' => 6, 'local_count' => 1,
            'discounts' => [
                $this->discount('Senior citizen')->discount_id => 2,
                $this->discount('Child')->discount_id          => 1,
            ],
        ])->assertSessionHasNoErrors();

        $group = VisitGroup::latest('group_id')->firstOrFail();
        // 2 full at 50, 2 seniors at 40, 1 child free, 1 local free.
        $this->assertSame('180.00', $group->total_fee);
        $this->assertSame(4, $group->paying_count);
        $this->assertSame('2 Senior citizen · 1 Child', $group->discount_summary);
        $this->assertStringContainsString('Check 2 × Senior citizen ID. Check 1 × Birth certificate.', session('success'));
    }

    public function test_more_locals_and_discounts_than_people_is_refused(): void
    {
        $this->saveRules([$this->senior()]);

        $this->staff()->post('/desk/groups', [
            'contact_name' => 'Too many', 'group_type' => 'Group', 'visitor_type' => 'Tourist',
            'headcount' => 3, 'local_count' => 2,
            'discounts' => [$this->discount('Senior citizen')->discount_id => 2],
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('visit_groups', 0);
    }

    public function test_a_party_priced_at_nothing_lets_its_members_in_without_a_payment(): void
    {
        $this->saveRules([$this->child()]);

        $this->staff()->post('/desk/groups', [
            'contact_name' => 'Day care', 'group_type' => 'School', 'visitor_type' => 'Tourist',
            'headcount' => 12, 'local_count' => 0,
            'discounts' => [$this->discount('Child')->discount_id => 12],
        ]);

        $group = VisitGroup::latest('group_id')->firstOrFail();
        $this->assertSame('Free', $group->payment_status);
        $this->assertTrue($group->clearsMembers());
    }

    public function test_correcting_the_locals_keeps_the_discounts_and_their_price(): void
    {
        $this->saveRules([$this->senior()]);
        $this->staff()->post('/desk/groups', [
            'contact_name' => 'Cruz party', 'group_type' => 'Group', 'visitor_type' => 'Tourist',
            'headcount' => 4, 'local_count' => 0,
            'discounts' => [$this->discount('Senior citizen')->discount_id => 1],
        ]);
        $group = VisitGroup::latest('group_id')->firstOrFail();
        $this->assertSame('190.00', $group->total_fee);

        // The museum changes the discount afterwards; the party keeps its own.
        $this->saveRules([$this->senior(['id' => $this->discount('Senior citizen')->discount_id, 'percent_off' => 50])]);

        $this->staff()->post(route('desk.groups.correct', $group), ['local_count' => 1])->assertSessionHas('success');
        $this->assertSame('140.00', $group->fresh()->total_fee);

        // Three locals plus the senior would be five of four.
        $this->staff()->post(route('desk.groups.correct', $group), ['local_count' => 4])->assertSessionHas('error');
    }

    // -- Residents of all Aurora ----------------------------------------------------

    public function test_under_the_aurora_rule_a_local_names_a_town(): void
    {
        $this->saveRules([], 'aurora');

        $this->staff()->post('/desk/visitors', [
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'visitor_type' => 'Local', 'city' => 'Casiguran',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('visitors', [
            'first_name' => 'Juan', 'city' => 'Casiguran', 'province' => 'Aurora', 'barangay' => null, 'admission_fee' => 0,
        ]);

        $this->staff()->get('/desk')->assertOk()
            ->assertSee('How many are from Aurora?')
            ->assertSee('Maria Aurora');
    }

    public function test_the_app_asks_a_local_for_a_town_or_a_barangay_by_the_rule_in_force(): void
    {
        $this->saveRules([], 'aurora');

        $this->postJson('/api/v1/visitors', $this->signUp(['visitor_type' => 'Local', 'city' => 'Manila']))
            ->assertStatus(422)->assertJson(['field' => 'city', 'message' => 'Please select your town.']);

        $this->postJson('/api/v1/visitors', $this->signUp(['visitor_type' => 'Local', 'city' => 'Dipaculao']))
            ->assertCreated()->assertJson(['city' => 'Dipaculao', 'admission_fee' => 0, 'clearance' => 'pending_id']);

        $this->saveRules([], 'baler');

        $this->postJson('/api/v1/visitors', $this->signUp(['visitor_type' => 'Local', 'city' => 'Dipaculao', 'email' => 'b@example.org']))
            ->assertStatus(422)->assertJson(['field' => 'barangay']);
    }

    // -- The visitor app ------------------------------------------------------------

    public function test_the_app_prices_a_claimed_discount_on_the_server(): void
    {
        $this->saveRules([$this->senior()]);
        $id = $this->discount('Senior citizen')->discount_id;

        $this->postJson('/api/v1/visitors', $this->signUp(['age' => 40, 'discount_id' => $id]))
            ->assertStatus(422)->assertJson(['field' => 'discount_id', 'message' => 'Senior citizen is for ages 60 and over.']);

        $this->postJson('/api/v1/visitors', $this->signUp(['age' => 66, 'discount_id' => $id]))
            ->assertCreated()
            ->assertJson([
                'admission_fee'  => 40,
                'payment_status' => 'Unpaid',
                'discount'       => ['name' => 'Senior citizen', 'percent_off' => 20, 'proof' => 'Senior citizen ID'],
            ]);
    }

    public function test_a_returning_visitor_is_priced_at_todays_discount(): void
    {
        $this->saveRules([$this->senior()]);
        $id = $this->discount('Senior citizen')->discount_id;
        $this->postJson('/api/v1/visitors', $this->signUp(['age' => 66, 'discount_id' => $id]))->assertCreated();
        // As if the emailed code had been typed back.
        Visitor::where('email', 'maria@example.org')->firstOrFail()->markEmailVerified();

        $this->saveRules([$this->senior(['id' => $id, 'percent_off' => 100])]);
        Carbon::setTestNow(now()->addDay());

        $this->postJson('/api/v1/visitors/login', ['email' => 'maria@example.org', 'password' => 'Correct-horse-battery-7'])
            ->assertOk()->assertJson(['admission_fee' => 0, 'payment_status' => 'Free', 'clearance' => 'pending_id']);

        // Withdrawn altogether: back to the full fee.
        $this->saveRules([]);
        Carbon::setTestNow(now()->addDay());

        $this->postJson('/api/v1/visitors/login', ['email' => 'maria@example.org', 'password' => 'Correct-horse-battery-7'])
            ->assertOk()->assertJson(['admission_fee' => 50, 'payment_status' => 'Unpaid', 'discount' => null]);

        Carbon::setTestNow();
    }
}
