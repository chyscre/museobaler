<?php

namespace App\Support;

/**
 * Web-sized copies of the exhibit pictures.
 *
 * The pictures come off a camera at 6000x4000 and up to 9.8 MB each. Served as
 * uploaded, a single exhibit page was pushing ten megabytes down a phone's
 * mobile data before the first pixel appeared — which is what "images take too
 * long to load" was.
 *
 * So each original keeps a pair of derivatives beside it:
 *
 *   images/exhibits/<name>.jpg           the untouched original, kept as the
 *                                        archive copy and never served to the app
 *   images/exhibits/display/<name>.webp  ~1280px, for the exhibit detail view
 *   images/exhibits/thumb/<name>.webp    ~400px,  for list rows, cards and grids
 *
 * The derivatives are WebP: at the same visual quality a WebP photograph is
 * roughly a third smaller than the JPEG this used to write, which over mobile
 * data on a grid of forty cards is the difference a visitor feels. A GD built
 * without WebP writes JPEG instead, and variantPath() accepts either, so the
 * .jpg derivatives already on disk keep working until they are rebuilt.
 *
 * Both are derived, so they can be deleted and rebuilt at any time
 * (`php artisan exhibits:thumbs --force`). Anything that cannot be rebuilt —
 * an original in a format GD was not compiled for, say — simply falls back to
 * the original, so a missing derivative degrades to "slow" and never to
 * "broken image".
 */
class ExhibitImage
{
    public const DIR     = 'images/exhibits';
    public const DISPLAY = 'display';
    public const THUMB   = 'thumb';

    /** Longest edge, in pixels, and encoder quality for each variant. */
    private const SIZES = [
        self::DISPLAY => [1280, 78],
        self::THUMB   => [400, 74],
    ];

    /**
     * The public path for a variant, falling back to the original when the
     * derivative is not on disk - and to null when neither one is there.
     *
     * That last case is the picture whose row in the database outlived its
     * file: a restored backup, a half-finished upload, a file moved by hand.
     * This used to hand back the original's path regardless, which put a
     * signed URL in front of a 404 - and null is the only answer the app can
     * recognise as "there is no picture" and draw the category placeholder
     * for. Given a URL it assumes a photograph and lays out a black frame
     * around the browser's broken-image glyph.
     *
     * The extra stat costs nothing in the ordinary case: it is reached only
     * when the derivative is missing, which is itself the unusual path.
     */
    public static function variantPath(?string $file, string $variant): ?string
    {
        if (!$file) {
            return null;
        }

        $name = basename($file);

        foreach (static::derivedNames($name) as $derivedName) {
            $derived = self::DIR . '/' . $variant . '/' . $derivedName;

            if (is_file(public_path($derived))) {
                return $derived;
            }
        }

        $original = self::DIR . '/' . $name;

        return is_file(public_path($original)) ? $original : null;
    }

    /**
     * The name a derivative is written under: WebP where GD can encode it,
     * JPEG otherwise. Never PNG - the originals are photographs.
     */
    public static function derivedName(string $name): string
    {
        return pathinfo($name, PATHINFO_FILENAME) . (static::webpAvailable() ? '.webp' : '.jpg');
    }

    /**
     * Every name a derivative may be on disk under, preferred first. The
     * .jpg is what this wrote before the switch to WebP.
     */
    public static function derivedNames(string $name): array
    {
        $base = pathinfo($name, PATHINFO_FILENAME);

        return [$base . '.webp', $base . '.jpg'];
    }

    public static function webpAvailable(): bool
    {
        return function_exists('imagewebp');
    }

    /**
     * Build both derivatives for one original. Returns true when at least one
     * was written. Safe to call repeatedly; skips work that is already done
     * unless $force.
     */
    public static function generate(string $name, bool $force = false): bool
    {
        $src = public_path(self::DIR . '/' . basename($name));
        if (!is_file($src) || !function_exists('imagecreatetruecolor')) {
            return false;
        }

        $info = @getimagesize($src);
        if (!$info) {
            return false;
        }

        $made = false;
        foreach (self::SIZES as $variant => [$maxEdge, $quality]) {
            $dir = public_path(self::DIR . '/' . $variant);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $out = $dir . '/' . static::derivedName(basename($name));
            if (!$force && is_file($out) && filemtime($out) >= filemtime($src)) {
                continue;
            }

            $im = static::open($src, $info['mime'] ?? '');
            if (!$im) {
                return false;
            }

            [$w, $h] = [imagesx($im), imagesy($im)];
            $scale = min(1, $maxEdge / max($w, $h));   // never upscale
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));

            $dst = imagecreatetruecolor($nw, $nh);
            // Photographs, flattened onto white: a transparent PNG would
            // otherwise come out with a black background as a JPEG.
            imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            if (static::webpAvailable()) {
                imagewebp($dst, $out, $quality);
            } else {
                imagejpeg($dst, $out, $quality);
            }

            imagedestroy($dst);
            imagedestroy($im);
            // The .jpg an earlier build wrote is left where it is: the live
            // release keeps serving it while a deploy builds these, and
            // rollback.sh returns to code that knows only .jpg. forget()
            // removes both when the exhibit goes.
            $made = true;
        }

        return $made;
    }

    /** Remove the derivatives for an original that is being deleted. */
    public static function forget(?string $name): void
    {
        if (!$name) {
            return;
        }
        foreach (array_keys(self::SIZES) as $variant) {
            foreach (static::derivedNames(basename($name)) as $derived) {
                $p = public_path(self::DIR . '/' . $variant . '/' . $derived);
                if (is_file($p)) {
                    @unlink($p);
                }
            }
        }
    }

    private static function open(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/gif'  => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            default      => null,
        } ?: null;
    }
}
