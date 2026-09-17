<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function index(Request $request)
    {
        $query = Feedback::with('visitor');

        if ($search = $request->input('search')) {
            $query->where('comment', 'like', "%$search%")
                  ->orWhereHas('visitor', fn($q) => $q->where('first_name', 'like', "%$search%")
                      ->orWhere('last_name', 'like', "%$search%"));
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

        $stats = [
            'total'      => Feedback::count(),
            'avg_rating' => round(Feedback::avg('rating'), 1),
        ];

        return view('feedback.index', compact('feedback', 'stats'));
    }

    public function show(Feedback $feedback)
    {
        $feedback->load('visitor');
        return view('feedback.show', compact('feedback'));
    }

    public function modalShow(Feedback $feedback)
    {
        $feedback->load('visitor');
        return response()->json([
            'feedback_id'   => $feedback->feedback_id,
            'rating'        => $feedback->rating,
            'comment'       => $feedback->comment,
            'date'          => $feedback->submitted_at?->format('M j, Y g:i A'),
            'visitor_first' => $feedback->visitor?->first_name,
            'visitor_last'  => $feedback->visitor?->last_name,
            'country'       => $feedback->visitor?->country,
        ]);
    }
}
