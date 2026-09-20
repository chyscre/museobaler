<?php

namespace Tests\Feature;

use App\Models\Exhibit;
use App\Models\MuseumHall;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An exhibit's hall and floor are read from its museum_halls row, so the
 * halls staff manage on the Museum Info page are the halls exhibits sit in.
 */
class ExhibitHallTest extends TestCase
{
    use RefreshDatabase;

    public function test_hall_and_floor_are_read_from_the_hall_row(): void
    {
        $hall = MuseumHall::create(['name' => 'Hall B — Culture', 'floor' => '2nd Floor', 'sort_order' => 1]);
        $ex   = Exhibit::create(['exhibit_code' => 'EXH-001', 'name' => 'Loom', 'hall_id' => $hall->hall_id, 'status' => true]);

        $this->assertSame('Hall B — Culture', $ex->fresh()->hall);
        $this->assertSame('2nd Floor', $ex->fresh()->floor);

        // Renaming the hall renames it on every exhibit; nothing to re-save.
        $hall->update(['name' => 'Hall B — Heritage']);
        $this->assertSame('Hall B — Heritage', $ex->fresh()->hall);
    }

    public function test_an_exhibit_without_a_hall_has_no_hall_or_floor(): void
    {
        $ex = Exhibit::create(['exhibit_code' => 'EXH-002', 'name' => 'Cannon', 'status' => true]);

        $this->assertNull($ex->hall);
        $this->assertNull($ex->floor);
    }

    public function test_removing_a_hall_unassigns_its_exhibits_instead_of_deleting_them(): void
    {
        $keep = MuseumHall::create(['name' => 'Hall A', 'floor' => 'Ground Floor', 'sort_order' => 1]);
        $gone = MuseumHall::create(['name' => 'Hall E', 'floor' => '2nd Floor', 'sort_order' => 2]);
        $ex   = Exhibit::create(['exhibit_code' => 'EXH-003', 'name' => 'Fern', 'hall_id' => $gone->hall_id, 'status' => true]);

        $this->actingAs(Staff::factory()->administrator()->create())->post('/museum', [
            'name'          => 'Museo de Baler',
            'admission_fee' => 30,
            'halls'         => json_encode([['id' => $keep->hall_id, 'name' => 'Hall A', 'floor' => 'Ground Floor', 'sort' => 1]]),
        ]);

        $this->assertDatabaseMissing('museum_halls', ['hall_id' => $gone->hall_id]);
        $this->assertNull($ex->fresh()->hall_id);
        $this->assertSame('Fern', $ex->fresh()->name);
    }

    public function test_the_exhibit_form_offers_the_halls_from_the_museum_page(): void
    {
        MuseumHall::create(['name' => 'Hall F — Maritime', 'floor' => 'Ground Floor', 'sort_order' => 6]);

        $this->actingAs(Staff::factory()->administrator()->create())
            ->get('/exhibits')
            ->assertOk()
            ->assertSee('Hall F — Maritime');
    }

    public function test_saving_an_exhibit_stores_the_chosen_hall(): void
    {
        $hall = MuseumHall::create(['name' => 'Hall C', 'floor' => 'Ground Floor', 'sort_order' => 3]);
        $ex   = Exhibit::create(['exhibit_code' => 'EXH-004', 'name' => 'Basket', 'status' => true]);

        $this->actingAs(Staff::factory()->administrator()->create())->put('/exhibits/' . $ex->exhibit_id, [
            'exhibit_code' => 'EXH-004',
            'name'         => 'Basket',
            'hall_id'      => $hall->hall_id,
        ]);

        $this->assertSame($hall->hall_id, $ex->fresh()->hall_id);
    }
}
