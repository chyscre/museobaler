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

    public function test_an_uncleared_visitor_cannot_log_scans(): void
    {
        $e = $this->exhibit();
        $v = Visitor::factory()->create();

        $this->postJson('/api/v1/scans', ['exhibit_id' => $e->exhibit_id], ['Authorization' => 'Bearer ' . $v->issueToken()['token']])
            ->assertStatus(403);
        $this->postJson('/api/v1/scans', ['exhibit_id' => $e->exhibit_id])->assertStatus(401);
    }
}
