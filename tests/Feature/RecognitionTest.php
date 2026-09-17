<?php

namespace Tests\Feature;

use App\Models\Exhibit;
use App\Models\ExhibitTrainingImage;
use App\Models\Staff;
use App\Services\Recognition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Camera recognition, run by the museum.
 *
 * The loop the staff are handed: photograph an exhibit on a phone, press
 * Train on the office computer, and the visitor app picks up the new model.
 * The browser does the training, so what is tested here is everything
 * around it - photos in, dataset out, model files back in, and which of
 * those a phone is allowed to reach.
 */
class RecognitionTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /** Files this test writes under public/, removed afterwards. */
    private array $written = [];
    private ?string $modelBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        // The model directory is a live part of the visitor app. Anything
        // there before the test goes back afterwards, byte for byte.
        $dir = public_path(Recognition::MODEL_DIR);
        if (is_dir($dir)) {
            $this->modelBackup = sys_get_temp_dir() . '/mb_model_backup_' . uniqid();
            mkdir($this->modelBackup);
            foreach (['model.json', 'weights.bin', 'metadata.json'] as $f) {
                if (is_file("$dir/$f")) copy("$dir/$f", "{$this->modelBackup}/$f");
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $f) {
            if (is_file($f)) @unlink($f);
        }
        if ($this->modelBackup) {
            $dir = public_path(Recognition::MODEL_DIR);
            foreach (['model.json', 'weights.bin', 'metadata.json'] as $f) {
                if (is_file("{$this->modelBackup}/$f")) {
                    copy("{$this->modelBackup}/$f", "$dir/$f");
                    @unlink("{$this->modelBackup}/$f");
                } else {
                    @unlink("$dir/$f");
                }
            }
            @rmdir($this->modelBackup);
        }
        parent::tearDown();
    }

    private function on(string $agent): self
    {
        return $this->withHeader('User-Agent', $agent)
            ->actingAs(Staff::factory()->administrator()->create());
    }

    private function exhibit(string $code = 'EXH-001'): Exhibit
    {
        return Exhibit::create(['exhibit_code' => $code, 'name' => 'Baler Church', 'description' => 'd', 'status' => true]);
    }

    private function photo(string $name = 'shot.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 1200, 900);
    }

    private function rememberPhotos(): void
    {
        foreach (ExhibitTrainingImage::all() as $p) {
            $this->written[] = $p->path;
        }
    }

    // -- Photos -----------------------------------------------------------

    public function test_a_phone_can_open_the_photo_page_and_upload_shots(): void
    {
        $exhibit = $this->exhibit();

        $this->on(self::PHONE)->get(route('recognition.photos', $exhibit))
            ->assertOk()
            ->assertSee('Take a photo')
            // Phone chrome, not the desktop sidebar.
            ->assertDontSee('sidebar-nav');

        $res = $this->on(self::PHONE)
            ->withHeader('Accept', 'application/json')
            ->post(route('recognition.photos.upload', $exhibit), ['photos' => [$this->photo('a.jpg'), $this->photo('b.jpg')]]);
        $this->rememberPhotos();

        $res->assertOk()->assertJson(['ok' => true, 'count' => 2]);
        $this->assertSame(2, $exhibit->trainingImages()->count());

        // The photo is shrunk to training size and stored as JPEG, whatever came in.
        $stored = ExhibitTrainingImage::first();
        $this->assertFileExists($stored->path);
        [$w, $h] = getimagesize($stored->path);
        $this->assertLessThanOrEqual(640, max($w, $h));
        $this->assertSame('image/jpeg', mime_content_type($stored->path));
    }

    public function test_background_photos_have_no_exhibit(): void
    {
        $res = $this->on(self::PHONE)
            ->withHeader('Accept', 'application/json')
            ->post(route('recognition.background.upload'), ['photos' => [$this->photo()]]);
        $this->rememberPhotos();

        $res->assertOk();
        $this->assertNull(ExhibitTrainingImage::first()->exhibit_id);
    }

    public function test_a_photo_can_be_removed(): void
    {
        $exhibit = $this->exhibit();
        $this->on(self::LAPTOP)->post(route('recognition.photos.upload', $exhibit), ['photos' => [$this->photo()]]);
        $this->rememberPhotos();
        $photo = ExhibitTrainingImage::first();
        $path  = $photo->path;

        $this->on(self::PHONE)->delete(route('recognition.photo.destroy', $photo))->assertRedirect();

        $this->assertDatabaseMissing('exhibit_training_images', ['training_image_id' => $photo->training_image_id]);
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_non_image_is_refused(): void
    {
        $exhibit = $this->exhibit();
        $this->on(self::LAPTOP)
            ->post(route('recognition.photos.upload', $exhibit), ['photos' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')]])
            ->assertSessionHasErrors('photos.0');
        $this->assertSame(0, ExhibitTrainingImage::count());
    }

    // -- Training plumbing ------------------------------------------------

    public function test_the_dataset_names_classes_by_exhibit_code_and_ends_with_background(): void
    {
        $a = $this->exhibit('EXH-001');
        $b = $this->exhibit('EXH-002');
        Exhibit::create(['exhibit_code' => 'EXH-999', 'name' => 'Archived', 'description' => 'd', 'status' => false]);

        $this->on(self::LAPTOP)->post(route('recognition.photos.upload', $a), ['photos' => [$this->photo()]]);
        $this->on(self::LAPTOP)->post(route('recognition.background.upload'), ['photos' => [$this->photo()]]);
        $this->rememberPhotos();

        $json = $this->on(self::LAPTOP)->getJson(route('recognition.dataset'))->assertOk()->json();

        $labels = array_column($json['classes'], 'label');
        $this->assertSame(['EXH-001', 'EXH-002', Recognition::BACKGROUND], $labels);
        $this->assertCount(1, $json['classes'][0]['photos']);
        $this->assertCount(0, $json['classes'][1]['photos']);
        $this->assertCount(1, $json['classes'][2]['photos']);
        $this->assertNotContains('EXH-999', $labels);
    }

    public function test_a_trained_model_is_written_where_the_visitor_app_loads_it(): void
    {
        $this->exhibit('EXH-001');

        $model = json_encode(['modelTopology' => ['class_name' => 'Sequential'], 'weightsManifest' => [['paths' => ['./weights.bin'], 'weights' => []]]]);
        $meta  = json_encode(['labels' => ['EXH-001', 'Background'], 'timeStamp' => '2026-09-16T10:00:00.000Z', 'imageSize' => 224]);

        $this->on(self::LAPTOP)
            ->withHeader('Accept', 'application/json')
            ->post(route('recognition.model.save'), [
                'model_json'    => UploadedFile::fake()->createWithContent('model.json', $model),
                'weights'       => UploadedFile::fake()->createWithContent('weights.bin', "\x00\x01\x02"),
                'metadata_json' => UploadedFile::fake()->createWithContent('metadata.json', $meta),
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'labels' => ['EXH-001', 'Background']]);

        $dir = public_path(Recognition::MODEL_DIR);
        $this->assertSame($model, file_get_contents("$dir/model.json"));
        $this->assertSame("\x00\x01\x02", file_get_contents("$dir/weights.bin"));
        $this->assertFileDoesNotExist("$dir/model.json.tmp");

        $current = Recognition::currentModel();
        $this->assertSame(['EXH-001', 'Background'], $current['labels']);
        $this->assertSame('2026-09-16', $current['trained_at']->toDateString());

        // And the panel reads it back.
        $this->on(self::LAPTOP)->get(route('recognition.index'))
            ->assertOk()
            ->assertSee('Recognition is on');

        $this->assertDatabaseHas('logs', ['action' => 'Recognition model trained']);
    }

    public function test_a_broken_model_never_replaces_the_live_one(): void
    {
        $dir = public_path(Recognition::MODEL_DIR);
        $before = is_file("$dir/model.json") ? file_get_contents("$dir/model.json") : null;

        $this->on(self::LAPTOP)
            ->withHeader('Accept', 'application/json')
            ->post(route('recognition.model.save'), [
                'model_json'    => UploadedFile::fake()->createWithContent('model.json', '{"nope":true}'),
                'weights'       => UploadedFile::fake()->createWithContent('weights.bin', 'x'),
                'metadata_json' => UploadedFile::fake()->createWithContent('metadata.json', '{"labels":["A","B"]}'),
            ])
            ->assertStatus(422);

        $after = is_file("$dir/model.json") ? file_get_contents("$dir/model.json") : null;
        $this->assertSame($before, $after);
    }

    // -- Where each screen may be used --------------------------------------

    public function test_the_panel_flags_exhibits_the_model_does_not_know(): void
    {
        $this->exhibit('EXH-001');
        $this->exhibit('EXH-002');
        Recognition::writeModel('{"modelTopology":{},"weightsManifest":[]}', 'x', json_encode(['labels' => ['EXH-001', 'Background']]));

        $this->on(self::LAPTOP)->get(route('recognition.index'))
            ->assertOk()
            ->assertSee('1 exhibit(s) are not in the model');
    }

    public function test_the_training_panel_stays_on_the_desk(): void
    {
        $this->on(self::PHONE)->get(route('recognition.index'))->assertRedirect(route('my.attendance'));
        $this->on(self::LAPTOP)->get(route('recognition.index'))->assertOk();
    }

    public function test_tourism_is_shut_out(): void
    {
        $exhibit = $this->exhibit();
        $this->withHeader('User-Agent', self::LAPTOP)
            ->actingAs(Staff::factory()->tourismHead()->create())
            ->get(route('recognition.photos', $exhibit))
            ->assertForbidden();
    }
}
