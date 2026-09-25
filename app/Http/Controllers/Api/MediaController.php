<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    public function show(Request $request, string $path): BinaryFileResponse
    {
        $path = ltrim(rawurldecode($path), '/');

        if (!preg_match('#\A(?:images/exhibits|audio)/[A-Za-z0-9._/-]+\z#', $path) || str_contains($path, '..')) {
            abort(404);
        }

        $root = realpath(public_path());
        $file = realpath(public_path($path));

        if (!$root || !$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
            abort(404);
        }

        return response()->file($file, [
            'Cache-Control' => 'private, max-age=600',
            'Accept-Ranges' => 'bytes',
        ]);
    }
}