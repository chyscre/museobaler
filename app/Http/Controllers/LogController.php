<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Support\AuditTrail;
use App\Support\DashboardPeriod;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $query = Log::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('user_name', 'like', "%$search%")
                  ->orWhere('action', 'like', "%$search%")
                  ->orWhere('details', 'like', "%$search%");
            });
        }

        // The filter lists kinds of change ("Mark visitor as paid"), not every
        // stored action: those carry record numbers, and older rows are still
        // in request form. A kind matches every stored action that reads as it.
        $kinds = Log::distinct()->pluck('action')
            ->groupBy(fn ($a) => AuditTrail::kind($a))
            ->sortKeys();

        // Staff attendance is switched off (see config/access.php): its
        // entries stay in the table but leave the list and the filter.
        $hidden = [];
        if (!config('access.staff_attendance')) {
            $attendance = $kinds->filter(fn ($actions, $kind) => AuditTrail::category($kind) === 'Staff attendance');
            $hidden = $attendance->flatten()->all();
            $kinds  = $kinds->diffKeys($attendance);
            $query->whereNotIn('action', $hidden);
        }

        if ($action = $request->input('action')) {
            $query->whereIn('action', $kinds->get($action, collect([$action]))->all());
        }

        if ($user = $request->input('user')) {
            $query->where('user_name', $user);
        }

        // The same Year / Month / Week / Range control as the dashboard,
        // opening on this week. Every row in the period comes back; the
        // table pages them in the browser.
        $period  = DashboardPeriod::fromRequest($request, 'week');
        $logs    = $query->whereBetween('created_at', $period->bounds())
            ->orderByDesc('created_at')->get();

        // The filter list in labelled sections, Exhibits first.
        $actions = $kinds->keys()
            ->groupBy(fn ($kind) => AuditTrail::category($kind))
            ->sortBy(fn ($kinds, $category) => array_search($category, AuditTrail::CATEGORY_ORDER, true));
        $users   = Log::distinct()->whereNotNull('user_name')->orderBy('user_name')->pluck('user_name');
        $total   = Log::whereNotIn('action', $hidden)->count();

        return view('logs.index', compact('period', 'logs', 'actions', 'users', 'total'));
    }
}
