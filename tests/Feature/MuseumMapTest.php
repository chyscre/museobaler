<?php

namespace Tests\Feature;

use App\Models\Exhibit;
use App\Models\MuseumHall;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The museum map draws its exhibit pins from map_x/map_y on the exhibit,
 * and an administrator can drag them and save the whole layout at once.
 */
class MuseumMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_page_hands_the_pins_to_the_script(): void
    {
        // The floor comes from the hall, so the map keys off the hall row.
        $ground = MuseumHall::create(['name' => 'Hall A', 'floor' => 'Ground Floor', 'sort_order' => 1]);
        $upper  = MuseumHall::create(['name' => 'Hall D', 'floor' => '2nd Floor', 'sort_order' => 2]);
        Exhibit::create(['exhibit_code' => 'EXH-001', 'name' => 'Cannon', 'hall_id' => $ground->hall_id, 'map_x' => 12.5, 'map_y' => 40, 'status' => true]);
        Exhibit::create(['exhibit_code' => 'EXH-002', 'name' => 'Loom',   'hall_id' => $upper->hall_id, 'status' => true]);

        $response = $this->actingAs(Staff::factory()->administrator()->create())->get('/map');

        $response->assertOk();
        $pins = $response->viewData('pins');
        $this->assertCount(2, $pins);
        $this->assertSame('ground', $pins[0]['floor']);
        $this->assertEquals(12.5, $pins[0]['x']);
        $this->assertSame('second', $pins[1]['floor']);
        $this->assertNull($pins[1]['x'], 'An unplaced exhibit must reach the page as null so it gets a default spot.');
    }

    public function test_administrator_can_save_dragged_positions(): void
    {
        $a = Exhibit::create(['exhibit_code' => 'EXH-001', 'name' => 'Cannon', 'status' => true]);
        $b = Exhibit::create(['exhibit_code' => 'EXH-002', 'name' => 'Loom',   'status' => true]);

        $this->actingAs(Staff::factory()->administrator()->create())
            ->postJson('/map/positions', ['positions' => [
                ['id' => $a->exhibit_id, 'x' => 33.333, 'y' => 66.666],
                ['id' => $b->exhibit_id, 'x' => 5,      'y' => 95],
            ]])
            ->assertOk()->assertJson(['ok' => true, 'saved' => 2]);

        $this->assertEquals(33.33, $a->fresh()->map_x);
        $this->assertEquals(66.67, $a->fresh()->map_y);
        $this->assertEquals(5, $b->fresh()->map_x);
    }

    public function test_positions_outside_the_plan_are_refused(): void
    {
        $a = Exhibit::create(['exhibit_code' => 'EXH-001', 'name' => 'Cannon', 'status' => true]);

        $this->actingAs(Staff::factory()->administrator()->create())
            ->postJson('/map/positions', ['positions' => [['id' => $a->exhibit_id, 'x' => 120, 'y' => -3]]])
            ->assertUnprocessable();

        $this->assertNull($a->fresh()->map_x);
    }
}
