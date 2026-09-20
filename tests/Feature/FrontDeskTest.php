<?php

namespace Tests\Feature;

use App\Models\MuseumInfo;
use App\Models\Staff;
use App\Models\Visitor;
use App\Models\VisitGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The front desk is what replaces the paper logbook, so these cover the
 * things that decide whether it actually gets used: a party of five is one
 * entry rather than five, the fee shown matches what is owed, and only the
 * people who actually stand at the entrance can take admission money.
 */
class FrontDeskTest extends TestCase
{
    use RefreshDatabase;

    public function test_desk_staff_can_register_a_walk_in(): void
    {
        $desk = Staff::factory()->administrator()->create();

        $this->actingAs($desk)->post('/desk/visitors', [
            'first_name' => 'Ramon', 'last_name' => 'Cruz', 'visitor_type' => 'Tourist',
            'city' => 'Quezon City',
        ])->assertRedirect();

        $this->assertDatabaseHas('visitors', [
            'first_name'     => 'Ramon',
            'source'         => 'desk',
            'registered_by'  => $desk->staff_id,
            'payment_status' => 'Unpaid',
        ]);
    }

    public function test_a_local_owes_nothing_but_starts_unverified(): void
    {
        $desk = Staff::factory()->administrator()->create();

        $this->actingAs($desk)->post('/desk/visitors', [
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'visitor_type' => 'Local',
        ]);

        $visitor = Visitor::firstWhere('first_name', 'Ana');

        $this->assertSame('Free', $visitor->payment_status);
        $this->assertEquals(0, (float) $visitor->admission_fee);
        $this->assertFalse($visitor->id_verified);
    }

    public function test_a_local_is_a_baler_resident_and_names_a_barangay(): void
    {
        $desk = Staff::factory()->administrator()->create();

        // Free admission is for Baler only, so the town is not the desk's to
        // type: whatever was sent, a local lives in Baler, Aurora.
        $this->actingAs($desk)->post('/desk/visitors', [
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'visitor_type' => 'Local',
            'barangay' => 'Sabang', 'city' => 'Casiguran',
        ]);

        $ana = Visitor::firstWhere('first_name', 'Ana');
        $this->assertSame('Sabang', $ana->barangay);
        $this->assertSame('Baler', $ana->city);
        $this->assertSame('Sabang, Baler', $ana->location);

        // A made-up barangay is dropped rather than stored; a tourist never has one.
        $this->actingAs($desk)->post('/desk/visitors', [
            'first_name' => 'Ben', 'last_name' => 'Reyes', 'visitor_type' => 'Local', 'barangay' => 'Nowhere',
        ]);
        $this->actingAs($desk)->post('/desk/visitors', [
            'first_name' => 'Cai', 'last_name' => 'Reyes', 'visitor_type' => 'Tourist', 'barangay' => 'Sabang', 'city' => 'Manila',
        ]);
        $this->assertNull(Visitor::firstWhere('first_name', 'Ben')->barangay);
        $this->assertNull(Visitor::firstWhere('first_name', 'Cai')->barangay);
        $this->assertSame('Manila', Visitor::firstWhere('first_name', 'Cai')->location);

        // A tourist reads city and province; a foreign visitor city and country.
        $this->actingAs($desk)->post('/desk/visitors', [
            'first_name' => 'Dee', 'last_name' => 'Reyes', 'visitor_type' => 'Tourist', 'city' => 'Tagaytay', 'province' => 'Cavite',
        ]);
        $this->actingAs($desk)->post('/desk/visitors', [
            'first_name' => 'Eli', 'last_name' => 'Sato', 'visitor_type' => 'Foreign', 'city' => 'Osaka', 'country' => 'Japan',
        ]);
        $this->assertSame('Tagaytay, Cavite', Visitor::firstWhere('first_name', 'Dee')->location);
        $this->assertSame('Osaka, Japan', Visitor::firstWhere('first_name', 'Eli')->location);
    }

    public function test_a_party_of_five_is_one_entry_with_one_fee(): void
    {
        $desk = Staff::factory()->administrator()->create();

        $this->actingAs($desk)->post('/desk/groups', [
            'contact_name' => 'Jun Molina', 'group_type' => 'Group',
            'visitor_type' => 'Tourist', 'headcount' => 5,
        ])->assertRedirect();

        $group = VisitGroup::first();

        $this->assertSame(5, $group->headcount);
        $this->assertSame(5, $group->paying_count);
        $this->assertEquals(5 * MuseumInfo::admissionFee(), (float) $group->total_fee);
        $this->assertSame('Unpaid', $group->payment_status);

        // One row, not five: this is the whole reason the desk keeps up with
        // a bus rather than falling back to the paper book.
        $this->assertDatabaseCount('visit_groups', 1);
    }

    public function test_a_mixed_party_only_pays_for_its_non_local_members(): void
    {
        $desk = Staff::factory()->administrator()->create();

        $this->actingAs($desk)->post('/desk/groups', [
            'contact_name' => 'Santos family', 'group_type' => 'Family',
            'visitor_type' => 'Tourist', 'headcount' => 6, 'paying_count' => 4,
        ]);

        $this->assertEquals(4 * MuseumInfo::admissionFee(), (float) VisitGroup::first()->total_fee);
    }

    public function test_paying_more_heads_than_the_party_has_is_rejected(): void
    {
        $desk = Staff::factory()->administrator()->create();

        $this->actingAs($desk)->post('/desk/groups', [
            'contact_name' => 'Typo', 'group_type' => 'Group',
            'visitor_type' => 'Tourist', 'headcount' => 3, 'paying_count' => 30,
        ]);

        $this->assertDatabaseCount('visit_groups', 0);
    }

    public function test_a_local_group_is_free(): void
    {
        $desk = Staff::factory()->administrator()->create();

        $this->actingAs($desk)->post('/desk/groups', [
            'contact_name' => 'Baler NHS', 'group_type' => 'School',
            'visitor_type' => 'Local', 'headcount' => 40,
        ]);

        $group = VisitGroup::first();

        $this->assertSame(0, $group->paying_count);
        $this->assertSame('Free', $group->payment_status);
    }

    public function test_every_museum_staff_account_can_cover_the_desk(): void
    {
        // The museum runs on a handful of people who all rotate onto the
        // entrance desk, so no museum account is excluded.
        $this->actingAs(Staff::factory()->administrator()->create())
            ->get('/desk')->assertOk();
    }

    public function test_the_tourism_office_is_kept_off_the_desk(): void
    {
        // She works from the municipal office and never stands at the
        // entrance, so the till and the money never touch her account.
        $tourism = Staff::factory()->tourismHead()->create();

        $this->actingAs($tourism)->get('/desk')->assertForbidden();

        $this->actingAs($tourism)->post('/desk/visitors', [
            'first_name' => 'Ramon', 'last_name' => 'Cruz', 'visitor_type' => 'Tourist',
        ])->assertForbidden();

        $this->assertDatabaseCount('visitors', 0);
    }

    public function test_a_signed_out_visitor_cannot_reach_the_desk(): void
    {
        $this->get('/desk')->assertRedirect('/login');
        $this->post('/desk/visitors', [
            'first_name' => 'Nobody', 'last_name' => 'Here', 'visitor_type' => 'Tourist',
        ])->assertRedirect('/login');

        $this->assertDatabaseCount('visitors', 0);
    }
}
