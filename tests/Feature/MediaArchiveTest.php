<?php

namespace Tests\Feature;

use App\Support\BackupCipher;
use App\Support\MediaArchive;
use Illuminate\Support\Facades\File;
use PharData;
use Tests\TestCase;

/**
 * The media half of the nightly backup.
 *
 * The dump needs a real MySQL server; the tarball does not, so its shape is
 * pinned here: paths relative to public/ (a restore is one tar command),
 * the .gitkeep placeholders left out, an empty museum producing no file at
 * all, and the result opening with the same key as the dump.
 */
class MediaArchiveTest extends TestCase
{
    private string $root;
    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('app/testing/media-root-' . uniqid());
        $this->out  = storage_path('app/testing/media-out-' . uniqid());
        File::ensureDirectoryExists($this->root . '/images/exhibits/gallery');
        File::ensureDirectoryExists($this->root . '/audio');
        File::ensureDirectoryExists($this->out);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory($this->out);
        parent::tearDown();
    }

    public function test_paths_inside_are_relative_to_the_root(): void
    {
        File::put($this->root . '/images/exhibits/siege.jpg', 'jpg');
        File::put($this->root . '/images/exhibits/gallery/detail.jpg', 'jpg');
        File::put($this->root . '/audio/siege_en.mp3', 'mp3');
        File::put($this->root . '/audio/.gitkeep', '');

        $gz = MediaArchive::pack(['images/exhibits', 'audio'], $this->root, $this->out . '/x-media.tar');

        $this->assertSame($this->out . '/x-media.tar.gz', $gz);
        $this->assertFileExists($gz);
        $this->assertFileDoesNotExist($this->out . '/x-media.tar');

        $names = [];
        foreach (new \RecursiveIteratorIterator(new PharData($gz)) as $file) {
            $names[] = substr($file->getPathname(), strpos($file->getPathname(), '.tar.gz/') + 8);
        }
        sort($names);

        $this->assertSame([
            'audio/siege_en.mp3',
            'images/exhibits/gallery/detail.jpg',
            'images/exhibits/siege.jpg',
        ], $names);
    }

    public function test_an_empty_museum_writes_no_file(): void
    {
        File::put($this->root . '/audio/.gitkeep', '');

        $gz = MediaArchive::pack(['images/exhibits', 'audio', 'images/missing'], $this->root, $this->out . '/x-media.tar');

        $this->assertNull($gz);
        $this->assertFileDoesNotExist($this->out . '/x-media.tar');
        $this->assertFileDoesNotExist($this->out . '/x-media.tar.gz');
    }

    public function test_the_tarball_round_trips_through_the_backup_cipher(): void
    {
        File::put($this->root . '/audio/guide.mp3', str_repeat('m', 5000));

        $gz  = MediaArchive::pack(['audio'], $this->root, $this->out . '/x-media.tar');
        $key = base64_decode(BackupCipher::generateKey());
        $enc = BackupCipher::encrypt($gz, "{$gz}.enc", $key);
        $dec = BackupCipher::decrypt($enc, $this->out . '/back.tar.gz', $key);

        $this->assertFileEquals($gz, $dec);
        $this->assertSame(str_repeat('m', 5000), file_get_contents('phar://' . $dec . '/audio/guide.mp3'));
    }
}
