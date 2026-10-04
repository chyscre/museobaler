<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Exhibit;
use App\Models\MuseumHall;
use App\Models\Scan;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Nobody away from the museum can unlock an exhibit by typing or scanning a
 * code.
 *
 * Exhibit codes are short and sequential (EXH-001, EXH-002...), so guessing
 * one is easy. What stops it is the daily session lock: every route that
 * opens an exhibit - the lookup a QR scan makes, the scan record, the photo
 * matcher - requires a visitor the front desk cleared TODAY, in person.
 * These tests hold each route to that, so a later refactor cannot quietly
 * move one out from behind the gate.
 */
class ScanLockTest extends TestCase
{
    use RefreshDatabase;

    private function exhibit(): Exhibit
    {
        $hall = MuseumHall::firstOrCreate(['name' => 'Hall A'], ['floor' => 'Ground Floor', 'sort_order' => 1]);
        $cat  = Category::firstOrCreate(['name' => 'History']);

        return Exhibit::create([
            'exhibit_code'    => 'EXH-001',
            'name'            => 'Siege of Baler Diorama',
            'description'     => 'Three hundred and thirty-seven days.',
            'category_id'     => $cat->category_id,
            'hall_id'         => $hall->hall_id,
            'languages'       => 'en',
            'storyline_order' => 1,
            'status'          => true,
        ]);
    }

    private function token(Visitor $v): array
    {
        return ['Authorization' => 'Bearer ' . $v->issueToken()['token']];
    }

    public function test_a_guessed_code_without_a_token_unlocks_nothing(): void
    {
        $this->exhibit();

        $this->getJson('/api/v1/exhibits/EXH-001')
            ->assertStatus(401)
            ->assertJsonMissing(['description' => 'Three hundred and thirty-seven days.']);
    }

    public function test_an_account_never_admitted_at_the_desk_cannot_open_an_exhibit(): void
    {
        $this->exhibit();
        $v = Visitor::factory()->create(['payment_status' => 'Unpaid', 'paid_at' => null]);

        $this->getJson('/api/v1/exhibits/EXH-001', $this->token($v))
            ->assertStatus(403)
            ->assertJsonPath('error', 'not_cleared')
            ->assertJsonMissing(['description' => 'Three hundred and thirty-seven days.']);
    }

    /** Admitted yesterday, scanning a label from home today. */
    public function test_yesterdays_admission_does_not_unlock_a_code_today(): void
    {
        $exhibit = $this->exhibit();
        $v = Visitor::factory()->paid()->create(['paid_at' => now()->subDay()]);
        $headers = $this->token($v);

        $this->getJson('/api/v1/exhibits/EXH-001', $headers)->assertStatus(403);

        $this->postJson('/api/v1/scans', ['exhibit_id' => $exhibit->exhibit_id], $headers)
            ->assertStatus(403);
        $this->assertSame(0, Scan::count());

        $this->post('/api/v1/recognition', [
            'frame' => UploadedFile::fake()->image('frame.jpg', 64, 64),
        ], $headers + ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_a_visitor_admitted_today_can_open_the_exhibit(): void
    {
        $this->exhibit();
        $v = Visitor::factory()->paid()->create();

        $this->getJson('/api/v1/exhibits/EXH-001', $this->token($v))
            ->assertOk()
            ->assertJsonPath('exhibit_code', 'EXH-001');
    }
}
