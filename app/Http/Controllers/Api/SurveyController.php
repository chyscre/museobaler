<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tour;
use App\Models\Visitor;
use App\Support\ArtaSurvey;
use App\Support\SurveyAnswers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/survey - everything the feedback sheet needs in one call.
 *
 * The active questions (edited by the Tourism office), whether this visit
 * was guided so the "how was your guide" question appears only for the
 * minority of visits that had one, and the header defaults the paper form
 * asks for that the visitor's record can already answer.
 */
class SurveyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Visitor $visitor */
        $visitor = $request->user('visitor');
        $guide   = self::todaysGuideFor($visitor)?->guide;

        // Region: Baler is Region III. A foreign visitor is "outside PH"; a
        // domestic tourist can correct the default on the sheet.
        $region = $visitor->visitor_type === 'Foreign' ? 'Outside the Philippines' : 'III – Central Luzon';

        return response()->json([
            'questions'  => SurveyAnswers::questions()->map(fn ($q) => SurveyAnswers::present($q))->values(),
            'guided'     => $guide !== null,
            'guide_name' => $guide?->name,
            'defaults'   => ['client_type' => 'citizen', 'region' => $region],
            'regions'    => ArtaSurvey::REGIONS,
        ]);
    }

    /**
     * The tour this visitor was on today, if any. Looked up on the server
     * rather than accepted from the request, so a visitor cannot rate a
     * staff member who never guided them.
     */
    public static function todaysGuideFor(Visitor $visitor): ?Tour
    {
        return Tour::query()
            ->where('visitor_id', $visitor->visitor_id)
            ->whereDate('started_at', today())
            ->whereNotNull('guide_staff_id')
            ->with('guide')
            ->orderByDesc('started_at')
            ->first();
    }
}
