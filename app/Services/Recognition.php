<?php

namespace App\Services;

use App\Models\Exhibit;
use App\Models\ExhibitTrainingImage;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Point-and-identify, run by the museum.
 *
 * The visitor app recognises exhibits with a small image classifier it loads
 * from public/visitor/model/ - three files, produced by training. That
 * training used to happen on Google's Teachable Machine site with the export
 * copied onto the server by hand, which is a developer's job. This class and
 * RecognitionController move the whole loop into the panel: staff photograph
 * each exhibit, press Train, and the browser writes the same three files
 * back here. The visitor app does not know the difference.
 *
 * Two things live on disk:
 *   images/training/   the photos, one row each in exhibit_training_images
 *   visitor/model/     model.json, weights.bin, metadata.json
 */
class Recognition
{
    public const DIR       = 'images/training';
    public const MODEL_DIR = 'visitor/model';

    /** Class name for the not-an-exhibit set. The visitor app ignores it. */
    public const BACKGROUND = 'Background';

    /** Below this a class is flagged as too thin to recognise reliably. */
    public const MIN_PHOTOS  = 20;
    /** From here on the count reads as healthy. */
    public const GOOD_PHOTOS = 30;

    /**
     * Training photos are only ever seen at 224px, so the longest side is
     * capped here. Keeps a 4 MB phone shot to ~60 KB and makes the training
     * page load a few hundred of them in seconds instead of minutes.
     */
    private const MAX_SIDE = 640;

    /** Store one photo, returning the filename the row keeps. */
    public static function storePhoto(UploadedFile $file, ?Exhibit $exhibit): string
    {
        $dir = public_path(self::DIR);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $img = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (!$img) {
            throw new RuntimeException('That file is not an image the server can read.');
        }

        $img = self::upright($img, $file->getRealPath());
        $img = self::shrink($img);

        $label = $exhibit ? $exhibit->exhibit_code : self::BACKGROUND;
        $name  = Str::slug($label) . '_' . time() . '_' . Str::lower(Str::random(6)) . '.jpg';

        imagejpeg($img, $dir . '/' . $name, 85);
        imagedestroy($img);

        return $name;
    }

    public static function deletePhoto(ExhibitTrainingImage $photo): void
    {
        $path = $photo->path;
        if (is_file($path)) {
            @unlink($path);
        }
        $photo->delete();
    }

    /**
     * What is on disk right now: when it was trained and which classes it
     * knows. Null when there is no model, in which case the visitor app is
     * on the pHash fallback.
     */
    public static function currentModel(): ?array
    {
        $meta = public_path(self::MODEL_DIR . '/metadata.json');
        $json = public_path(self::MODEL_DIR . '/model.json');
        if (!is_file($meta) || !is_file($json)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($meta), true);
        if (!is_array($data) || !is_array($data['labels'] ?? null)) {
            return null;
        }

        $when = null;
        try {
            $when = !empty($data['timeStamp']) ? Carbon::parse($data['timeStamp']) : Carbon::createFromTimestamp(filemtime($meta));
        } catch (\Throwable) {
        }

        return [
            'trained_at' => $when,
            'labels'     => array_values(array_map('strval', $data['labels'])),
        ];
    }

    /**
     * Write the three files the browser trained. Each lands beside the live
     * one under a temporary name and is renamed into place at the end, so a
     * visitor who loads the model mid-write never gets half a model.
     */
    public static function writeModel(string $modelJson, string $weights, string $metadataJson): void
    {
        $dir = public_path(self::MODEL_DIR);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $files = [
            'model.json'    => $modelJson,
            'weights.bin'   => $weights,
            'metadata.json' => $metadataJson,
        ];

        $staged = [];
        foreach ($files as $name => $contents) {
            $tmp = $dir . '/' . $name . '.tmp';
            if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
                foreach ($staged as $s) @unlink($s);
                throw new RuntimeException("Could not write $name.");
            }
            $staged[$name] = $tmp;
        }

        foreach ($staged as $name => $tmp) {
            if (!rename($tmp, $dir . '/' . $name)) {
                throw new RuntimeException("Could not replace $name.");
            }
        }
    }

    /**
     * Phone cameras record the orientation in EXIF rather than rotating the
     * pixels. GD ignores it, so without this a portrait shot trains sideways.
     */
    private static function upright(\GdImage $img, string $path)
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $o = (int) ($exif['Orientation'] ?? 1);

        $out = match ($o) {
            3       => imagerotate($img, 180, 0),
            6       => imagerotate($img, -90, 0),
            8       => imagerotate($img, 90, 0),
            default => null,
        };
        if ($out === null || $out === false) {
            return $img;
        }
        imagedestroy($img);
        return $out;
    }

    private static function shrink(\GdImage $img)
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $longest = max($w, $h);
        if ($longest <= self::MAX_SIDE) {
            return $img;
        }
        $scale = self::MAX_SIDE / $longest;
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $out = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        return $out;
    }
}
