<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Scan;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Live activity feed behind the admin bell.
 *
 * Two kinds of information come back, and they behave differently:
 *
 * - `items` are *events* — a check-in, a registration, an exhibit scan. The
 *   client deduplicates them by id so each one toasts at most once.
 * - `pending` are *outstanding desk tasks* — an admission fee still to collect,
 *   a residency ID still to sight. Those are current state rather than events,
 *   so they are re-sent on every poll and stay in the panel until someone
 *   actually clears them.
 *
 * On `?init=1` the events come back fully rendered instead of as bare ids.
 * Previously init returned empty messages purely to mark ids as seen, which
 * meant an admin logging in mid-afternoon saw an empty panel no matter how much
 * had happened that day — the day's activity had already been "seen" by the
 * very request that was supposed to show it.
 */
class NotificationController extends Controller
{
    public function poll(Request $request)
    {
        $today     = today()->toDateString();
        $isInit    = $request->has('init');

        if ($isInit) {
            $items = $this->events(
                Attendance::whereDate('visit_date', $today),
                Visitor::whereDate('created_at', $today),
                Scan::whereDate('scanned_at', $today),
            );
        } else {
            // Timestamps are compared in the app timezone, which now matches the
            // MySQL server clock — see the APP_TIMEZONE note in config/app.php.
            $since = $request->input('since', now()->subSeconds(40)->toDateTimeString());

            $items = $this->events(
                Attendance::where('created_at', '>=', $since),
                Visitor::where('created_at', '>=', $since),
                Scan::where('scanned_at', '>=', $since),
            );
        }

        return response()->json([
            'count'            => $isInit ? 0 : $items->count(),
            'items'            => $items,
            'pending'          => $this->pending(),
            'today_attendance' => Attendance::whereDate('visit_date', $today)->count(),
            // A plain datetime string, not toISOString(): the ISO form is always
            // UTC, which would sit 8 hours behind the timestamps actually stored
            // in the database and make every poll re-fetch the same rows.
            'server_time'      => now()->toDateTimeString(),
        ]);
    }

    /**
     * Merge the three event sources into one feed, newest first.
     */
    private function events(Builder $attendance, Builder $visitors, Builder $scans): Collection
    {
        $checkIns = $attendance->orderByDesc('created_at')->get()->map(fn ($a) => [
            'id'      => 'att_' . $a->attendance_id,
            'type'    => 'attendance',
            'message' => ($a->visitor_name ?: 'Anonymous') . ' checked in',
            'time'    => $a->created_at?->format('h:i A') ?? '',
            'at'      => $a->created_at?->timestamp ?? 0,
            'url'     => route('records.index') . '?tab=attendance',
        ]);

        $registrations = $visitors->orderByDesc('created_at')->get()->map(fn ($v) => [
            'id'      => 'vis_' . $v->visitor_id,
            'type'    => 'visitor',
            'message' => trim($v->first_name . ' ' . $v->last_name) . ' registered',
            'time'    => $v->created_at?->format('h:i A') ?? '',
            'at'      => $v->created_at?->timestamp ?? 0,
            'url'     => route('records.index') . '?tab=visitors',
        ]);

        $exhibitScans = $scans->with(['exhibit', 'visitor'])->orderByDesc('scanned_at')->get()
            ->map(function ($s) {
                $who = $s->visitor
                    ? trim($s->visitor->first_name . ' ' . $s->visitor->last_name)
                    : 'A guest';

                return [
                    'id'      => 'scan_' . $s->scan_id,
                    'type'    => 'scan',
                    'message' => $who . ' scanned ' . ($s->exhibit->name ?? 'an exhibit'),
                    'time'    => $s->scanned_at?->format('h:i A') ?? '',
                    'at'      => $s->scanned_at?->timestamp ?? 0,
                    'url'     => $s->exhibit
                        ? route('exhibits.show', $s->exhibit->exhibit_id)
                        : route('records.index') . '?tab=scans',
                ];
            });

        return $checkIns->concat($registrations)->concat($exhibitScans)
            ->sortByDesc('at')
            ->values();
    }

    /**
     * Visitors the front desk still owes an action: a fee to collect, or a
     * residency ID to sight before free admission is granted.
     *
     * Each carries the POST target for the matching action so the bell can
     * offer the button directly instead of only linking to the records table.
     */
    private function pending(): Collection
    {
        return Visitor::where(function (Builder $q) {
                $q->where('payment_status', 'Unpaid')
                  ->orWhere(function (Builder $local) {
                      $local->where('visitor_type', 'Local')->where('id_verified', false);
                  });
            })
            ->orderByDesc('visitor_id')
            ->limit(10)
            ->get()
            ->map(function ($v) {
                $name   = trim($v->first_name . ' ' . $v->last_name);
                $unpaid = $v->payment_status === 'Unpaid';

                return [
                    'id'         => ($unpaid ? 'pay_' : 'idv_') . $v->visitor_id,
                    'type'       => $unpaid ? 'payment' : 'id_check',
                    'message'    => $unpaid
                        ? $name . ' owes ₱' . number_format((float) $v->admission_fee, 2)
                        : $name . ' needs a residency ID check',
                    'action'     => $unpaid ? 'Mark Paid' : 'Verify ID',
                    'action_url' => $unpaid
                        ? route('visitors.mark-paid', $v->visitor_id)
                        : route('visitors.verify-id', $v->visitor_id),
                    'url'        => route('records.index')
                        . '?tab=visitors&adm=' . ($unpaid ? 'unpaid' : 'unverified'),
                ];
            })
            ->values();
    }
}
