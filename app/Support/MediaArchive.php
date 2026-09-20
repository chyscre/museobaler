<?php

namespace App\Support;

use FilesystemIterator;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Everything the museum uploaded, in one tarball.
 *
 * The database alone restores a museum with no pictures in it: exhibit
 * photos, gallery images, recognition training shots and the audio guides
 * all live as files under public/, outside any dump. This packs the folders
 * config/backup.php names into a .tar.gz beside the nightly dump.
 *
 * Paths inside the archive are relative to public/, so a restore is one
 * command that drops everything back where the app reads it:
 *
 *     tar -xzf museobaler-<stamp>-media.tar.gz -C public
 *
 * PharData rather than ZipArchive because the zip extension is not on every
 * PHP build this runs on, and Phar is.
 */
class MediaArchive
{
    /**
     * Write $tar (a path ending in .tar) compressed to "$tar.gz" and return
     * that path, or null when the folders held nothing worth backing up.
     *
     * @param  string[]  $directories  Relative to $root, e.g. 'images/exhibits'.
     */
    public static function pack(array $directories, string $root, string $tar): ?string
    {
        $gz = "{$tar}.gz";
        @unlink($tar);
        @unlink($gz);

        $phar  = new PharData($tar);
        $added = 0;

        foreach ($directories as $dir) {
            $dir  = trim($dir, '/\\');
            $base = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $dir;

            if (!is_dir($base)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->getFilename() === '.gitkeep') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($base) + 1);
                $phar->addFile($file->getPathname(), $dir . '/' . str_replace('\\', '/', $relative));
                $added++;
            }
        }

        if ($added === 0) {
            unset($phar);
            @unlink($tar);
            return null;
        }

        $phar->compress(Phar::GZ);
        unset($phar);
        @unlink($tar);

        return $gz;
    }
}
