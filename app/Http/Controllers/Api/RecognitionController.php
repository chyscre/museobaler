<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Scan;
use App\Services\ImageSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/recognition - which exhibit is the camera pointed at?
 *
 * SECURITY: behind the admission gate. Point-and-identify is a paid
 * feature of the tour; without the gate a visitor who never paid could
 * still walk the museum identifying exhibits.
 *
 * The frame is read into memory, hashed and discarded: it is never written
 * anywhere it could be recovered from.
 */
class RecognitionController extends Controller
{
    public function search(Request $request, ImageSearch $search): JsonResponse
    {
        $request->validate([
            // 1 MB: a phone's downscaled viewfinder frame, not a photo.
            'frame' => ['required', 'file', 'max:1024', 'mimetypes:image/jpeg,image/png,image/gif,image/webp'],
        ]);

        $frame = @imagecreatefromstring((string) file_get_contents($request->file('frame')->getRealPath()));

        if (!$frame) {
            return response()->json(['error' => 'unreadable_frame'], 400);
        }

        $results = $search->match($frame);
        imagedestroy($frame);

        $top = $results[0] ?? null;

        if ($top === null) {
            return response()->json(['exhibit_code' => null, 'exhibit_id' => null, 'confidence' => 0, 'candidates' => []]);
        }

        // Only a match strong enough to open the exhibit counts as a visit.
        // The app samples every few seconds, so logging every candidate wrote
        // a row per sample and made engagement figures meaningless; a weaker
        // match is a suggestion, and if the visitor picks one from the list
        // the app logs that itself.
        if ($top['confidence'] >= ImageSearch::HIGH_CONFIDENCE) {
            Scan::create([
                'exhibit_id' => $top['exhibit_id'],
                'visitor_id' => $request->user('visitor')->visitor_id,
                'scan_type'  => 'image',
            ]);
        }

        return response()->json([
            'exhibit_code' => $top['exhibit_code'],
            'exhibit_id'   => $top['exhibit_id'],
            'confidence'   => $top['confidence'],
            'candidates'   => array_map(fn ($c) => [
                'exhibit_code' => $c['exhibit_code'],
                'name'         => $c['name'],
                'confidence'   => $c['confidence'],
            ], array_slice($results, 0, ImageSearch::MAX_CANDIDATES)),
        ]);
    }
}
