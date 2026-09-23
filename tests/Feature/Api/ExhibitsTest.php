<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Exhibit;
use App\Models\ExhibitImage;
use App\Models\ExhibitTranslation;
use App\Models\MuseumHall;
use App\Models\Visitor;
use App\Models\VisitGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The exhibits, and the gate in front of them.
 *
 * Exhibit content is what the admission fee pays for, so this is where a
 * missing token, an expired one, and an unpaid visitor are all turned away
 * - each with the answer the app's waiting screen is built around.
 */
class ExhibitsTest extends TestCase
{
    use RefreshDatabase;

    private function exhibit(array $overrides = []): Exhibit
    {
        $hall = MuseumHall::firstOrCreate(['name' => 'Hall A'], ['floor' => 'Ground Floor', 'sort_order' => 1]);
        $cat  = Category::firstOrCreate(['name' => 'History']);

        return Exhibit::create($overrides + [
            'exhibit_code'    => 'EXH-' . fake()->unique()->numerify('###'),
            'name'            => 'Siege of Baler Diorama',
            'description'     => 'Three hundred and thirty-seven days.',
            'fun_facts'       => "First fact\n\nSecond fact\n",
            'category_id'     => $cat->category_id,
            'hall_id'         => $hall->hall_id,
            'languages'       => 'en,fil',
            'storyline_order' => 1,
            'image'           => 'siege.jpg',
            'status'          => true,
            'date_published'  => '1898-06-27',
        ]);
    }

    private function token(Visitor $v): array
    {
        return ['Authorization' => 'Bearer ' . $v->issueToken()['token']];
    }

    // -- The gate ------------------------------------------------------------

    public function test_no_token_is_401_in_the_shape_the_app_expects(): void
    {
        $this->getJson('/api/v1/exhibits')
            ->assertStatus(401)
            ->assertJson(['error' => 'unauthenticated']);
    }

    public function test_a_garbage_or_expired_token_is_401(): void
    {
        $this->getJson('/api/v1/exhibits', ['Authorization' => 'Bearer not-a-token'])->assertStatus(401);

        $v = Visitor::factory()->paid()->create();
        $h = $this->token($v);
        $v->forceFill(['token_expires_at' => now()->subMinute()])->save();

        $this->getJson('/api/v1/exhibits', $h)->assertStatus(401);
    }

    public function test_an_unpaid_tourist_is_403_and_told_to_pay(): void
    {
        $v = Visitor::factory()->create();

        $this->getJson('/api/v1/exhibits', $this->token($v))
            ->assertStatus(403)
            ->assertJsonPath('error', 'not_cleared')
            ->assertJsonPath('clearance', 'pending_payment')
            ->assertJsonPath('visitor.cleared', false)
            ->assertJsonMissingPath('visitor.password');
    }

    public function test_an_unverified_local_is_403_and_told_to_show_an_id(): void
    {
        $v = Visitor::factory()->local()->create();

        $this->getJson('/api/v1/exhibits', $this->token($v))
            ->assertStatus(403)
            ->assertJsonPath('clearance', 'pending_id');
    }

    public function test_a_member_of_an_unpaid_group_waits_on_the_group(): void
    {
        $group = VisitGroup::create([
            'group_name' => 'Baler Central School', 'group_type' => 'School', 'visitor_type' => 'Tourist',
            'contact_name' => 'Ms Reyes', 'headcount' => 30, 'paying_count' => 30, 'total_fee' => 1500,
            'payment_status' => 'Unpaid', 'visit_date' => today(), 'join_code' => 'ABCDEF',
        ]);
        $v = Visitor::factory()->create(['group_id' => $group->group_id, 'payment_status' => 'Free', 'admission_fee' => 0]);

        $this->getJson('/api/v1/exhibits', $this->token($v))
            ->assertStatus(403)
            ->assertJsonPath('clearance', 'pending_group')
            ->assertJsonPath('visitor.group.label', 'Baler Central School');

        $group->update(['payment_status' => 'Paid']);

        $this->getJson('/api/v1/exhibits', $this->token($v->fresh()))->assertOk();
    }

    // -- The content ---------------------------------------------------------

    public function test_the_list_carries_the_shape_the_app_renders(): void
    {
        $e = $this->exhibit();
        $this->exhibit(['name' => 'Retired', 'status' => false]);
        ExhibitTranslation::create(['exhibit_id' => $e->exhibit_id, 'language_code' => 'fil', 'language_label' => 'Filipino', 'title' => 'Diorama ng Pagkubkob', 'audio_file' => 'exhibit_1_fil.mp3']);
        $v = Visitor::factory()->paid()->create();

        $res = $this->getJson('/api/v1/exhibits?lang=fil', $this->token($v))->assertOk()->assertJsonCount(1);

        $res->assertJsonPath('0.exhibit_code', $e->exhibit_code)
            ->assertJsonPath('0.name', 'Diorama ng Pagkubkob')          // translated
            ->assertJsonPath('0.description', 'Three hundred and thirty-seven days.') // falls back
            ->assertJsonPath('0.fun_facts', ['First fact', 'Second fact'])
            ->assertJsonPath('0.category', 'History')
            ->assertJsonPath('0.hall', 'Hall A')
            ->assertJsonPath('0.floor', 'Ground Floor')
            ->assertJsonPath('0.languages', ['en', 'fil'])
            ->assertJsonPath('0.year', '1898')
            ->assertJsonPath('0.image', '/images/exhibits/siege.jpg')
            ->assertJsonPath('0.audio_url', '/audio/exhibit_1_fil.mp3');
    }

    public function test_one_exhibit_by_code_adds_gallery_next_and_scan_count(): void
    {
        $first  = $this->exhibit(['storyline_order' => 1]);
        $second = $this->exhibit(['storyline_order' => 2, 'name' => 'Church Model']);
        ExhibitImage::create(['exhibit_id' => $first->exhibit_id, 'filename' => 'b.jpg', 'caption' => 'B', 'sort_order' => 2]);
        ExhibitImage::create(['exhibit_id' => $first->exhibit_id, 'filename' => 'a.jpg', 'caption' => 'A', 'sort_order' => 1]);
        $v = Visitor::factory()->paid()->create();

        $this->getJson('/api/v1/exhibits/' . $first->exhibit_code, $this->token($v))
            ->assertOk()
            ->assertJsonPath('exhibit_id', $first->exhibit_id)
            ->assertJsonPath('original_name', 'Siege of Baler Diorama')
            ->assertJsonPath('gallery.0.url', '/images/exhibits/a.jpg')
            ->assertJsonPath('gallery.1.caption', 'B')
            ->assertJsonPath('next_id', $second->exhibit_id)
            ->assertJsonPath('scan_count', 0);
    }

    public function test_an_unknown_code_is_a_200_with_not_found_not_a_404(): void
    {
        // The app tells "no such label" apart from "server unreachable" by
        // this: a 200 carrying {error} is a wrong code, anything else is the
        // offline notice.
        $v = Visitor::factory()->paid()->create();

        $this->getJson('/api/v1/exhibits/EXH-999', $this->token($v))
            ->assertOk()
            ->assertJson(['error' => 'not_found']);
    }

    public function test_the_list_is_revalidated_with_an_etag_not_served_stale(): void
    {
        $this->exhibit();
        $v = Visitor::factory()->paid()->create();
        $h = $this->token($v);

        $first = $this->getJson('/api/v1/exhibits', $h)->assertOk();
        $etag  = $first->headers->get('ETag');

        $this->assertNotEmpty($etag);
        $this->assertStringContainsString('no-cache', $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $first->headers->get('Cache-Control'));

        $this->getJson('/api/v1/exhibits', $h + ['If-None-Match' => $etag])->assertStatus(304);
    }
}
