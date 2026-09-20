<?php

namespace Tests\Feature;

use App\Models\Exhibit;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Adding an exhibit from the panel.
 *
 * Two things used to go wrong here and both looked like "the button does
 * nothing". A refused save bounced back to a closed modal with the reason
 * swallowed, and an accepted save put the picture behind a public/storage
 * symlink that pointed at another machine, so the card came up with a broken
 * image on both the panel and the visitor app.
 */
class AddExhibitTest extends TestCase
{
    use RefreshDatabase;

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /** Files this test drops into public/, cleaned up afterwards. */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            '_form'           => 'add_exhibit',
            'exhibit_code'    => 'EXH-009',
            'name'            => 'Baler Surfing Heritage',
            'hall_id'         => '',
            'languages'       => 'Filipino,English',
            'storyline_order' => 9,
            'category_id'     => '',
        ], $overrides);
    }

    private function asMuseumStaff()
    {
        return $this->withHeader('User-Agent', self::LAPTOP)
            ->actingAs(Staff::factory()->administrator()->create())
            ->from('/exhibits');
    }

    public function test_museum_staff_can_add_an_exhibit_and_it_is_live_for_visitors(): void
    {
        $this->asMuseumStaff()->post('/exhibits', $this->form())
            ->assertRedirect('/exhibits')
            ->assertSessionHas('success');

        $exhibit = Exhibit::where('exhibit_code', 'EXH-009')->first();
        $this->assertNotNull($exhibit);
        // status = 1 is exactly what the visitor API filters on
        $this->assertTrue($exhibit->status);
        $this->assertSame('Baler Surfing Heritage', $exhibit->name);
    }

    public function test_the_picture_is_stored_where_both_screens_read_it(): void
    {
        $this->asMuseumStaff()->post('/exhibits', $this->form([
            'image' => UploadedFile::fake()->image('surf.jpg', 640, 480),
        ]))->assertSessionHas('success');

        $exhibit = Exhibit::where('exhibit_code', 'EXH-009')->firstOrFail();
        $this->written[] = $stored = public_path('images/exhibits/' . $exhibit->image);

        $this->assertFileExists($stored);
        $this->assertStringContainsString('/exhibit-image/', $exhibit->image_url);
    }

    public function test_a_duplicate_code_is_refused_with_the_reason_shown_in_the_modal(): void
    {
        Exhibit::create(['exhibit_code' => 'EXH-009', 'name' => 'Already here']);

        $this->asMuseumStaff()->post('/exhibits', $this->form())
            ->assertRedirect('/exhibits')
            ->assertSessionHasErrors('exhibit_code');

        $this->assertSame(1, Exhibit::count());

        // The page that comes back must say why, and reopen the form with
        // what was typed still in it.
        $page = $this->withHeader('User-Agent', self::LAPTOP)
            ->actingAs(Staff::factory()->administrator()->create())
            ->withSession(['errors' => $this->errorBag(['exhibit_code' => 'That exhibit code is already used by another exhibit. Pick a different one.']), '_old_input' => $this->form()])
            ->get('/exhibits');

        $page->assertOk();
        $page->assertSee('The exhibit was not saved.');
        $page->assertSee('already used by another exhibit');
        $page->assertSee('value="Baler Surfing Heritage"', false);
        $page->assertSee("document.getElementById('addModal').classList.add('open');", false);
    }

    public function test_an_oversized_or_wrong_type_picture_is_refused_in_plain_words(): void
    {
        $this->asMuseumStaff()->post('/exhibits', $this->form([
            'image' => UploadedFile::fake()->create('photo.heic', 200, 'image/heic'),
        ]))->assertSessionHasErrors(['image' => 'The picture must be an image file (JPG, PNG, GIF or WebP).']);

        $this->asMuseumStaff()->post('/exhibits', $this->form([
            'image' => UploadedFile::fake()->image('huge.jpg')->size(10241),
        ]))->assertSessionHasErrors(['image' => 'The picture is too large. Keep it under 10 MB.']);

        $this->assertSame(0, Exhibit::count());
    }

    public function test_the_tourism_office_cannot_add_exhibits(): void
    {
        $this->withHeader('User-Agent', self::LAPTOP)
            ->actingAs(Staff::factory()->tourismHead()->create())
            ->post('/exhibits', $this->form())
            ->assertForbidden();
    }

    private function errorBag(array $messages): \Illuminate\Support\ViewErrorBag
    {
        $bag = new \Illuminate\Support\ViewErrorBag();
        $bag->put('default', new \Illuminate\Support\MessageBag($messages));

        return $bag;
    }
}
