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
     * Uses chillerlan/php-qrcode - pure PHP, no imagemagick required.
     */
    public static function svg(string $url): string
    {
        if (!class_exists(\chillerlan\QRCode\QRCode::class)) {
            // Fallback: a placeholder SVG if the package isn't installed yet
            return '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256">'
                 . '<rect width="256" height="256" fill="#fff"/>'
                 . '<text x="128" y="128" text-anchor="middle" font-size="12" fill="#999">QR package not installed</text>'
                 . '</svg>';
        }

        $options = new \chillerlan\QRCode\QROptions([
            'outputInterface' => \chillerlan\QRCode\Output\QRMarkupSVG::class,
            // ECC_H tolerates 30% damage, which matters for codes that end up
            // printed and stuck to a wall visitors brush past.
            'eccLevel'        => \chillerlan\QRCode\Common\EccLevel::H,
            'addQuietzone'    => true,
            'quietzoneSize'   => 4,
            'connectPaths'    => true,
            // Without this the renderer hands back a data: URI rather than
            // SVG markup.
            'outputBase64'    => false,
        ]);

        $svg = (new \chillerlan\QRCode\QRCode($options))->render($url);

        // The renderer emits only a viewBox, leaving the intrinsic size to
        // whatever opens the file. Print dialogs and image viewers need real
        // dimensions, so pin the rendered size to 256x256.
        return preg_replace('/<svg /', '<svg width="256" height="256" ', $svg, 1);
    }
}
