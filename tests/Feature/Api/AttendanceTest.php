<?php

namespace Tests\Feature\Api;

use App\Models\Attendance;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The geofence's attendance record: an arrival, an exit, and a registration
 * claiming the anonymous row the fence made before the visitor signed up.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function token(Visitor $v): array
    {
        return ['Authorization' => 'Bearer ' . $v->issueToken()['token']];
    }

    public function test_an_anonymous_phone_crossing_the_fence_is_recorded(): void
    {
        $res = $this->postJson('/api/v1/attendance', ['latitude' => 15.7604, 'longitude' => 121.5617, 'accuracy' => 12])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('already_logged', false);

        $row = Attendance::findOrFail($res->json('attendance_id'));
        $this->assertNull($row->visitor_id);
        $this->assertSame('geofence', $row->method);
        $this->assertTrue($row->visit_date->isToday());
    }

    public function test_a_signed_in_visitor_is_logged_once_per_day_under_their_own_name(): void
    {
        $v = Visitor::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);
        $h = $this->token($v);

        $first = $this->postJson('/api/v1/attendance', ['visitor_name' => 'Somebody Else'], $h)->assertOk();
        $this->assertSame('Maria Santos', Attendance::first()->visitor_name, 'the name is the account\'s, not the body\'s');

        $this->postJson('/api/v1/attendance', [], $h)
            ->assertOk()
            ->assertJsonPath('already_logged', true)
            ->assertJsonPath('attendance_id', $first->json('attendance_id'));

        $this->assertSame(1, Attendance::count());
    }

    public function test_a_body_claiming_to_be_someone_else_is_recorded_as_nobody(): void
    {
        $me    = Visitor::factory()->create();
        $other = Visitor::factory()->create();

        $this->postJson('/api/v1/attendance', ['visitor_id' => $other->visitor_id], $this->token($me))->assertOk();

        $this->assertNull(Attendance::first()->visitor_id);
    }

    public function test_registering_claims_todays_anonymous_row_but_nobody_elses(): void
    {
        $anon = Attendance::create(['method' => 'geofence', 'visit_date' => today()]);
        $v    = Visitor::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);

        $this->patchJson("/api/v1/attendance/{$anon->attendance_id}", ['visitor_id' => $v->visitor_id, 'visitor_name' => 'Maria Santos'], $this->token($v))
            ->assertOk()->assertJsonPath('claimed', true);

        $anon->refresh();
        $this->assertSame($v->visitor_id, $anon->visitor_id);
        $this->assertSame('geofence', $anon->method, 'claiming does not relabel the arrival as manual');

        // Already somebody's: a no-op, not a 403 that maps the id space.
        $other = Visitor::factory()->create();
        $this->patchJson("/api/v1/attendance/{$anon->attendance_id}", ['visitor_id' => $other->visitor_id], $this->token($other))
            ->assertOk()->assertJsonPath('claimed', false);
        $this->assertSame($v->visitor_id, $anon->fresh()->visitor_id);

        // Yesterday's anonymous row is history, not today's arrival.
        $old = Attendance::create(['method' => 'geofence', 'visit_date' => today()->subDay()]);
        $this->patchJson("/api/v1/attendance/{$old->attendance_id}", [], $this->token($other))
            ->assertOk()->assertJsonPath('claimed', false);

        // Nobody signed in cannot claim anything.
        $fresh = Attendance::create(['method' => 'geofence', 'visit_date' => today()]);
        $this->patchJson("/api/v1/attendance/{$fresh->attendance_id}", [])->assertStatus(401);
    }

    public function test_an_exit_keeps_the_longest_duration_seen(): void
    {
        $v   = Visitor::factory()->create();
        $h   = $this->token($v);
        $row = Attendance::create(['visitor_id' => $v->visitor_id, 'method' => 'geofence', 'visit_date' => today()]);

        $this->patchJson("/api/v1/attendance/{$row->attendance_id}", ['event' => 'exit', 'duration_mins' => 95], $h)->assertOk();
        $this->assertSame(95, $row->fresh()->duration_mins);
        $this->assertNotNull($row->fresh()->exited_at);

        // The app reopened in the car park: a two-minute fragment must not
        // overwrite the visit.
        $this->patchJson("/api/v1/attendance/{$row->attendance_id}", ['event' => 'exit', 'duration_mins' => 2], $h)->assertOk();
        $this->assertSame(95, $row->fresh()->duration_mins);

        // An anonymous row can be closed by the anonymous phone.
        $anon = Attendance::create(['method' => 'geofence', 'visit_date' => today()]);
        $this->patchJson("/api/v1/attendance/{$anon->attendance_id}", ['event' => 'exit', 'duration_mins' => 10])->assertOk();
        $this->assertSame(10, $anon->fresh()->duration_mins);

        // Somebody else's row: silently nothing.
        $other = Visitor::factory()->create();
        $this->patchJson("/api/v1/attendance/{$row->attendance_id}", ['event' => 'exit', 'duration_mins' => 500], $this->token($other))
            ->assertOk()->assertJsonPath('claimed', false);
        $this->assertSame(95, $row->fresh()->duration_mins);
    }

    public function test_an_unknown_id_or_a_bad_coordinate_is_handled(): void
    {
        $this->patchJson('/api/v1/attendance/999999', ['event' => 'exit'])->assertOk()->assertJsonPath('claimed', false);
        $this->patchJson('/api/v1/attendance/abc', ['event' => 'exit'])->assertNotFound();
        $this->postJson('/api/v1/attendance', ['latitude' => 999])->assertStatus(422);
    }

    /**
     * Two check-ins for the same visitor landing at the same moment.
     *
     * The phone guards against this in memory, but that guard is per tab and
     * per page load: the app open on a phone and a tablet, or reopened while
     * the first request is still in flight, sends two. Both read "no row for
     * today" before either writes one, and the unique key on
     * (visitor_id, visit_date) then refuses the second insert.
     *
     * That refusal is correct and is what keeps the visitor count honest. What
     * matters is that the loser of the race is told "already logged" and not
     * handed a server error, because from the visitor's side nothing went
     * wrong - they are checked in.
     *
     * The race is made deterministic by writing the conflicting row from a
     * `creating` hook: that fires after the request has done its check and
     * before its own insert, which is exactly the window a real second request
     * would land in.
     */
    public function test_two_simultaneous_check_ins_settle_on_one_record(): void
    {
        $visitor = Visitor::factory()->create();

        Attendance::creating(function () use ($visitor) {
            // Only the first insert loses the race; the recovery must not
            // trip this again or the test would loop.
            Attendance::unsetEventDispatcher();

            DB::table('attendances')->insert([
                'visitor_id' => $visitor->visitor_id,
                'method'     => 'geofence',
                'visit_date' => today(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->postJson('/api/v1/attendance', [], $this->token($visitor))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('already_logged', true);

        $this->assertSame(1, Attendance::where('visitor_id', $visitor->visitor_id)->count());
    }
}
