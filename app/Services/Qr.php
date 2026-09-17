<?php

namespace App\Services;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Every QR code the panel draws, from the one library.
 *
 * There used to be two. Exhibit labels came from chillerlan/php-qrcode, a
 * managed Composer dependency; the entrance poster and the staff-room kiosk
 * called a copy of the old phpqrcode library committed into public/ and
 * pulled in with require_once on a public_path(). That copy received no
 * updates, was reachable over HTTP by virtue of living under the docroot, and
 * meant a fix to one QR path left the other alone.
 *
 * SVG rather than PNG, because both remaining callers put the result in an
 * <img>: the poster is printed, where vector stays sharp at any paper size,
 * and the kiosk is read off a screen by a phone camera a metre away.
 */
class Qr
{
    public static function svg(string $data, int $size = 256, int $ecc = EccLevel::H): string
    {
        if (!class_exists(QRCode::class)) {
            // Composer has not been run. Draw something legible rather than
            // letting an exhibit page or the kiosk fail outright.
            return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '">'
                 . '<rect width="100%" height="100%" fill="#fff"/>'
                 . '<text x="50%" y="50%" text-anchor="middle" font-size="12" fill="#999">QR package not installed</text>'
                 . '</svg>';
        }

        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'eccLevel'        => $ecc,
            'addQuietzone'    => true,
            'quietzoneSize'   => 4,
            'connectPaths'    => true,
            // Without this the renderer hands back a data: URI rather than
            // SVG markup.
            'outputBase64'    => false,
        ]);

        $svg = (new QRCode($options))->render($data);

        // The renderer emits only a viewBox, leaving the intrinsic size to
        // whatever opens the file. Print dialogs and image viewers need real
        // dimensions.
        return preg_replace('/<svg /', '<svg width="' . $size . '" height="' . $size . '" ', $svg, 1);
    }

    /** The same code as a data: URI, for dropping straight into an <img src>. */
    public static function dataUri(string $data, int $size = 256, int $ecc = EccLevel::H): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($data, $size, $ecc));
    }
}
