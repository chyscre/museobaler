<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\SurveyQuestion;
use App\Support\CsmReport;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function index(Request $request)
    {
        $query = Feedback::with(['visitor', 'answers']);

        if ($search = $request->input('search')) {
            $query->where(fn ($q) => $q->where('comment', 'like', "%$search%")
                  ->orWhereHas('visitor', fn($v) => $v->where('first_name', 'like', "%$search%")
                      ->orWhere('last_name', 'like', "%$search%")));
        }

        if ($rating = $request->input('rating')) {
            $query->where('rating', $rating);
        }

        $sort = $request->input('sort', 'newest');
        match ($sort) {
            'oldest'  => $query->orderBy('submitted_at'),
            'highest' => $query->orderByDesc('rating'),
            'lowest'  => $query->orderBy('rating'),
            default   => $query->orderByDesc('submitted_at'),
        };

        $feedback = $query->paginate(20)->withQueryString();

        // The headline CSM number, over everything ever filed. The dated
        // version is on the report.
        $csm = CsmReport::build(Feedback::with('answers')->get());

        $stats = [
            'total'        => Feedback::count(),
            'avg_rating'   => round(Feedback::avg('rating'), 1),
            'respondents'  => $csm['respondents'],
            'sqd_score'    => $csm['sqd_score'],
            'sqd_label'    => CsmReport::rating($csm['sqd_score']),
            'cc_awareness' => $csm['cc_awareness'],
        ];

        return view('feedback.index', compact('feedback', 'stats'));
    }

    public function show(Feedback $feedback)
    {
        $feedback->load(['visitor', 'answers']);
        return view('feedback.show', [
            'feedback' => $feedback,
            'answers'  => $this->answerLines($feedback),
        ]);
    }

    public function modalShow(Feedback $feedback)
    {
        $feedback->load(['visitor', 'answers']);
        return response()->json([
            'feedback_id'   => $feedback->feedback_id,
            'rating'        => $feedback->rating,
            'comment'       => $feedback->comment,
            'date'          => $feedback->submitted_at?->format('M j, Y g:i A'),
            'visitor_first' => $feedback->visitor?->first_name,
            'visitor_last'  => $feedback->visitor?->last_name,
            'country'       => $feedback->visitor?->country,
            'client_type'   => $feedback->client_type,
            'region'        => $feedback->region,
            'answers'       => $this->answerLines($feedback),
        ]);
    }

    /**
     * One line per survey answer, in question order, with the answer put
     * into words. A deleted question still shows under its code.
     */
    private function answerLines(Feedback $feedback): array
    {
        if ($feedback->answers->isEmpty()) {
            return [];
        }
        $questions = SurveyQuestion::orderBy('sort_order')->get()->keyBy('code');
        $order     = $questions->keys()->flip();

        return $feedback->answers
            ->sortBy(fn ($a) => $order[$a->code] ?? 999)
            ->map(function ($a) use ($questions) {
                $q = $questions->get($a->code);
                return [
                    'code'    => $a->code,
                    'section' => $q?->section ?? 'app',
                    'text'    => $q?->text_en ?? $a->code,
                    'value'   => $a->value,
                    'label'   => $q ? $q->labelFor($a->value) : ($a->value === null ? 'N/A' : (string) $a->value),
                ];
            })
            ->values()
            ->all();
    }
}
