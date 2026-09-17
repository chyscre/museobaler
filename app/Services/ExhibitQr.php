<?php

namespace App\Services;

use App\Models\Exhibit;

/**
 * The QR code on each display case.
 *
 * Every exhibit gets one the moment it is created, written to
 * public/images/qr/{code}.svg so the label can be printed straight from the
 * exhibit's own page. The code encodes the visitor app's deep link for the
 * exhibit, so it is remade whenever the exhibit code changes.
 */
class ExhibitQr
{
    public const DIR = 'images/qr';

    /** The visitor-app URL a scan of this exhibit's code opens. */
    public static function scanUrl(Exhibit $exhibit): string
    {
        // Built with url() so the link is correct on whatever host/base path
        // the app is served from (a tunnel, a LAN IP, a subdirectory).
        // Targets index.php explicitly: Apache's DirectoryIndex prefers
        // index.html, so a bare directory URL would drop the ?scan= code.
        return url('/visitor/index.php') . '?scan=' . urlencode($exhibit->exhibit_code);
    }

    /** Write (or rewrite) the exhibit's SVG and return its filename. */
    public static function write(Exhibit $exhibit): string
    {
        $dir = public_path(self::DIR);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Any previous file - the code may have changed, so the name may too.
        if ($exhibit->qr_file && $exhibit->qr_file !== self::filename($exhibit)) {
            @unlink($dir . '/' . $exhibit->qr_file);
        }

        $name = self::filename($exhibit);
        file_put_contents($dir . '/' . $name, self::svg(self::scanUrl($exhibit)));

        return $name;
    }

    public static function filename(Exhibit $exhibit): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $exhibit->exhibit_code) . '.svg';
    }

    public static function path(Exhibit $exhibit): ?string
    {
        if (!$exhibit->qr_file) {
            return null;
        }
        $path = public_path(self::DIR . '/' . basename($exhibit->qr_file));

        return is_file($path) ? $path : null;
    }

    /**
     * Generate a QR code SVG string for a given URL.
     *
     * ECC_H tolerates 30% damage, which matters for codes that end up printed
     * and stuck to a wall visitors brush past.
     */
    public static function svg(string $url): string
    {
        return Qr::svg($url);
    }
}
