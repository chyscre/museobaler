<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MuseumHall;
use App\Models\MuseumInfo;
use App\Services\GeofenceService;
use App\Services\Qr;
use App\Support\Admission;
use App\Support\GoogleSignIn;
use chillerlan\QRCode\Common\EccLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

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
        // Who enters free and who pays less: what the sign-up form asks a
        // local for, and the categories it offers everyone else.
        $info['admission_rules'] = Admission::forClients();

        // Whether the app must actually be inside the fence before it logs
        // an entry. Decided here, not by the phone looking at its hostname:
        // a production server reached by its LAN address is still production.
        $info['geofence_enforced'] = app(GeofenceService::class)->visitorEnforced();

        // Whether to offer "Continue with Google" at all.
        $info['google_sign_in'] = GoogleSignIn::configured();

        return response()->json(['info' => $info, 'halls' => $halls]);
    }

    /**
     * GET /api/v1/museum/app-qr - the code the app shows when it is opened on
     * a computer, so the visitor can carry on on their phone.
     *
     * Always the app's own address, built from this request like the entrance
     * poster's (DeskController::poster), so it holds on localhost, the LAN IP
     * and a tunnel. It takes no input on purpose: an endpoint that encoded
     * whatever it was handed would draw codes for any link under the museum's
     * name.
     */
    public function appQr(): Response
    {
        return response(Qr::svg(url('/visitor/index.html'), 200, EccLevel::M), 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }
}
