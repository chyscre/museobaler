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
use Illuminate\Support\Facades\URL;
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

    /** @var list<string> files written to public/ by a test, removed after it */
    private array $written = [];

    /**
     * Put a real file behind a picture's name.
     *
     * The API now answers null for a picture whose file is not on disk - so
     * that the app draws its category placeholder instead of framing a 404 -
     * which means a test that wants a media URL has to have the file there.
     * Before this, every one of these passed against a name alone.
     */
    private function imageFile(string ...$names): void
    {
        foreach ($names as $name) {
            $path = public_path('images/exhibits/' . $name);
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, 'not really a jpeg, but a real file on disk');
            $this->written[] = $path;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }
        $this->written = [];

        parent::tearDown();
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
        $this->imageFile('siege.jpg');
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
            ->assertJsonPath('0.image', fn (string $url) => str_contains($url, '/api/v1/media/images/exhibits/siege.jpg?'))
            ->assertJsonPath('0.audio_url', fn (string $url) => str_contains($url, '/api/v1/media/audio/exhibit_1_fil.mp3?'));
    }

    public function test_one_exhibit_by_code_adds_gallery_next_and_scan_count(): void
    {
        $first  = $this->exhibit(['storyline_order' => 1]);
        $second = $this->exhibit(['storyline_order' => 2, 'name' => 'Church Model']);
        $this->imageFile('siege.jpg', 'a.jpg', 'b.jpg');
        ExhibitImage::create(['exhibit_id' => $first->exhibit_id, 'filename' => 'b.jpg', 'caption' => 'B', 'sort_order' => 2]);
        ExhibitImage::create(['exhibit_id' => $first->exhibit_id, 'filename' => 'a.jpg', 'caption' => 'A', 'sort_order' => 1]);
        $v = Visitor::factory()->paid()->create();

        $this->getJson('/api/v1/exhibits/' . $first->exhibit_code, $this->token($v))
            ->assertOk()
            ->assertJsonPath('exhibit_id', $first->exhibit_id)
            ->assertJsonPath('original_name', 'Siege of Baler Diorama')
            ->assertJsonPath('gallery.0.url', fn (string $url) => str_contains($url, '/api/v1/media/images/exhibits/a.jpg?'))
            ->assertJsonPath('gallery.1.caption', 'B')
            ->assertJsonPath('next_id', $second->exhibit_id)
            ->assertJsonPath('scan_count', 0);
    }

    public function test_an_unknown_code_is_a_404_with_not_found(): void
    {
        $v = Visitor::factory()->paid()->create();

        $this->getJson('/api/v1/exhibits/EXH-999', $this->token($v))
            ->assertStatus(404)
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

    /**
     * A picture whose file is gone is no picture at all.
     *
     * The app has one way to say "there is nothing to show here" - a null -
     * and draws the category gradient and icon for it. Handed a URL it lays
     * out a photo frame, fills it black and leaves the browser's broken-image
     * glyph in the middle, which is what a restored backup with missing files
     * looked like on every card in the museum.
     */
    public function test_a_picture_with_no_file_behind_it_is_not_offered_at_all(): void
    {
        $this->exhibit(['image' => 'deleted-by-hand.jpg']);
        $v = Visitor::factory()->paid()->create();

        $this->getJson('/api/v1/exhibits', $this->token($v))
            ->assertOk()
            ->assertJsonPath('0.image', null)
            ->assertJsonPath('0.thumb', null);

        // And with the file there, it is.
        $this->imageFile('deleted-by-hand.jpg');

        $this->getJson('/api/v1/exhibits', $this->token($v))
            ->assertOk()
            ->assertJsonPath('0.image', fn (?string $url) => is_string($url) && str_contains($url, 'deleted-by-hand.jpg?'));
    }

    /**
     * A signature is proof of who minted the URL, not of where it points.
     *
     * Tampering with the path invalidates the signature, which is what the
     * test below covers. This covers the other direction: a path that escapes
     * public/ and IS correctly signed - what an attacker who found a way to
     * have one minted would hold, and what a future caller of mediaUrl() with
     * an unsanitised filename would produce by accident. The controller's own
     * whitelist and realpath containment have to answer it, not the
     * signature.
     */
    public function test_a_correctly_signed_url_still_cannot_leave_the_public_folder(): void
    {
        $v = Visitor::factory()->paid()->create();

        foreach ([
            'images/exhibits/../../../.env',
            'images/exhibits/../../.env',
            'audio/../../composer.json',
            '../.env',
            '/etc/passwd',
            'images/exhibits/..%2f..%2f.env',
            'storage/logs/laravel.log',
            'images/exhibits/subdir/../../../../.env',
        ] as $path) {
            $signed = URL::temporarySignedRoute('api.media', now()->addMinutes(30), ['path' => $path], false);

            $res = $this->get($signed, $this->token($v));

            $this->assertContains(
                $res->status(),
                [403, 404],
                "a signed URL for '{$path}' was answered with {$res->status()}",
            );
        }
    }

    public function test_media_urls_reject_a_tampered_signature(): void
    {
        $this->exhibit();
        $this->imageFile('siege.jpg');
        $v = Visitor::factory()->paid()->create();
        $url = $this->getJson('/api/v1/exhibits', $this->token($v))
            ->json('0.image');

        $tampered = preg_replace('/signature=[^&]+/', 'signature=invalid', $url);

        $this->get($tampered)->assertStatus(403);
    }

    /**
     * The other half of the test above, and the half that was missing.
     *
     * Refusing a tampered signature passes whether or not a good one works,
     * so it went green while every real thumbnail in the visitor app came
     * back 403: ExhibitController mints these relative (absolute: false) and
     * the route validated them with plain `signed`, which rebuilds the
     * absolute URL. A relative signature cannot match that, so the app
     * rejected URLs it had signed itself. Nothing logged it - a 403 is an
     * answer, not an error - and it only showed up as pictures that never
     * arrived.
     */
    public function test_a_media_url_the_app_signed_itself_is_served(): void
    {
        $this->exhibit();
        $v = Visitor::factory()->paid()->create();

        $file = public_path('images/exhibits/siege.jpg');
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, 'not really a jpeg, but a real file on disk');

        try {
            $url = $this->getJson('/api/v1/exhibits', $this->token($v))->json('0.image');

            $this->get($url)->assertOk();
        } finally {
            @unlink($file);
        }
    }


    /**
     * Point one directory at another, the way a deploy does.
     *
     * A plain symlink needs a privilege Windows does not hand an ordinary
     * shell, and this bug only ever appeared on the deployed layout - so a
     * test that could only run on Linux would be a test that never ran on the
     * machine the code is written on. A directory junction needs no privilege
     * and PHP resolves it exactly the same way: realpath() follows it out of
     * public/ and is_file() on the near side says true. Either link
     * reproduces this faithfully.
     */
    private function linkDirectory(string $link, string $target): bool
    {
        if (@symlink($target, $link)) {
            return true;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        exec(sprintf('mklink /J %s %s 2>&1', escapeshellarg($link), escapeshellarg($target)), $out, $code);

        return $code === 0 && is_dir($link);
    }

    /**
     * The deployed layout, which is not the layout the other tests run in.
     *
     * deploy/deploy.sh keeps uploaded media in shared/ so it survives a
     * release, and links public/images/exhibits and public/audio at it. Every
     * other test here writes a real file into a real directory, so none of
     * them could see what that does: the controller resolved the file with
     * realpath(), which follows the link straight out of public/, and then
     * required the result to be under public/ anyway. Live, that answered 404
     * for every picture and every audio guide in the museum from behind a
     * signature that was perfectly valid. Here, it reproduces it.
     */
    public function test_media_is_served_when_its_directory_is_a_symlink_into_shared_storage(): void
    {
        $real    = public_path('images/exhibits');
        $shared  = storage_path('framework/testing/shared-exhibits');
        $stashed = $real . '.test-stash';

        @mkdir($shared, 0755, true);
        file_put_contents($shared . '/siege.jpg', 'not really a jpeg, but a real file on disk');

        // Stand the release's own directory aside and link it at the shared
        // one, exactly as a deploy does.
        $hadReal = is_dir($real);
        if ($hadReal) {
            rename($real, $stashed);
        }

        if (!$this->linkDirectory($real, $shared)) {
            if ($hadReal) {
                rename($stashed, $real);
            }
            $this->markTestSkipped('this environment allows neither a symlink nor a junction');
        }

        try {
            $this->exhibit();
            $v = Visitor::factory()->paid()->create();

            $url = $this->getJson('/api/v1/exhibits', $this->token($v))->json('0.image');

            $this->assertNotNull($url, 'the API did not mint a URL for a picture that is on disk');
            $this->get($url)->assertOk();
        } finally {
            // The link, never what it points at: unlink for a symlink, rmdir
            // for a junction. Both leave the shared copy where it is.
            if (!@unlink($real)) {
                @rmdir($real);
            }
            if ($hadReal) {
                rename($stashed, $real);
            }
            @unlink($shared . '/siege.jpg');
            @rmdir($shared);
        }
    }
}
