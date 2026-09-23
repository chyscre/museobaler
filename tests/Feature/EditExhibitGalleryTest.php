<?php

namespace Tests\Feature;

use App\Models\Exhibit;
use App\Models\ExhibitImage;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Gallery pictures in the exhibit edit modal.
 *
 * The x on a picture used to be its own form nested inside the exhibit
 * form, which browsers flatten - so the picture went, or the wrong form
 * submitted, before anyone pressed Save. Now the x only marks the picture
 * and the removal (and any new pictures) travel with Save Changes.
 */
class EditExhibitGalleryTest extends TestCase
{
    use RefreshDatabase;

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $f) {
            if (is_file($f)) @unlink($f);
        }
        parent::tearDown();
    }

    private function staff()
    {
        return $this->withHeader('User-Agent', self::LAPTOP)
            ->actingAs(Staff::factory()->administrator()->create());
    }

    private function exhibitWithPictures(int $n = 2): Exhibit
    {
        $exhibit = Exhibit::create(['exhibit_code' => 'EXH-001', 'name' => 'Church', 'description' => 'd', 'status' => true]);
        $dir = public_path('images/exhibits');
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        for ($i = 1; $i <= $n; $i++) {
            $file = "test_gallery_{$i}_" . uniqid() . '.jpg';
            file_put_contents("$dir/$file", 'jpg');
            $this->written[] = "$dir/$file";
            ExhibitImage::create(['exhibit_id' => $exhibit->exhibit_id, 'filename' => $file, 'sort_order' => $i]);
        }
        return $exhibit;
    }

    private function rememberUploads(Exhibit $exhibit): void
    {
        foreach ($exhibit->images()->get() as $img) {
            $this->written[] = public_path('images/exhibits/' . $img->filename);
        }
        $this->written[] = public_path('images/qr/' . $exhibit->fresh()->qr_file);
    }

    public function test_the_edit_form_has_no_nested_forms_and_no_immediate_delete(): void
    {
        $exhibit = $this->exhibitWithPictures();

        $html = $this->staff()->get(route('exhibits.modal.edit', $exhibit))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<form'), 'the edit modal must be a single form');
        $this->assertStringNotContainsString(route('exhibits.gallery.destroy', $exhibit->images->first()), $html);
        $this->assertStringContainsString('name="remove_images[]"', $html);
        $this->assertStringContainsString('name="gallery_images[]"', $html);
    }

    public function test_saving_without_marking_anything_keeps_every_picture(): void
    {
        $exhibit = $this->exhibitWithPictures();

        $this->staff()->put(route('exhibits.update', $exhibit), [
            'exhibit_code' => 'EXH-001', 'name' => 'Church', 'description' => 'changed',
        ])->assertRedirect(route('exhibits.index'));
        $this->rememberUploads($exhibit);

        $this->assertSame(2, $exhibit->images()->count());
        $this->assertSame('changed', $exhibit->fresh()->description);
    }

    public function test_marked_pictures_go_only_when_the_form_is_saved(): void
    {
        $exhibit = $this->exhibitWithPictures();
        [$keep, $drop] = $exhibit->images->all();
        $dropPath = public_path('images/exhibits/' . $drop->filename);

        $this->staff()->put(route('exhibits.update', $exhibit), [
            'exhibit_code'  => 'EXH-001', 'name' => 'Church',
            'remove_images' => [$drop->image_id],
        ])->assertRedirect();
        $this->rememberUploads($exhibit);

        $this->assertDatabaseMissing('exhibit_images', ['image_id' => $drop->image_id]);
        $this->assertDatabaseHas('exhibit_images', ['image_id' => $keep->image_id]);
        $this->assertFileDoesNotExist($dropPath);
    }

    public function test_another_exhibits_picture_cannot_be_removed_through_this_form(): void
    {
        $mine  = $this->exhibitWithPictures(1);
        $other = Exhibit::create(['exhibit_code' => 'EXH-002', 'name' => 'Other', 'description' => 'd', 'status' => true]);
        $theirs = ExhibitImage::create(['exhibit_id' => $other->exhibit_id, 'filename' => 'nope.jpg', 'sort_order' => 0]);

        $this->staff()->put(route('exhibits.update', $mine), [
            'exhibit_code'  => 'EXH-001', 'name' => 'Church',
            'remove_images' => [$theirs->image_id],
        ])->assertRedirect();
        $this->rememberUploads($mine);

        $this->assertDatabaseHas('exhibit_images', ['image_id' => $theirs->image_id]);
    }

    public function test_new_gallery_pictures_upload_with_save(): void
    {
        $exhibit = $this->exhibitWithPictures(1);

        $this->staff()->put(route('exhibits.update', $exhibit), [
            'exhibit_code'    => 'EXH-001', 'name' => 'Church',
            'gallery_images'  => [UploadedFile::fake()->image('side.jpg', 300, 200)],
            'gallery_caption' => 'Side view',
        ])->assertRedirect();
        $this->rememberUploads($exhibit);

        $this->assertSame(2, $exhibit->images()->count());
        $added = $exhibit->images()->orderByDesc('image_id')->first();
        $this->assertSame('Side view', $added->caption);
        $this->assertFileExists(public_path('images/exhibits/' . $added->filename));
    }
}
