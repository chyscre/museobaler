<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\VisitGroup;
use App\Models\Visitor;
use App\Support\VisitDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The View Details window on Records and the attendance log.
 *
 * Each answers with the window's body as HTML, which the page drops into
 * one shared modal (records/partials/details-modal). Read-only, so the
 * Tourism office sees the same as the desk.
 */
class RecordDetailsController extends Controller
{
    /** GET /records/visitors/{visitor}/details?date=Y-m-d - one day's visit. */
    public function visitor(Request $request, Visitor $visitor)
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        // The day of the page the row was on, else their latest visit.
        $day = $request->filled('date')
            ? Carbon::parse($request->input('date'))
            : ($visitor->last_visit ?? $visitor->created_at ?? today())->copy();

        return view('records.partials.visitor-details', VisitDetails::forVisitor($visitor->load('holder'), $day->startOfDay()));
    }

    /** GET /records/groups/{group}/details - the party and its members. */
    public function group(VisitGroup $group)
    {
        return view('records.partials.group-details', VisitDetails::forGroup($group));
    }

    /** GET /records/attendance/{attendance}/details - from the attendance log. */
    public function attendance(Attendance $attendance)
    {
        if ($attendance->visitor) {
            return view('records.partials.visitor-details',
                VisitDetails::forVisitor($attendance->visitor->load('holder'), $attendance->visit_date->copy()->startOfDay()));
        }

        return view('records.partials.anonymous-details', VisitDetails::forAnonymous($attendance));
    }
}
