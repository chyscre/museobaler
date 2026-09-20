<?php

namespace Tests\Feature;

use App\Models\AttendanceCorrection;
use App\Models\Log;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Tourism office oversees the museum from outside it, and these are the
 * boundaries that make that oversight real rather than decorative:
 *
 *  - the museum head cannot promote themselves or mint an Administrator
 *  - nobody files their own attendance
 *  - filing and approving a correction are always two different people
 *  - the audit trail is not readable by the staff it audits
 */
class OversightTest extends TestCase
{
    use RefreshDatabase;

    // -- Role hierarchy ----------------------------------------------------

    public function test_museum_staff_cannot_create_a_colleague(): void
    {
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->post('/staff', [
            'name' => 'Second Admin', 'email' => 'second@example.com',
            'password' => 'Password1!', 'role' => 'Administrator',
        ])->assertForbidden();

        $this->assertDatabaseMissing('staff', ['email' => 'second@example.com']);
    }

    public function test_museum_staff_cannot_create_a_tourism_account(): void
    {
        // The tier being overseen must never be able to mint its overseer.
        $staff = Staff::factory()->administrator()->create();

        $this->actingAs($staff)->post('/staff', [
            'name' => 'Fake Tourism', 'email' => 'fake@example.com',
            'password' => 'Password1!', 'role' => 'TourismHead',
        ])->assertForbidden();

        $this->assertDatabaseMissing('staff', ['email' => 'fake@example.com']);
    }

    public function test_museum_staff_cannot_promote_themselves(): void
    {
        $staff = Staff::factory()->administrator()->create(['email' => 'me@example.com']);

        $this->actingAs($staff)->put("/staff/{$staff->staff_id}", [
            'name' => $staff->name, 'email' => 'me@example.com', 'role' => 'TourismHead',
        ])->assertForbidden();

        $this->assertSame('Administrator', $staff->fresh()->role);
    }

    public function test_the_tourism_office_can_create_any_role(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();

        $this->actingAs($tourism)->post('/staff', [
            'name' => 'New Head', 'email' => 'head@example.com',
            'password' => 'Password1!', 'role' => 'Administrator',
        ])->assertRedirect();

        $this->assertDatabaseHas('staff', ['email' => 'head@example.com', 'role' => 'Administrator']);
    }

    public function test_a_museum_administrator_cannot_take_over_a_tourism_account(): void
    {
        $admin   = Staff::factory()->administrator()->create();
        $tourism = Staff::factory()->tourismHead()->create(['email' => 'tourism@example.com']);

        // Resetting the password would be the takeover; the role change is
        // just the visible half.
        $this->actingAs($admin)->put("/staff/{$tourism->staff_id}", [
            'name' => 'Hijacked', 'email' => 'tourism@example.com',
            'role' => 'Administrator', 'password' => 'Newpass1!',
        ])->assertForbidden();

        $this->assertSame('TourismHead', $tourism->fresh()->role);
        $this->assertSame($tourism->name, $tourism->fresh()->name);
    }

    public function test_the_last_tourism_account_cannot_be_deactivated(): void
    {
        $tourism = Staff::factory()->tourismHead()->create();
        $other   = Staff::factory()->tourismHead()->create();

        // Deactivating the second-to-last is fine.
        $this->actingAs($tourism)->post("/staff/{$other->staff_id}/toggle");
        $this->assertFalse($other->fresh()->status);

        // The last one is not, or staff management locks itself shut forever.
        $spare = Staff::factory()->tourismHead()->create();
        $this->actingAs($spare)->post("/staff/{$tourism->staff_id}/toggle");
        $this->assertFalse($tourism->fresh()->status);

        $this->actingAs($spare)->post("/staff/{$spare->staff_id}/toggle");
        $this->assertTrue($spare->fresh()->status);
    }

    // -- Audit log ---------------------------------------------------------

    public function test_both_roles_can_read_the_audit_log(): void
    {
        // With one museum role there is nobody to hide it from, and staff
        // seeing that their own actions are recorded is the point of it.
        // Exporting is a separate, narrower right — see the next test.
        foreach (['administrator', 'tourismHead'] as $role) {
            $this->actingAs(Staff::factory()->$role()->create())
                ->get('/logs')->assertOk();
        }
    }

    public function test_only_the_tourism_office_can_export_the_audit_log(): void
    {
        $this->actingAs(Staff::factory()->administrator()->create())
            ->get('/reports/audit/csv')->assertForbidden();

        $this->actingAs(Staff::factory()->tourismHead()->create())
            ->get('/reports/audit/csv')->assertOk();
    }

    // -- Correction workflow -----------------------------------------------

    public function test_nobody_can_file_a_correction_for_their_own_attendance(): void
    {
        $admin = Staff::factory()->administrator()->create();

        $this->actingAs($admin)->post('/attendance/corrections', [
            'staff_id' => $admin->staff_id, 'work_date' => today()->subDay()->toDateString(),
            'type' => 'in', 'requested_time' => '08:00',
            'reason' => 'I was definitely here on time, honestly.',
        ]);

        $this->assertDatabaseCount('attendance_corrections', 0);
    }

    public function test_a_filed_correction_does_not_count_until_approved(): void
    {
        $admin = Staff::factory()->administrator()->create();
        $guide = Staff::factory()->administrator()->create();

        $this->actingAs($admin)->post('/attendance/corrections', [
            'staff_id' => $guide->staff_id, 'work_date' => today()->subDay()->toDateString(),
            'type' => 'in', 'requested_time' => '08:05',
            'reason' => 'Phone battery died before they could scan in.',
        ])->assertRedirect();

        $this->assertDatabaseHas('attendance_corrections', ['status' => 'Pending']);

        // Nothing lands on the attendance record itself yet.
        $this->assertDatabaseCount('staff_attendances', 0);
    }

    public function test_the_tourism_office_approving_creates_the_attendance_row(): void
    {
        $admin   = Staff::factory()->administrator()->create();
        $guide   = Staff::factory()->administrator()->create();
        $tourism = Staff::factory()->tourismHead()->create();

        $this->actingAs($admin)->post('/attendance/corrections', [
            'staff_id' => $guide->staff_id, 'work_date' => today()->subDay()->toDateString(),
            'type' => 'in', 'requested_time' => '08:05',
            'reason' => 'Phone battery died before they could scan in.',
        ]);

        $correction = AttendanceCorrection::first();

        $this->actingAs($tourism)->post("/attendance/corrections/{$correction->correction_id}/review", [
            'decision' => 'Approved',
        ])->assertRedirect();

        $this->assertDatabaseHas('staff_attendances', [
            'staff_id'    => $guide->staff_id,
            'type'        => 'in',
            'method'      => 'manual',
            'recorded_by' => $tourism->staff_id,
        ]);
    }

    public function test_the_tourism_office_cannot_file_a_correction_at_all(): void
    {
        // She was not on site to vouch for anyone, and if she could both file
        // and approve, the two-signature rule would be one person with extra
        // steps. Filing is museum work; her half is the review.
        $tourism = Staff::factory()->tourismHead()->create();
        $guide   = Staff::factory()->administrator()->create();

        $this->actingAs($tourism)->post('/attendance/corrections', [
            'staff_id' => $guide->staff_id, 'work_date' => today()->subDay()->toDateString(),
            'type' => 'in', 'requested_time' => '08:05',
            'reason' => 'Kiosk tablet was offline all morning.',
        ])->assertForbidden();

        $this->assertDatabaseCount('attendance_corrections', 0);

        // She still reads the list — that is where the approve buttons live.
        $this->actingAs($tourism)->get('/attendance/corrections')->assertOk();
    }

    public function test_the_tourism_office_cannot_act_on_visitor_records(): void
    {
        // Records is a window for her, not a counter. Collecting a fee or
        // sighting an ID is desk work and stays behind the museum-only routes.
        $tourism = Staff::factory()->tourismHead()->create();
        $visitor = \App\Models\Visitor::create([
            'first_name' => 'Ramon', 'last_name' => 'Cruz', 'visitor_type' => 'Tourist',
            'admission_fee' => 50, 'payment_status' => 'Unpaid',
        ]);

        $this->actingAs($tourism)->get('/records')->assertOk();
        $this->actingAs($tourism)->post("/visitors/{$visitor->visitor_id}/mark-paid")->assertForbidden();
        $this->actingAs($tourism)->post("/visitors/{$visitor->visitor_id}/verify-id")->assertForbidden();

        $this->assertSame('Unpaid', $visitor->fresh()->payment_status);
    }

    public function test_a_museum_administrator_cannot_approve_a_correction(): void
    {
        $filer  = Staff::factory()->administrator()->create();
        $admin2 = Staff::factory()->administrator()->create();
        $guide  = Staff::factory()->administrator()->create();

        $this->actingAs($filer)->post('/attendance/corrections', [
            'staff_id' => $guide->staff_id, 'work_date' => today()->subDay()->toDateString(),
            'type' => 'in', 'requested_time' => '08:05',
            'reason' => 'Phone battery died before they could scan in.',
        ]);

        $correction = AttendanceCorrection::first();

        $this->actingAs($admin2)->post("/attendance/corrections/{$correction->correction_id}/review", [
            'decision' => 'Approved',
        ])->assertForbidden();

        $this->assertSame('Pending', $correction->fresh()->status);
    }

    public function test_a_rejected_correction_records_nothing(): void
    {
        $admin   = Staff::factory()->administrator()->create();
        $guide   = Staff::factory()->administrator()->create();
        $tourism = Staff::factory()->tourismHead()->create();

        $this->actingAs($admin)->post('/attendance/corrections', [
            'staff_id' => $guide->staff_id, 'work_date' => today()->subDay()->toDateString(),
            'type' => 'in', 'requested_time' => '08:05',
            'reason' => 'Claimed they were here but nobody saw them.',
        ]);

        $correction = AttendanceCorrection::first();

        $this->actingAs($tourism)->post("/attendance/corrections/{$correction->correction_id}/review", [
            'decision' => 'Rejected', 'review_note' => 'Not corroborated.',
        ]);

        $this->assertSame('Rejected', $correction->fresh()->status);
        $this->assertDatabaseCount('staff_attendances', 0);
    }

    public function test_a_role_change_is_recorded_with_what_it_changed_from(): void
    {
        // Promoting museum staff to the Tourism office is the single most
        // consequential change in the system, so the log has to say what it
        // changed from, not merely that something was updated.
        $tourism = Staff::factory()->tourismHead()->create();
        $member  = Staff::factory()->administrator()->create(['email' => 'g@example.com']);

        $this->actingAs($tourism)->put("/staff/{$member->staff_id}", [
            'name' => $member->name, 'email' => 'g@example.com', 'role' => 'TourismHead',
        ]);

        $log = Log::where('action', 'Staff Updated')->latest('log_id')->first();

        $this->assertStringContainsString('Administrator', $log->details);
        $this->assertStringContainsString('TourismHead', $log->details);
    }
}
