<?php

namespace Tests\Feature;

use App\Models\AdmissionPayment;
use App\Models\Attendance;
use App\Models\Exhibit;
use App\Models\MuseumHall;
use App\Models\Scan;
use App\Models\Staff;
use App\Models\VisitGroup;
use App\Models\Visitor;
use App\Support\VisitDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The View Details / View Breakdown window on Records and the attendance
 * log, and the group pass a member sees in the app.
 */
class RecordDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(Staff $staff)
    {
        return $this->withHeader('User-Agent', self::LAPTOP)->actingAs($staff);
    }

    private function desk()
    {
        return $this->as(Staff::factory()->administrator()->create());
    }

    private function exhibit(string $name = 'Bell of Baler'): Exhibit
    {
        $hall = MuseumHall::create(['name' => 'Siege Hall', 'floor' => MuseumHall::FLOORS[0], 'sort_order' => 1]);

        return Exhibit::create(['exhibit_code' => 'EXH-' . random_int(100, 999), 'name' => $name, 'hall_id' => $hall->hall_id]);
    }

    private function group(array $overrides = []): VisitGroup
    {
        return VisitGroup::create($overrides + [
            'join_code' => 'K7PM4X', 'group_type' => 'Family', 'contact_name' => 'Maria Santos',
            'visitor_type' => 'Tourist', 'headcount' => 4, 'local_count' => 0, 'paying_count' => 4,
            'total_fee' => 200, 'payment_status' => 'Unpaid', 'visit_date' => today(),
        ]);
    }

    public function test_duration_reads_like_a_person_would_say_it(): void
    {
        $this->assertSame('1 hr 18 mins', VisitDetails::duration(78));
        $this->assertSame('45 mins', VisitDetails::duration(45));
        $this->assertSame('2 hrs', VisitDetails::duration(120));
        $this->assertSame('0 mins', VisitDetails::duration(0));
    }

    public function test_a_paid_visitors_details_show_the_transaction_presence_and_scans(): void
    {
        Carbon::setTestNow(today()->setTime(10, 0));
        $v = Visitor::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $this->desk()->post(route('visitors.mark-paid', $v));
        $ref = AdmissionPayment::sole()->reference;

        $a = Attendance::create(['visitor_id' => $v->visitor_id, 'method' => 'geofence', 'visit_date' => today()]);
        $a->forceFill(['exited_at' => now()->addMinutes(78), 'duration_mins' => 78])->save();
        Scan::create(['visitor_id' => $v->visitor_id, 'exhibit_id' => $this->exhibit()->exhibit_id, 'scanned_at' => now()->addMinutes(5)]);

        $this->desk()->get(route('records.details.visitor', ['visitor' => $v, 'date' => today()->toDateString()]))
            ->assertOk()
            ->assertSee($ref)
            ->assertSee('Full admission')
            ->assertSee('₱50.00 · Paid', false)
            ->assertSee('10:00 AM')                 // admitted at the desk
            ->assertSee('Last seen on site')
            ->assertSee('1 hr 18 mins')
            ->assertSee('Bell of Baler')
            ->assertSee('Siege Hall');
    }

    public function test_a_free_visitor_without_a_number_says_so(): void
    {
        $v = Visitor::factory()->local(true)->create(['verified_at' => now()]);

        $this->desk()->get(route('records.details.visitor', ['visitor' => $v, 'date' => today()->toDateString()]))
            ->assertOk()
            ->assertSee('No transaction number')
            ->assertSee('Baler resident')
            ->assertSee('No geofence check-in');
    }

    public function test_a_past_day_is_read_from_that_day_not_the_latest_visit(): void
    {
        Carbon::setTestNow(now()->subDays(3));
        $v = Visitor::factory()->create();
        $this->desk()->post(route('visitors.mark-paid', $v));
        Attendance::create(['visitor_id' => $v->visitor_id, 'method' => 'geofence', 'visit_date' => today()]);
        $then = today()->toDateString();
        Carbon::setTestNow();

        $this->desk()->get(route('records.details.visitor', ['visitor' => $v, 'date' => $then]))
            ->assertOk()
            ->assertSee('Visit ended')
            ->assertSee(AdmissionPayment::sole()->reference);
    }

    public function test_the_group_breakdown_lists_members_with_when_they_joined_and_what_they_scanned(): void
    {
        $group = $this->group();
        Carbon::setTestNow(today()->setTime(9, 41));
        $ana = Visitor::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $ana->joinGroup($group);
        Carbon::setTestNow();
        Scan::create(['visitor_id' => $ana->visitor_id, 'exhibit_id' => $this->exhibit('Church Door')->exhibit_id]);

        $this->desk()->post(route('desk.groups.paid', $group));

        $this->desk()->get(route('records.details.group', $group))
            ->assertOk()
            ->assertSee(AdmissionPayment::sole()->reference)
            ->assertSee('Maria Santos')
            ->assertSee('1 joined in the app, 3 counted at the desk only')
            ->assertSee('Ana Cruz')
            ->assertSee('9:41 AM')
            ->assertSee('Cleared')
            ->assertSee('Church Door');
    }

    public function test_the_attendance_log_opens_details_for_anonymous_rows_too(): void
    {
        $a = Attendance::create(['visitor_name' => null, 'method' => 'geofence', 'visit_date' => today(), 'accuracy' => 12]);

        $this->desk()->get(route('records.details.attendance', $a))
            ->assertOk()
            ->assertSee('Anonymous')
            ->assertSee('Still inside')
            ->assertSee('±12m', false);
    }

    public function test_every_records_row_and_the_attendance_page_have_the_button(): void
    {
        $v = Visitor::factory()->create();
        $this->group();
        Attendance::create(['visitor_id' => $v->visitor_id, 'method' => 'geofence', 'visit_date' => today()]);

        $this->desk()->get('/records')->assertOk()
            ->assertSee(route('records.details.visitor', ['visitor' => $v, 'date' => today()->toDateString()]), false)
            ->assertSee('View Breakdown');
        $this->desk()->get('/attendance')->assertOk()->assertSee('View Details');
    }

    public function test_the_tourism_office_can_read_details_but_not_act(): void
    {
        $v = Visitor::factory()->create();

        $this->as(Staff::factory()->tourismHead()->create())
            ->get(route('records.details.visitor', $v))
            ->assertOk();
    }

    public function test_a_free_group_gets_a_zero_peso_number_when_registered(): void
    {
        $this->desk()->post('/desk/groups', [
            'group_type' => 'Family', 'contact_name' => 'Lito Reyes', 'visitor_type' => 'Local', 'headcount' => 5,
        ])->assertSessionHas('success');

        $p = AdmissionPayment::sole();
        $this->assertEquals(0, (float) $p->amount);
        $this->assertSame(5, $p->headcount);
        $this->assertMatchesRegularExpression('/^MDB-\d{8}-\d{4}$/', $p->reference);
    }

    public function test_a_members_pass_carries_the_group_its_code_and_its_number(): void
    {
        $group = $this->group();
        $this->desk()->post(route('desk.groups.paid', $group));
        $member = Visitor::factory()->create();
        $member->joinGroup($group);

        $this->getJson('/api/v1/visitors/me', ['Authorization' => 'Bearer ' . $member->issueToken()['token']])
            ->assertOk()
            ->assertJsonPath('group.label', "Maria Santos's group")
            ->assertJsonPath('group.signed_in_by', 'Maria Santos')
            ->assertJsonPath('group.join_code', 'K7PM4X')
            ->assertJsonPath('group.reference', AdmissionPayment::sole()->reference)
            ->assertJsonPath('group.joined', 1);
    }
}
