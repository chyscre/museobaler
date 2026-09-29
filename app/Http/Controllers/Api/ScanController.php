<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreScanRequest;
use App\Models\Scan;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/scans - the visitor opened an exhibit, by label or by camera.
 *
 * SECURITY: the scanning visitor is the token's, never a visitor_id in the
 * body - that was forgeable, letting a caller write history onto someone
 * else's record. Behind the gate too, so an uncleared visitor cannot log
 * scans before paying.
 */
class ScanController extends Controller
{
    public function store(StoreScanRequest $request): JsonResponse
    {
        Scan::create([
            'exhibit_id' => $request->integer('exhibit_id'),
            'visitor_id' => $request->user('visitor')->visitor_id,
            'scan_type'  => $request->input('scan_type', 'qr'),
            // Normally absent, and then the model stamps now(). The app sends
            // it only for a scan it made with no signal and held in its queue
            // until there was some: without it, every exhibit opened in the
            // dead zone at the back of the museum would be filed at the
            // moment the visitor walked back into coverage.
            'scanned_at' => $request->date('scanned_at'),
        ]);

        return response()->json(['ok' => true]);
    }
}
