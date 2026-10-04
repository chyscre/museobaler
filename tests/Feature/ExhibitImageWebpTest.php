<?php

namespace Tests\Feature;

use App\Support\ExhibitImage;
use Tests\TestCase;

/**
 * The web-sized copies are WebP, and the .jpg copies an earlier build left on
 * disk keep being served until a WebP one exists.
 */
class ExhibitImageWebpTest extends TestCase
{
    private string $name;

    protected function setUp(): void
    {
        parent::setUp();

        if (!ExhibitImage::webpAvailable()) {
            $this->markTestSkipped('This PHP build has no WebP encoder.');
        }

        $this->name = 'webp_test_' . uniqid() . '.jpg';
    }

    protected function tearDown(): void
    {
        ExhibitImage::forget($this->name);
        @unlink(public_path(ExhibitImage::DIR . '/' . $this->name));

        parent::tearDown();
    }

    private function original(int $w, int $h): void
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 120, 80, 40));
        @mkdir(public_path(ExhibitImage::DIR), 0755, true);
        imagejpeg($im, public_path(ExhibitImage::DIR . '/' . $this->name), 90);
        imagedestroy($im);
    }

    public function test_uploads_get_webp_thumbnail_and_display_copies(): void
    {
        $this->original(2000, 1000);

        $this->assertTrue(ExhibitImage::generate($this->name));

        $thumb   = ExhibitImage::variantPath($this->name, ExhibitImage::THUMB);
        $display = ExhibitImage::variantPath($this->name, ExhibitImage::DISPLAY);

        $this->assertStringEndsWith('/thumb/' . pathinfo($this->name, PATHINFO_FILENAME) . '.webp', $thumb);
        $this->assertStringEndsWith('.webp', $display);

        $info = getimagesize(public_path($thumb));
        $this->assertSame('image/webp', $info['mime']);
        $this->assertSame(400, $info[0]);              // longest edge
        $this->assertSame(1280, getimagesize(public_path($display))[0]);
    }

    public function test_an_older_jpeg_thumbnail_is_still_served_until_rebuilt(): void
    {
        $this->original(800, 600);
        $base   = pathinfo($this->name, PATHINFO_FILENAME);
        $legacy = ExhibitImage::DIR . '/' . ExhibitImage::THUMB . '/' . $base . '.jpg';
        @mkdir(dirname(public_path($legacy)), 0755, true);
        copy(public_path(ExhibitImage::DIR . '/' . $this->name), public_path($legacy));

        $this->assertSame($legacy, ExhibitImage::variantPath($this->name, ExhibitImage::THUMB));

        ExhibitImage::generate($this->name);

        $this->assertStringEndsWith('.webp', ExhibitImage::variantPath($this->name, ExhibitImage::THUMB));
    }

    public function test_forgetting_an_image_removes_both_formats(): void
    {
        $this->original(800, 600);
        $base   = pathinfo($this->name, PATHINFO_FILENAME);
        $legacy = public_path(ExhibitImage::DIR . '/' . ExhibitImage::THUMB . '/' . $base . '.jpg');
        @mkdir(dirname($legacy), 0755, true);
        file_put_contents($legacy, 'old');
        ExhibitImage::generate($this->name);

        ExhibitImage::forget($this->name);

        $this->assertFileDoesNotExist($legacy);
        $this->assertFileDoesNotExist(public_path(ExhibitImage::DIR . '/' . ExhibitImage::THUMB . '/' . $base . '.webp'));
    }
}
