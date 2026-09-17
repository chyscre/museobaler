<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\Log;
use App\Models\Scan;
use App\Models\Staff;
use App\Models\Tour;
use App\Models\Visitor;
use App\Models\VisitGroup;
use App\Services\AttendanceStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports for the Tourism office.
 *
 * Output is print-styled HTML plus CSV rather than generated PDF: the museum
 * prints from the browser, which keeps this working on a Laragon box with no
 * extra composer dependency to install, and CSV is what anyone actually wants
 * when they need to total something in Excel.
 *
 * The daily logbook is the important one. It is what lets the museum stop
 * writing in the paper book - whoever wants a physical record still gets a
 * page to sign and file, it is just printed from the database instead of
 * being the only copy of the data.
 */
class ReportController extends Controller
{
    public function __construct(private AttendanceStatusService $status) {}

    // -- Daily logbook -----------------------------------------------------

    public function logbook(Request $request)
    {
        $date = $request->filled('date') ? Carbon::parse($request->input('date')) : today();

        $visitors = Visitor::with('registeredBy')
            ->whereDate('created_at', $date)
            ->whereNull('group_id')
            ->orderBy('created_at')
            ->get();

        $groups = VisitGroup::with('registeredBy')
            ->whereDate('visit_date', $date)
            ->orderBy('created_at')
            ->get();

        $collected = $visitors->where('payment_status', 'Paid')->sum('admission_fee')
                   + $groups->where('payment_status', 'Paid')->sum('total_fee');

        $outstanding = $visitors->where('payment_status', 'Unpaid')->sum('admission_fee')
                     + $groups->where('payment_status', 'Unpaid')->sum('total_fee');

        return view('reports.logbook', [
            'date'        => $date,
            'visitors'    => $visitors,
            'groups'      => $groups,
            'headcount'   => $visitors->count() + $groups->sum('headcount'),
            'collected'   => $collected,
            'outstanding' => $outstanding,
        ]);
    }

    public function logbookCsv(Request $request): StreamedResponse
    {
        $date = $request->filled('date') ? Carbon::parse($request->input('date')) : today();

        $visitors = Visitor::whereDate('created_at', $date)->whereNull('group_id')->orderBy('created_at')->get();
        $groups   = VisitGroup::whereDate('visit_date', $date)->orderBy('created_at')->get();

        $rows = [['Time', 'Name', 'Type', 'Pax', 'From', 'Source', 'Fee', 'Payment']];

        foreach ($visitors as $v) {
            $rows[] = [
                $v->created_at->format('g:i A'), $v->full_name, $v->visitor_type, 1,
                $v->city ?: $v->country, $v->source,
                number_format((float) $v->admission_fee, 2), $v->payment_status,
            ];
        }

        foreach ($groups as $g) {
            $rows[] = [
                $g->created_at->format('g:i A'),
                ($g->group_name ?: $g->contact_name) . ' (group)',
                $g->visitor_type, $g->headcount,
                $g->city ?: $g->country, 'desk',
                number_format((float) $g->total_fee, 2), $g->payment_status,
            ];
        }

        return $this->csv($rows, 'logbook-' . $date->toDateString() . '.csv');
    }

    // -- Staff DTR ---------------------------------------------------------

    public function dtr(Request $request, Staff $staff)
    {
        // Only museum staff have hours to report on.
        abort_unless($staff->role === Staff::ROLE_ADMIN, 404);

        $month = $request->filled('month')
            ? Carbon::parse($request->input('month') . '-01')
            : today()->startOfMonth();

        $to = $month->copy()->endOfMonth();
        if ($to->isFuture()) {
            $to = today();
        }

        $days = $this->status->rangeFor($staff, $month->copy()->startOfMonth(), $to);

        return view('reports.dtr', [
            'staff'  => $staff,
            'month'  => $month,
            'days'   => $days,
            'totals' => [
                'present' => $days->where('status', AttendanceStatusService::PRESENT)->count(),
                'late'    => $days->where('status', AttendanceStatusService::LATE)->count(),
                'absent'  => $days->where('status', AttendanceStatusService::ABSENT)->count(),
                'manual'  => $days->where('is_manual', true)->count(),
                'minutes' => $days->sum('worked_minutes'),
            ],
        ]);
    }

    // -- Visitors and admission -------------------------------------------

    public function visitors(Request $request)
    {
        [$from, $to] = $this->range($request);

        $visitors = Visitor::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
        $groups   = VisitGroup::whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])->get();

        $daily = collect();
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $daily->push([
                'date'      => $d->copy(),
                'headcount' => $visitors->filter(fn ($v) => $v->created_at->toDateString() === $key && !$v->group_id)->count()
                             + $groups->filter(fn ($g) => $g->visit_date->toDateString() === $key)->sum('headcount'),
                'collected' => $visitors->filter(fn ($v) => $v->created_at->toDateString() === $key && $v->payment_status === 'Paid')->sum('admission_fee')
                             + $groups->filter(fn ($g) => $g->visit_date->toDateString() === $key && $g->payment_status === 'Paid')->sum('total_fee'),
            ]);
        }

        return view('reports.visitors', [
            'from'  => $from,
            'to'    => $to,
            'daily' => $daily,
            'byType'=> [
                'Local'   => $visitors->where('visitor_type', 'Local')->count()   + $groups->where('visitor_type', 'Local')->sum('headcount'),
                'Tourist' => $visitors->where('visitor_type', 'Tourist')->count() + $groups->where('visitor_type', 'Tourist')->sum('headcount'),
                'Foreign' => $visitors->where('visitor_type', 'Foreign')->count() + $groups->where('visitor_type', 'Foreign')->sum('headcount'),
            ],
            'bySource' => $visitors->groupBy('source')->map->count(),
            'totalHeads'  => $daily->sum('headcount'),
            'totalMoney'  => $daily->sum('collected'),
            'outstanding' => $visitors->where('payment_status', 'Unpaid')->sum('admission_fee')
                           + $groups->where('payment_status', 'Unpaid')->sum('total_fee'),
        ]);
    }

    // -- Feedback ----------------------------------------------------------

    public function feedback(Request $request)
    {
        [$from, $to] = $this->range($request);

        $rows = Feedback::with(['staff', 'visitor'])
            ->whereBetween('submitted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        // Guide ratings are reported as a count and an average, never ranked:
        // guided tours here are rare enough that a league table would be noise.
        $byGuide = $rows->whereNotNull('staff_id')
            ->groupBy('staff_id')
            ->map(fn ($group) => [
                'staff'  => $group->first()->staff,
                'count'  => $group->count(),
                'avg'    => round($group->avg('guide_rating') ?: $group->avg('rating'), 2),
                'sparse' => $group->count() < 5,
            ])
            ->values();

        return view('reports.feedback', [
            'from'       => $from,
            'to'         => $to,
            'total'      => $rows->count(),
            'average'    => $rows->count() ? round($rows->avg('rating'), 2) : null,
            'breakdown'  => collect(range(5, 1))->mapWithKeys(fn ($n) => [$n => $rows->where('rating', $n)->count()]),
            'byGuide'    => $byGuide,
            'unattributed' => $rows->whereNull('staff_id')->count(),
            'recent'     => $rows->sortByDesc('submitted_at')->take(20),
        ]);
    }

    // -- Exhibit engagement ------------------------------------------------

    public function exhibits(Request $request)
    {
        [$from, $to] = $this->range($request);

        $scans = Scan::with('exhibit')
            ->whereBetween('scanned_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        return view('reports.exhibits', [
            'from'      => $from,
            'to'        => $to,
            'total'     => $scans->count(),
            'byExhibit' => $scans->groupBy('exhibit_id')
                ->map(fn ($g) => ['exhibit' => $g->first()->exhibit, 'count' => $g->count()])
                ->sortByDesc('count')->values(),
            'byLanguage' => $scans->groupBy('language_code')->map->count()->sortDesc(),
            'tours'      => Tour::whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count(),
        ]);
    }

    // -- Audit log export --------------------------------------------------

    public function auditCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->range($request);

        $rows = [['Timestamp', 'User', 'Role', 'Action', 'Details', 'IP']];

        Log::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('created_at')
            ->chunk(500, function ($chunk) use (&$rows) {
                foreach ($chunk as $log) {
                    $rows[] = [
                        $log->created_at->format('Y-m-d H:i:s'),
                        $log->user_name, $log->role, $log->action,
                        $log->details, $log->ip_address,
                    ];
                }
            });

        return $this->csv($rows, 'audit-log-' . $from->toDateString() . '-to-' . $to->toDateString() . '.csv');
    }

    // -- Shared ------------------------------------------------------------

    private function range(Request $request): array
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))
            : today()->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))
            : today();

        // A backwards range silently returns nothing, which reads as "no
        // visitors this month" rather than as the mistake it is.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    private function csv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            // Excel needs the BOM to read UTF-8 names correctly.
            fwrite($handle, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
