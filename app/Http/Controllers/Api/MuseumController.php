<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MuseumHall;
use App\Models\MuseumInfo;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/museum - the About screen and the app's own settings.
 *
 * Public: the story, hours and contact details are on the sign outside too.
 * Also carries the fee and the geofence, which the app reads before anyone
 * has signed in.
 */
class MuseumController extends Controller
{
    public function show(): JsonResponse
    {
        $info  = MuseumInfo::query()->first()?->toArray() ?? [];
        $halls = MuseumHall::query()->orderBy('sort_order')->get()->toArray();

        // The admission line is generated from the fee, never typed, so the
        // About screen, the sign-up fee box and the desk can never disagree.
        $fee = MuseumInfo::admissionFee();
        $info['admission_fee'] = $fee;
        $info['admission']     = MuseumInfo::admissionSentence($fee);

        // Whether the app must actually be inside the fence before it logs
        // an entry. Decided here, not by the phone looking at its hostname:
        // a production server reached by its LAN address is still production.
        $info['geofence_enforced'] = app()->environment('production')
            || (bool) config('access.visitor_geofence', true);

        return response()->json(['info' => $info, 'halls' => $halls]);
    }
}
