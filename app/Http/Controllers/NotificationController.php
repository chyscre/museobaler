<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Feedback;
use App\Models\Scan;
use App\Models\VisitGroup;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Live activity feed behind the admin bell.
 *
 * Two kinds of information come back, and they behave differently:
 *
 * - `items` are *events* — a check-in, a registration, a group
 *   arriving, an exhibit scan, a fee collected, an ID sighted, feedback left.
 *   The client deduplicates them by id so each one toasts at most once.
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
        $today  = today()->toDateString();
        $isInit = $request->has('init');

        if ($isInit) {
            $items = $this->events(fn (Builder $q, string $column) => $q->whereDate($column, $today));
        } else {
            // Timestamps are compared in the app timezone, which now matches the
            // MySQL server clock — see the APP_TIMEZONE note in config/app.php.
            $since = $request->input('since', now()->subSeconds(40)->toDateTimeString());
            $items = $this->events(fn (Builder $q, string $column) => $q->where($column, '>=', $since));
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
     * Every event source merged into one feed, newest first.
     *
     * $window narrows a query to the time range wanted — the whole of today on
     * init, or everything since the last poll — given the column that marks
     * when the event happened. Each source has its own such column.
     */
    private function events(callable $window): Collection
    {
        $records = fn (string $tab) => route('records.index') . '?tab=' . $tab;

        $checkIns = $window(Attendance::query(), 'created_at')
            ->orderByDesc('created_at')->get()->map(fn ($a) => [
                'id'      => 'att_' . $a->attendance_id,
                'type'    => 'attendance',
                'message' => ($a->visitor_name ?: 'An anonymous visitor') . ' checked in',
                'time'    => $a->created_at?->format('h:i A') ?? '',
                'at'      => $a->created_at?->timestamp ?? 0,
                'url'     => $records('attendance'),
            ]);

        // No check-out event: attendances.exited_at is rewritten every time the
        // phone is pocketed ("last confirmed on site"), so it cannot say when
        // someone actually left.

        $registrations = $window(Visitor::query(), 'created_at')
            ->orderByDesc('created_at')->get()->map(fn ($v) => [
                'id'      => 'vis_' . $v->visitor_id,
                'type'    => 'visitor',
                'message' => $v->full_name . ' registered'
                    . ($v->visitor_type ? ' (' . $v->visitor_type . ')' : ''),
                'time'    => $v->created_at?->format('h:i A') ?? '',
                'at'      => $v->created_at?->timestamp ?? 0,
                'url'     => $records('visitors'),
            ]);

        $groups = $window(VisitGroup::query(), 'created_at')
            ->orderByDesc('created_at')->get()->map(fn ($g) => [
                'id'      => 'grp_' . $g->group_id,
                'type'    => 'visitor',
                'message' => ($g->group_name ?: $g->contact_name ?: 'A group') . ' registered'
                    . ' — party of ' . (int) $g->headcount,
                'time'    => $g->created_at?->format('h:i A') ?? '',
                'at'      => $g->created_at?->timestamp ?? 0,
                'url'     => $records('visitors'),
            ]);

        $payments = $window(Visitor::where('payment_status', 'Paid')->whereNotNull('paid_at'), 'paid_at')
            ->orderByDesc('paid_at')->get()->map(fn ($v) => [
                'id'      => 'paid_' . $v->visitor_id . '_' . $v->paid_at?->timestamp,
                'type'    => 'payment',
                'message' => $v->full_name . ' paid ₱' . number_format((float) $v->admission_fee, 2),
                'time'    => $v->paid_at?->format('h:i A') ?? '',
                'at'      => $v->paid_at?->timestamp ?? 0,
                'url'     => $records('visitors'),
            ]);

        $idChecks = $window(Visitor::where('id_verified', true)->whereNotNull('verified_at'), 'verified_at')
            ->orderByDesc('verified_at')->get()->map(fn ($v) => [
                'id'      => 'idv_ok_' . $v->visitor_id . '_' . $v->verified_at?->timestamp,
                'type'    => 'id_check',
                'message' => $v->full_name . "'s residency ID was verified"
                    . ($v->verified_by ? ' by ' . $v->verified_by : ''),
                'time'    => $v->verified_at?->format('h:i A') ?? '',
                'at'      => $v->verified_at?->timestamp ?? 0,
                'url'     => $records('visitors'),
            ]);

        $feedback = $window(Feedback::with('visitor'), 'submitted_at')
            ->orderByDesc('submitted_at')->get()->map(fn ($f) => [
                'id'      => 'fb_' . $f->feedback_id,
                'type'    => 'feedback',
                'message' => ($f->visitor?->full_name ?: 'A visitor') . ' left feedback'
                    . ($f->rating ? ' — ' . str_repeat('★', (int) $f->rating) : ''),
                'time'    => $f->submitted_at?->format('h:i A') ?? '',
                'at'      => $f->submitted_at?->timestamp ?? 0,
                'url'     => route('feedback.show', $f->feedback_id),
            ]);

        $exhibitScans = $window(Scan::with(['exhibit', 'visitor']), 'scanned_at')
            ->orderByDesc('scanned_at')->get()->map(fn ($s) => [
                'id'      => 'scan_' . $s->scan_id,
                'type'    => 'scan',
                'message' => ($s->visitor?->full_name ?: 'A guest') . ' scanned ' . ($s->exhibit->name ?? 'an exhibit'),
                'time'    => $s->scanned_at?->format('h:i A') ?? '',
                'at'      => $s->scanned_at?->timestamp ?? 0,
                'url'     => $s->exhibit
                    ? route('exhibits.show', $s->exhibit->exhibit_id)
                    : $records('scans'),
            ]);

        return $checkIns->concat($registrations)->concat($groups)
            ->concat($payments)->concat($idChecks)->concat($feedback)->concat($exhibitScans)
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
                $name   = $v->full_name;
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
