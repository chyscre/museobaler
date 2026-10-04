<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Support\AuditTrail;
use App\Support\DayPage;
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

        if ($action = $request->input('action')) {
            $query->whereIn('action', $kinds->get($action, collect([$action]))->all());
        }

        if ($user = $request->input('user')) {
            $query->where('user_name', $user);
        }

        // A page is a day: the audit trail is read as "what happened on
        // Tuesday", and a hundred-row page split a busy day in half. The day
        // picker is the only date control; a From/To range used to sit in the
        // filter bar beside it, two ways to say the same thing.
        $day     = DayPage::of($query->orderByDesc('created_at'), 'created_at');
        $logs    = $day->rows;
        $actions = $kinds->keys();
        $users   = Log::distinct()->whereNotNull('user_name')->orderBy('user_name')->pluck('user_name');
        $total   = Log::count();

        return view('logs.index', compact('day', 'logs', 'actions', 'users', 'total'));
    }
}
