<?php

namespace App\Http\Controllers;

use App\Models\Exhibit;
use App\Models\Visitor;
use App\Models\Feedback;
use App\Models\Scan;
use App\Models\Attendance;
use App\Support\DashboardPeriod;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $period = DashboardPeriod::fromRequest($request);
        $range  = $period->bounds();

        $feedback = Feedback::whereBetween('submitted_at', $range);
        $scans    = Scan::whereBetween('scanned_at', $range)->count();

        $stats = [
            'visitors'   => Visitor::whereBetween('created_at', $range)->count(),
            'attendance' => Attendance::whereBetween('visit_date', [$period->from->toDateString(), $period->to->toDateString()])->count(),
            'scans'      => $scans,
            'feedback'   => (clone $feedback)->count(),
            'avg_rating' => round((clone $feedback)->avg('rating'), 1),
        ];

        $inPeriod = fn ($q) => $q->whereBetween('scanned_at', $range);

        // Both of these rank exhibits, and an exhibit exists whether or not
        // anybody has scanned it - so without a floor they render a league
        // table of eight exhibits on nil points, and flag three of them red
        // for "low engagement" when nothing has been scanned at all. A
        // ranking nobody earned is an invented record.
        // whereHas rather than having('scans_count', '>', 0): the subquery
        // withCount adds is not an aggregate, so HAVING against it is a
        // syntax error on SQLite and only happens to work on MySQL.
        $topExhibits = Exhibit::withCount(['scans' => $inPeriod])
            ->whereHas('scans', $inPeriod)
            ->orderByDesc('scans_count')
            ->limit(5)
            ->get(['exhibit_id', 'name']);

        // "Needs attention" only means something once there is traffic to be
        // missing out on. Before the first scan there is nothing to compare.
        $lowExhibits = $scans > 0
            ? Exhibit::where('status', 1)
                ->withCount(['scans' => $inPeriod])
                ->orderBy('scans_count')
                ->limit(3)
                ->get(['exhibit_id', 'name'])
            : collect();

        $fbDist = (clone $feedback)->selectRaw('rating, COUNT(*) as c')
            ->groupBy('rating')
            ->orderByDesc('rating')
            ->pluck('c', 'rating');

        $categories = $this->categoryScans($period);

        $years = DashboardPeriod::years();

        return view('dashboard.index', compact(
            'period', 'stats', 'topExhibits', 'lowExhibits', 'fbDist', 'categories', 'years'
        ));
    }

    public function chartVisitors(Request $request)
    {
        $period  = DashboardPeriod::fromRequest($request);
        $buckets = array_fill_keys(array_keys($period->buckets()), 0);

        // Bucketed here rather than with DATE_FORMAT/strftime so the same
        // code runs on MySQL in production and SQLite in the tests.
        Visitor::whereBetween('created_at', $period->bounds())
            ->pluck('created_at')
            ->each(function ($at) use ($period, &$buckets) {
                $key = $period->bucketOf($at);
                if (isset($buckets[$key])) {
                    $buckets[$key]++;
                }
            });

        return response()->json([
            'labels' => array_values($period->buckets()),
            'values' => array_values($buckets),
        ]);
    }

    public function chartCategories(Request $request)
    {
        $data = $this->categoryScans(DashboardPeriod::fromRequest($request));

        return response()->json([
            'labels' => $data->pluck('name'),
            'values' => $data->pluck('total'),
        ]);
    }

    private function categoryScans(DashboardPeriod $period)
    {
        return Scan::selectRaw('categories.name, COUNT(scans.scan_id) as total')
            ->join('exhibits', 'scans.exhibit_id', '=', 'exhibits.exhibit_id')
            ->join('categories', 'exhibits.category_id', '=', 'categories.category_id')
            ->whereBetween('scans.scanned_at', $period->bounds())
            ->groupBy('categories.category_id', 'categories.name')
            ->orderByDesc('total')
            ->get();
    }
}
