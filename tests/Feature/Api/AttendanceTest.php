<?php

namespace Tests\Feature\Api;

use App\Models\Attendance;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
