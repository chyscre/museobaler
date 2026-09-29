<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Exhibit;
use App\Models\MuseumHall;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScansTest extends TestCase
{
    use RefreshDatabase;

    private function exhibit(): Exhibit
    {
        $hall = MuseumHall::create(['name' => 'Hall A', 'floor' => 'Ground Floor', 'sort_order' => 1]);
        $cat  = Category::create(['name' => 'History']);

        return Exhibit::create([
            'exhibit_code' => 'EXH-001', 'name' => 'Siege', 'description' => '-', 'category_id' => $cat->category_id,
            'hall_id' => $hall->hall_id, 'languages' => 'en', 'storyline_order' => 1, 'status' => true,
        ]);
    }

    public function test_a_scan_is_logged_to_the_tokens_visitor_whatever_the_body_says(): void
    {
        $e     = $this->exhibit();
        $me    = Visitor::factory()->paid()->create();
        $other = Visitor::factory()->paid()->create();

        $this->postJson('/api/v1/scans', ['exhibit_id' => $e->exhibit_id, 'scan_type' => 'qr', 'visitor_id' => $other->visitor_id], [
            'Authorization' => 'Bearer ' . $me->issueToken()['token'],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('scans', ['exhibit_id' => $e->exhibit_id, 'visitor_id' => $me->visitor_id, 'scan_type' => 'qr']);
        $this->assertDatabaseMissing('scans', ['visitor_id' => $other->visitor_id]);
    }

    public function test_an_unknown_exhibit_or_type_is_refused(): void
    {
        $v = Visitor::factory()->paid()->create();
        $h = ['Authorization' => 'Bearer ' . $v->issueToken()['token']];

        $this->postJson('/api/v1/scans', ['exhibit_id' => 999], $h)->assertStatus(422)->assertJsonPath('field', 'exhibit_id');
        $this->postJson('/api/v1/scans', ['exhibit_id' => $this->exhibit()->exhibit_id, 'scan_type' => 'psychic'], $h)->assertStatus(422);
    }

    /**
     * A scan the app made with no signal and sent when it got some.
     *
     * The time it happened is the app's to report, because the server only
     * ever learns when the phone came back into coverage - and filing a hall
     * full of scans at that one moment is what made the quiet end of the
     * museum look unvisited.
     */
    public function test_a_scan_held_in_the_offline_queue_keeps_the_time_it_happened(): void
    {
        $e = $this->exhibit();
        $v = Visitor::factory()->paid()->create();
        $h = ['Authorization' => 'Bearer ' . $v->issueToken()['token']];

        $when = now()->subMinutes(40);

        $this->postJson('/api/v1/scans', [
            'exhibit_id' => $e->exhibit_id,
            'scan_type'  => 'qr',
            'scanned_at' => $when->toIso8601String(),
        ], $h)->assertOk();

        $this->assertSame(
            $when->format('Y-m-d H:i'),
            \App\Models\Scan::first()->scanned_at->format('Y-m-d H:i'),
        );
    }

    /**
     * The bounds on that, which are what stop it being a way to write
     * history: a scan cannot have happened tomorrow, and a backlog older
     * than two days is a wrong clock or a replay, not a dead zone.
     */
    public function test_a_scan_time_outside_the_plausible_window_is_refused(): void
    {
        $e = $this->exhibit();
        $v = Visitor::factory()->paid()->create();
        $h = ['Authorization' => 'Bearer ' . $v->issueToken()['token']];

        foreach ([now()->addDay(), now()->subWeek(), now()->subYears(3)] as $when) {
            $this->postJson('/api/v1/scans', [
                'exhibit_id' => $e->exhibit_id,
                'scanned_at' => $when->toIso8601String(),
            ], $h)->assertStatus(422);
        }

        $this->assertSame(0, \App\Models\Scan::count());
    }

    public function test_an_uncleared_visitor_cannot_log_scans(): void
    {
        $e = $this->exhibit();
        $v = Visitor::factory()->create();

        $this->postJson('/api/v1/scans', ['exhibit_id' => $e->exhibit_id], ['Authorization' => 'Bearer ' . $v->issueToken()['token']])
            ->assertStatus(403);
        $this->postJson('/api/v1/scans', ['exhibit_id' => $e->exhibit_id])->assertStatus(401);
    }
}
