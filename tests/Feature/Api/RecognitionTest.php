<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Exhibit;
use App\Models\MuseumHall;
use App\Models\Scan;
use App\Models\Visitor;
use App\Services\ImageSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Point-and-identify by perceptual hash.
 *
 * Two of the seeded exhibit photos, copied in under test names, and a
 * camera frame that is a re-encoded copy of one of them. The matcher has
 * to pick that one with confidence high enough to open it, log the scan to
 * the token's visitor, and rank the other below it.
 */
class RecognitionTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        File::delete($this->files);
        File::delete(ImageSearch::cachePath());
        parent::tearDown();
    }

    /** A copy of a seed photo under a test name, so a real install is untouched. */
    private function seedPhoto(string $name, string $source): string
    {
        $path = public_path('images/exhibits/' . $name);
        File::ensureDirectoryExists(dirname($path));
        File::copy(database_path('seeders/media/images/' . $source), $path);
        $this->files[] = $path;

        return $path;
    }

    private function siege(): string
    {
        return $this->seedPhoto('_test_siege.jpeg', 'Siege_of_Baler_Diorama_1776872245.jpeg');
    }

    private function bay(): string
    {
        return $this->seedPhoto('_test_bay.jpg', 'Baler_Bay_and_the_Pacific_Coast_1776873790.jpg');
    }

    private function exhibit(string $code, string $image): Exhibit
    {
        $hall = MuseumHall::firstOrCreate(['name' => 'Hall A'], ['floor' => 'Ground Floor', 'sort_order' => 1]);
        $cat  = Category::firstOrCreate(['name' => 'History']);

        return Exhibit::create([
            'exhibit_code' => $code, 'name' => "Exhibit $code", 'description' => '-',
            'category_id' => $cat->category_id, 'hall_id' => $hall->hall_id, 'languages' => 'en',
            'storyline_order' => 1, 'image' => $image, 'status' => true,
        ]);
    }

    private function frameOf(string $path): UploadedFile
    {
        // A fresh JPEG encode of the same picture, as a phone camera would
        // hand over: not byte-identical, visually the same.
        $src = imagecreatefromjpeg($path);
        $tmp = tempnam(sys_get_temp_dir(), 'frame');
        imagejpeg($src, $tmp, 70);
        imagedestroy($src);

        return new UploadedFile($tmp, 'frame.jpg', 'image/jpeg', null, true);
    }

    public function test_a_frame_of_an_exhibit_photo_finds_that_exhibit_and_logs_the_scan(): void
    {
        $siege = $this->siege();
        $bay   = $this->bay();
        $this->exhibit("EXH-S", basename($siege));
        $this->exhibit("EXH-B", basename($bay));
        $v = Visitor::factory()->paid()->create();

        $res = $this->post('/api/v1/recognition', ['frame' => $this->frameOf($bay)], [
            'Authorization' => 'Bearer ' . $v->issueToken()['token'],
        ])->assertOk();

        $res->assertJsonPath('exhibit_code', 'EXH-B');
        $this->assertGreaterThanOrEqual(ImageSearch::HIGH_CONFIDENCE, $res->json('confidence'));
        $this->assertSame('EXH-B', $res->json('candidates.0.exhibit_code'));

        $this->assertDatabaseHas('scans', ['visitor_id' => $v->visitor_id, 'scan_type' => 'image']);
        $this->assertSame(1, Scan::count());
    }

    public function test_reference_hashes_are_cached_between_requests(): void
    {
        $siege = $this->siege();
        $this->exhibit("EXH-S", basename($siege));
        $v = Visitor::factory()->paid()->create();
        $h = ['Authorization' => 'Bearer ' . $v->issueToken()['token']];

        $this->post('/api/v1/recognition', ['frame' => $this->frameOf($siege)], $h)->assertOk();

        $this->assertFileExists(ImageSearch::cachePath());
        $cache = json_decode(file_get_contents(ImageSearch::cachePath()), true);
        $this->assertCount(1, $cache);
        $this->assertStringContainsString('_test_siege.jpeg', array_key_first($cache));
    }

    public function test_the_gate_applies_and_the_frame_is_validated(): void
    {
        $this->post('/api/v1/recognition', ['frame' => $this->frameOf($this->siege())])->assertStatus(401);

        $v = Visitor::factory()->paid()->create();
        $h = ['Authorization' => 'Bearer ' . $v->issueToken()['token']];

        $this->post('/api/v1/recognition', [], $h)->assertStatus(422)->assertJsonValidationErrors('frame');

        $text = UploadedFile::fake()->create('frame.txt', 10, 'text/plain');
        $this->post('/api/v1/recognition', ['frame' => $text], $h)->assertStatus(422);
    }
}
