<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    /**
     * The only two directories media is served from - and, once every symlink
     * has been followed, the only two places a resolved file may end up.
     */
    private const ROOTS = ['images/exhibits', 'audio'];

    public function show(Request $request, string $path): BinaryFileResponse
    {
        $path = ltrim(rawurldecode($path), '/');

        if (!preg_match('#\A(?:images/exhibits|audio)/[A-Za-z0-9._/-]+\z#', $path) || str_contains($path, '..')) {
            abort(404);
        }

        $file = realpath(public_path($path));

        if (!$file || !is_file($file) || !static::insideMediaRoot($file)) {
            abort(404);
        }

        return response()->file($file, [
            'Cache-Control' => 'private, max-age=600',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Containment, checked against the media directories themselves rather
     * than against public/.
     *
     * This used to require the resolved file to sit under
     * `realpath(public_path())`, which is the same thing only as long as
     * nothing under public/ is a symlink. On the deployed layout both media
     * directories are: deploy/deploy.sh keeps uploads in shared/ so they
     * outlive a release, and links public/images/exhibits and public/audio at
     * them. realpath() follows the link out of the release directory, so the
     * check refused every exhibit picture and every audio guide on the live
     * site - a 404 from behind a signature that was perfectly valid, which is
     * why nothing logged it as an error. A Laragon checkout has no symlink to
     * follow, so it could not happen here.
     *
     * Resolving the roots the same way the file is resolved keeps the guard
     * doing its real job - refusing a path that climbs out of the media
     * folders - wherever those folders physically live.
     */
    private static function insideMediaRoot(string $file): bool
    {
        foreach (self::ROOTS as $dir) {
            $root = realpath(public_path($dir));

            if ($root && str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
