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
 *   images/exhibits/<name>.jpg          the untouched original, kept as the
 *                                       archive copy and never served to the app
 *   images/exhibits/display/<name>.jpg  ~1280px, for the exhibit hero
 *   images/exhibits/thumb/<name>.jpg    ~400px,  for list rows and cards
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

    /** Longest edge, in pixels, and JPEG quality for each variant. */
    private const SIZES = [
        self::DISPLAY => [1280, 78],
        self::THUMB   => [400, 74],
    ];

    /**
     * The public path for a variant, falling back to the original when the
     * derivative is not on disk.
     */
    public static function variantPath(?string $file, string $variant): ?string
    {
        if (!$file) {
            return null;
        }

        $name = basename($file);
        $derived = public_path(self::DIR . '/' . $variant . '/' . static::derivedName($name));

        return is_file($derived)
            ? self::DIR . '/' . $variant . '/' . static::derivedName($name)
            : self::DIR . '/' . $name;
    }

    /** Derivatives are always JPEG — the originals are photographs. */
    public static function derivedName(string $name): string
    {
        return pathinfo($name, PATHINFO_FILENAME) . '.jpg';
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
            imagejpeg($dst, $out, $quality);

            imagedestroy($dst);
            imagedestroy($im);
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
            $p = public_path(self::DIR . '/' . $variant . '/' . static::derivedName(basename($name)));
            if (is_file($p)) {
                @unlink($p);
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
