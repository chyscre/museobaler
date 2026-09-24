<?php

namespace App\Http\Controllers;

use App\Models\Log;
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

        if ($action = $request->input('action')) {
            $query->where('action', $action);
        }

        if ($user = $request->input('user')) {
            $query->where('user_name', $user);
        }

        if ($from = $request->input('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        // A page is a day: the audit trail is read as "what happened on
        // Tuesday", and a hundred-row page split a busy day in half.
        $day     = DayPage::of($query->orderByDesc('created_at'), 'created_at');
        $logs    = $day->rows;
        $actions = Log::distinct()->orderBy('action')->pluck('action');
        $users   = Log::distinct()->whereNotNull('user_name')->orderBy('user_name')->pluck('user_name');
        $total   = Log::count();

        return view('logs.index', compact('day', 'logs', 'actions', 'users', 'total'));
    }
}
