<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreFeedbackRequest;
use App\Models\Feedback;
use App\Models\FeedbackAnswer;
use App\Models\Visitor;
use App\Support\SurveyAnswers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/feedback.
 *
 * SECURITY: the author is the token's visitor. Reading the id from the
 * body let anyone post feedback in another visitor's name - and, since
 * feedback is read by the Tourism office, put words in their mouth. The
 * guide it is attributed to is looked up from today's tour on the server,
 * never accepted from the request, so a visitor cannot rate a staff
 * member who never guided them.
 *
 * The comment is stored as plain text; Blade escapes it on the way out.
 */
class FeedbackController extends Controller
{
    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        /** @var Visitor $author */
        $author = $request->user('visitor');
        $data   = $request->validated();

        // Every active, required question must be answered (or legitimately
        // skipped by its show_if rule) with one of its own options.
        $answerRows = [];
        if (array_key_exists('answers', $data) && is_array($data['answers'])) {
            $check = SurveyAnswers::validate(SurveyAnswers::questions(), $data['answers']);
            if (!$check['ok']) {
                return response()->json(['error' => $check['error'], 'code' => $check['code']], 422);
            }
            $answerRows = $check['answers'];
        }

        // Most visits at Museo de Baler are unguided, so tour_id, staff_id
        // and guide_rating stay null on most rows - expected, not missing.
        $tour        = SurveyController::todaysGuideFor($author);
        $guideRating = (int) ($data['guide_rating'] ?? 0);
        $attributed  = $tour === null ? 'none' : ($guideRating > 0 ? 'visitor' : 'duty');

        // One transaction: a feedback row with half its answers would count
        // in the ARTA totals as if the visitor had skipped the rest.
        DB::transaction(function () use ($author, $data, $tour, $guideRating, $attributed, $answerRows) {
            $feedback = Feedback::create([
                'visitor_id'    => $author->visitor_id,
                'tour_id'       => $tour?->tour_id,
                'staff_id'      => $tour?->guide_staff_id,
                // The account's own name when the sheet leaves it blank.
                'first_name'    => trim((string) ($data['first_name'] ?? '')) ?: $author->first_name,
                'last_name'     => trim((string) ($data['last_name'] ?? '')) ?: $author->last_name,
                'middle_name'   => trim((string) ($data['middle_name'] ?? '')) ?: $author->middle_name,
                'rating'        => $data['rating'],
                'guide_rating'  => $tour !== null && $guideRating > 0 ? $guideRating : null,
                'attributed_by' => $attributed,
                'comment'       => trim((string) ($data['comment'] ?? '')),
                'client_type'   => $data['client_type'] ?? null,
                'region'        => trim((string) ($data['region'] ?? '')) ?: null,
            ]);

            foreach ($answerRows as [$questionId, $code, $value]) {
                FeedbackAnswer::create([
                    'feedback_id' => $feedback->feedback_id,
                    'question_id' => $questionId,
                    'code'        => $code,
                    'value'       => $value,
                ]);
            }
        });

        return response()->json(['ok' => true]);
    }
}
