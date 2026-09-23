<?php

namespace App\Console\Commands;

use App\Support\ExhibitImage;
use Illuminate\Console\Command;

/**
 * Build the web-sized copies of the exhibit pictures.
 *
 * Uploads generate their own derivatives, so this is for the pictures that were
 * already on disk before that existed, and for rebuilding after the sizes in
 * App\Support\ExhibitImage change.
 */
class BuildExhibitThumbs extends Command
{
    protected $signature = 'exhibits:thumbs {--force : Rebuild derivatives that are already up to date}';

    protected $description = 'Generate display and thumbnail copies of the exhibit images';

    public function handle(): int
    {
        $dir = public_path(ExhibitImage::DIR);
        if (!is_dir($dir)) {
            $this->error('No ' . ExhibitImage::DIR . ' directory.');
            return self::FAILURE;
        }

        $originals = array_values(array_filter(
            scandir($dir),
            fn ($f) => is_file($dir . '/' . $f)
                && preg_match('/\.(jpe?g|png|gif|webp)$/i', $f)
        ));

        if (!$originals) {
            $this->info('No exhibit images found.');
            return self::SUCCESS;
        }

        $force  = (bool) $this->option('force');
        $made   = 0;
        $failed = [];
        $before = 0;
        $after  = 0;

        $bar = $this->output->createProgressBar(count($originals));
        $bar->start();

        foreach ($originals as $name) {
            $before += filesize($dir . '/' . $name);

            if (ExhibitImage::generate($name, $force)) {
                $made++;
            } elseif (!is_file($dir . '/' . ExhibitImage::DISPLAY . '/' . ExhibitImage::derivedName($name))) {
                $failed[] = $name;
            }

            $display = $dir . '/' . ExhibitImage::DISPLAY . '/' . ExhibitImage::derivedName($name);
            $after  += is_file($display) ? filesize($display) : filesize($dir . '/' . $name);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $mb = fn ($b) => number_format($b / 1048576, 1) . ' MB';
        $this->info(sprintf(
            '%d/%d built. What the app now downloads for these: %s (was %s).',
            $made, count($originals), $mb($after), $mb($before)
        ));

        if ($failed) {
            $this->warn('Could not resize (the originals will still be served): ' . implode(', ', $failed));
        }

        return self::SUCCESS;
    }
}
