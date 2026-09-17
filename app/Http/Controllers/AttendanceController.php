<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $date  = $request->input('date', today()->toDateString());
        $query = Attendance::with('visitor')
            ->orderByDesc('created_at');

        if ($date) {
            $query->whereDate('visit_date', $date);
        }

        $attendances  = $query->paginate(30)->withQueryString();
        $todayCount   = Attendance::whereDate('visit_date', today())->count();
        $totalCount   = Attendance::count();

        // Last 7 days chart data
        $chartLabels = [];
        $chartValues = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = now()->subDays($i);
            $chartLabels[] = $d->format('D, M j');
            $chartValues[] = Attendance::whereDate('visit_date', $d->toDateString())->count();
        }

        return view('attendance.index', compact(
            'attendances', 'todayCount', 'totalCount', 'date', 'chartLabels', 'chartValues'
        ));
    }
}
