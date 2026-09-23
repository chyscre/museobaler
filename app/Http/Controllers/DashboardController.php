<?php

namespace App\Http\Controllers;

use App\Models\Exhibit;
use App\Models\Visitor;
use App\Models\Feedback;
use App\Models\Scan;
use App\Models\Attendance;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'exhibits'          => Exhibit::where('status', 1)->count(),
            'visitors'          => Visitor::count(),
            'scans'             => Scan::count(),
            'feedback'          => Feedback::count(),
            'avg_rating'        => round(Feedback::avg('rating'), 1),
            'today_attendance'  => Attendance::whereDate('visit_date', today())->count(),
        ];

        // Both of these rank exhibits, and an exhibit exists whether or not
        // anybody has scanned it - so without a floor they render a league
        // table of eight exhibits on nil points, and flag three of them red
        // for "low engagement" when nothing has been scanned at all. A
        // ranking nobody earned is an invented record.
        // whereHas rather than having('scans_count', '>', 0): the subquery
        // withCount adds is not an aggregate, so HAVING against it is a
        // syntax error on SQLite and only happens to work on MySQL.
        $topExhibits = Exhibit::withCount('scans')
            ->whereHas('scans')
            ->orderByDesc('scans_count')
            ->limit(5)
            ->get(['exhibit_id', 'name']);

        // "Needs attention" only means something once there is traffic to be
        // missing out on. Before the first scan there is nothing to compare.
        $lowExhibits = $stats['scans'] > 0
            ? Exhibit::where('status', 1)
                ->withCount('scans')
                ->orderBy('scans_count')
                ->limit(3)
                ->get(['exhibit_id', 'name'])
            : collect();

        $fbDist = Feedback::selectRaw('rating, COUNT(*) as c')
            ->groupBy('rating')
            ->orderByDesc('rating')
            ->pluck('c', 'rating');

        return view('dashboard.index', compact('stats', 'topExhibits', 'lowExhibits', 'fbDist'));
    }

    public function chartVisitors()
    {
        $months = [];
        $values = [];
        for ($i = 11; $i >= 0; $i--) {
            $date   = now()->subMonths($i);
            $months[] = $date->format('M');
            $values[] = Visitor::whereYear('created_at', $date->year)
                                ->whereMonth('created_at', $date->month)
                                ->count();
        }
        return response()->json(['labels' => $months, 'values' => $values]);
    }

    public function chartCategories()
    {
        $data = Scan::selectRaw('categories.name, COUNT(scans.scan_id) as total')
            ->join('exhibits', 'scans.exhibit_id', '=', 'exhibits.exhibit_id')
            ->join('categories', 'exhibits.category_id', '=', 'categories.category_id')
            ->groupBy('categories.category_id', 'categories.name')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'labels' => $data->pluck('name'),
            'values' => $data->pluck('total'),
        ]);
    }
}
