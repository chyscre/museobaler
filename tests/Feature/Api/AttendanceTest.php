<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\AttendanceController;
use App\Models\Attendance;
use App\Models\MuseumInfo;
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

    /** What POST /attendance hands back for a row, for rows made here. */
    private function handle(Attendance $row): string
    {
        return AttendanceController::handleFor((int) $row->attendance_id);
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

        $this->patchJson("/api/v1/attendance/{$anon->attendance_id}", ['visitor_id' => $v->visitor_id, 'visitor_name' => 'Maria Santos', 'handle' => $this->handle($anon)], $this->token($v))
            ->assertOk()->assertJsonPath('claimed', true);

        $anon->refresh();
        $this->assertSame($v->visitor_id, $anon->visitor_id);
        $this->assertSame('geofence', $anon->method, 'claiming does not relabel the arrival as manual');

        // Already somebody's: a no-op, not a 403 that maps the id space.
        $other = Visitor::factory()->create();
        $this->patchJson("/api/v1/attendance/{$anon->attendance_id}", ['visitor_id' => $other->visitor_id, 'handle' => $this->handle($anon)], $this->token($other))
            ->assertOk()->assertJsonPath('claimed', false);
        $this->assertSame($v->visitor_id, $anon->fresh()->visitor_id);

        // Yesterday's anonymous row is history, not today's arrival.
        $old = Attendance::create(['method' => 'geofence', 'visit_date' => today()->subDay()]);
        $this->patchJson("/api/v1/attendance/{$old->attendance_id}", ['handle' => $this->handle($old)], $this->token($other))
            ->assertOk()->assertJsonPath('claimed', false);

        // Nobody signed in cannot claim anything.
        $fresh = Attendance::create(['method' => 'geofence', 'visit_date' => today()]);
        $this->patchJson("/api/v1/attendance/{$fresh->attendance_id}", ['handle' => $this->handle($fresh)])->assertStatus(401);
    }

    /**
     * The handle is what stands in for the token an anonymous row has none of.
     *
     * Attendance ids are sequential, so without it `visitor_id IS NULL` was an
     * invitation to walk the id space: a probe could claim another phone's
     * arrival under its own name, or close out every unregistered visitor's
     * stay. Nothing about the answer tells it whether an id exists.
     */
    public function test_an_anonymous_row_is_untouchable_without_the_handle_it_was_issued(): void
    {
        $anon = Attendance::create(['method' => 'geofence', 'visit_date' => today()]);
        $v    = Visitor::factory()->create();

        // No handle, and a wrong one, are both the same silent no-op.
        $this->patchJson("/api/v1/attendance/{$anon->attendance_id}", ['event' => 'exit', 'duration_mins' => 600])
            ->assertOk()->assertJsonPath('claimed', false);
        $this->patchJson("/api/v1/attendance/{$anon->attendance_id}", ['event' => 'exit', 'duration_mins' => 600, 'handle' => str_repeat('a', 32)])
            ->assertOk()->assertJsonPath('claimed', false);
        $this->patchJson("/api/v1/attendance/{$anon->attendance_id}", ['visitor_id' => $v->visitor_id], $this->token($v))
            ->assertOk()->assertJsonPath('claimed', false);

        $anon->refresh();
        $this->assertNull($anon->duration_mins);
        $this->assertNull($anon->exited_at);
        $this->assertNull($anon->visitor_id);

        // The handle for one row is no use on the next.
        $neighbour = Attendance::create(['method' => 'geofence', 'visit_date' => today()]);
        $this->patchJson("/api/v1/attendance/{$neighbour->attendance_id}", ['event' => 'exit', 'duration_mins' => 30, 'handle' => $this->handle($anon)])
            ->assertOk()->assertJsonPath('claimed', false);
        $this->assertNull($neighbour->fresh()->duration_mins);
    }

    /** The arrival hands the phone its handle, and that handle works. */
    public function test_the_arrival_answers_with_a_handle_the_same_phone_can_use(): void
    {
        $res = $this->postJson('/api/v1/attendance', ['latitude' => 15.7604, 'longitude' => 121.5617, 'accuracy' => 12])
            ->assertOk();

        $id     = $res->json('attendance_id');
        $handle = $res->json('handle');
        $this->assertIsString($handle);
        $this->assertSame(32, strlen($handle));

        $this->patchJson("/api/v1/attendance/{$id}", ['event' => 'exit', 'duration_mins' => 45, 'handle' => $handle])->assertOk();
        $this->assertSame(45, Attendance::find($id)->duration_mins);
    }

    /** A visit cannot last longer than a day, and the longest value sticks. */
    public function test_an_absurd_duration_is_refused_rather_than_kept_forever(): void
    {
        $v   = Visitor::factory()->create();
        $row = Attendance::create(['visitor_id' => $v->visitor_id, 'method' => 'geofence', 'visit_date' => today()]);

        $this->patchJson("/api/v1/attendance/{$row->attendance_id}", ['event' => 'exit', 'duration_mins' => 999999], $this->token($v))
            ->assertStatus(422);

        $this->assertNull($row->fresh()->duration_mins);
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

        // An anonymous row can be closed by the anonymous phone - the one
        // that can produce the handle its arrival was answered with.
        $anon = Attendance::create(['method' => 'geofence', 'visit_date' => today()]);
        $this->patchJson("/api/v1/attendance/{$anon->attendance_id}", ['event' => 'exit', 'duration_mins' => 10, 'handle' => $this->handle($anon)])->assertOk();
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

    // -- The fence, on the server --------------------------------------------

    private function pin(int $radius = 150): void
    {
        MuseumInfo::create([
            'name' => 'Museo de Baler', 'latitude' => 15.760440, 'longitude' => 121.561695, 'geofence_radius_m' => $radius,
        ]);
    }

    /**
     * The app checks the distance before it posts, but that check runs on the
     * visitor's own phone. Until this, it was the only one: the day's visitor
     * figures - which the municipality reports on - could be written from
     * anywhere by anyone with curl, and each row would look exactly like a
     * real arrival.
     */
    public function test_an_arrival_from_nowhere_near_baler_is_refused(): void
    {
        $this->pin();

        // Manila, about 230 km away.
        $this->postJson('/api/v1/attendance', ['latitude' => 14.5995, 'longitude' => 120.9842, 'accuracy' => 8])
            ->assertStatus(422)
            ->assertJsonPath('error', 'outside_fence');

        $this->assertSame(0, Attendance::count());
    }

    /** A claimed accuracy of ten kilometres does not widen the grounds. */
    public function test_a_wildly_vague_fix_cannot_buy_its_way_in_from_far_away(): void
    {
        $this->pin();

        $this->postJson('/api/v1/attendance', ['latitude' => 14.5995, 'longitude' => 120.9842, 'accuracy' => 10000])
            ->assertStatus(422);

        $this->assertSame(0, Attendance::count());
    }

    /**
     * And the forgiving half, which matters more: a fix taken indoors is
     * routinely vaguer than the fence is wide, and those visitors are exactly
     * the ones this record exists to count.
     */
    public function test_a_visitor_at_the_door_is_recorded_even_with_a_vague_fix(): void
    {
        $this->pin();

        // ~200 m from the pin, with the phone unsure to within 300 m.
        $this->postJson('/api/v1/attendance', ['latitude' => 15.762240, 'longitude' => 121.561695, 'accuracy' => 300])
            ->assertOk()
            ->assertJsonPath('already_logged', false);

        $this->assertSame(1, Attendance::count());
    }

    /** With the fence enforced, a request with no position is not the app. */
    public function test_an_arrival_with_no_position_at_all_is_refused_while_the_fence_stands(): void
    {
        $this->pin();

        $this->postJson('/api/v1/attendance', ['visitor_name' => 'Nobody'])->assertStatus(422);

        $this->assertSame(0, Attendance::count());
    }

    /** Off for a desk far from Baler - and then anything is accepted. */
    public function test_with_the_visitor_fence_off_an_off_site_arrival_is_recorded(): void
    {
        config(['access.visitor_geofence' => false]);
        $this->pin();

        $this->postJson('/api/v1/attendance', ['latitude' => 14.5995, 'longitude' => 120.9842, 'accuracy' => 8])
            ->assertOk();

        $this->assertSame(1, Attendance::count());
    }

    /** No pin saved yet must not silently stop the count. */
    public function test_a_museum_with_no_pin_yet_still_counts_arrivals(): void
    {
        MuseumInfo::create(['name' => 'Museo de Baler']);

        $this->postJson('/api/v1/attendance', ['latitude' => 14.5995, 'longitude' => 120.9842, 'accuracy' => 8])
            ->assertOk();

        $this->assertSame(1, Attendance::count());
    }
}
