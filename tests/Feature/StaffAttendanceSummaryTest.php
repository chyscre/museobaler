<?php

namespace Tests\Feature;

use App\Models\AttendanceCorrection;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\StaffSchedule;
use App\Support\Reports\StaffAttendanceSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Staff attendance reads as Present and Absent, nothing else.
 *
 * A late check-in is a day present, a rest day is neither, and the bulk
 * summary and the month view have to agree on that - these pin both, plus
 * the date control that replaced the browser's raw "mm/dd/yyyy" face.
 */
class StaffAttendanceSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Staff $ana;

    protected function setUp(): void
    {
        parent::setUp();

        // Friday evening, after the shift: Friday's no-show is an absence.
        Carbon::setTestNow(Carbon::create(2026, 9, 11, 18, 0, 0));

        $this->ana = Staff::factory()->administrator()->create(['name' => 'Ana Cruz']);

        foreach ([1, 2, 3, 4, 5] as $weekday) {
            StaffSchedule::create([
                'staff_id' => $this->ana->staff_id, 'weekday' => $weekday,
                'shift_start' => '08:00', 'shift_end' => '17:00', 'grace_minutes' => 15,
            ]);
        }
        foreach ([0, 6] as $weekday) {
            StaffSchedule::create([
                'staff_id' => $this->ana->staff_id, 'weekday' => $weekday,
                'shift_start' => '00:00', 'shift_end' => '00:00', 'is_rest_day' => true,
            ]);
        }

        $this->checkIn('2026-09-07 08:00');            // on time
        $this->checkIn('2026-09-08 09:00');            // late - still present
        $this->checkIn('2026-09-09 08:00', 'manual');  // hand-entered
        // Thursday and Friday: nobody.

        AttendanceCorrection::create([
            'staff_id' => $this->ana->staff_id, 'work_date' => '2026-09-10', 'type' => 'in',
            'requested_time' => '08:00', 'reason' => 'Phone died',
            'requested_by' => $this->ana->staff_id, 'status' => 'Pending',
        ]);
    }

    private function checkIn(string $at, string $method = 'qr'): void
    {
        $time = Carbon::parse($at);
        StaffAttendance::create([
            'staff_id' => $this->ana->staff_id, 'work_date' => $time->toDateString(),
            'type' => 'in', 'scanned_at' => $time, 'method' => $method,
        ]);
    }

    public function test_late_counts_as_present_and_rest_days_count_as_neither(): void
    {
        // Saturday to the end of the month: the weekend is rest, and the
        // days after today must not turn into absences.
        $s   = StaffAttendanceSummary::build(Carbon::parse('2026-09-05'), Carbon::parse('2026-09-30'));
        $ana = $s['rows']->firstWhere('staff.staff_id', $this->ana->staff_id);

        $this->assertSame(3, $ana['present']);
        $this->assertSame(2, $ana['absent']);
        $this->assertSame(60, $ana['rate']);
        $this->assertSame('1 manual, 1 pending', $ana['verified']);
        $this->assertTrue($s['to']->isSameDay(today()));
    }

    public function test_the_bulk_page_lists_everyone_and_is_offered_from_the_board(): void
    {
        Staff::factory()->administrator()->create(['name' => 'Ben Reyes']);

        $this->actingAs($this->ana)->get('/staff-attendance')
            ->assertOk()
            ->assertSee('Print All Staff Attendance')
            ->assertSee(route('reports.staff-attendance'), false);

        $this->actingAs($this->ana)->get('/reports/staff-attendance?from=2026-09-05&to=2026-09-11')
            ->assertOk()
            ->assertSee('Staff Attendance Summary')
            ->assertSeeInOrder(['Ana Cruz', '60%', '1 manual, 1 pending', 'Ben Reyes', 'All scanned']);
    }

    public function test_the_month_view_shows_present_and_absent_only(): void
    {
        // September 1-4 were working days with no check-in as well, so the
        // month is 3 present of 9 due.
        $this->actingAs($this->ana)->get('/staff-attendance/' . $this->ana->staff_id . '?month=2026-09')
            ->assertOk()
            ->assertViewHas('totals', fn ($t) => $t['present'] === 3 && $t['absent'] === 6)
            ->assertSee('33%')
            ->assertSee('67%')
            ->assertDontSee('>Late<', false)
            ->assertDontSee('Total worked')
            ->assertDontSee('b-gold', false);
    }

    public function test_an_empty_date_reads_select_date_not_a_raw_mask(): void
    {
        $this->actingAs($this->ana)->get('/records?tab=groups')
            ->assertOk()
            ->assertSee('Select Date')
            ->assertSee('data-date-field', false);

        $this->actingAs($this->ana)->get('/staff-attendance?date=2026-10-04')
            ->assertSee('Oct 4, 2026');
    }
}
