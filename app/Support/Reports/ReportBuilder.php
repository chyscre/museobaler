<?php

namespace App\Support\Reports;

use App\Models\Feedback;
use App\Models\Log;
use App\Models\Scan;
use App\Models\Staff;
use App\Models\SurveyQuestion;
use App\Models\Tour;
use App\Models\Visitor;
use App\Models\VisitGroup;
use App\Services\AttendanceStatusService;
use App\Support\CsmReport;
use Illuminate\Support\Carbon;

/**
 * Every report, reduced to rows.
 *
 * This exists so the nightly batch and the download buttons cannot disagree.
 * Before it, the CSV for a report was assembled inside the controller action
 * that served it - fine while CSV was the only export; with XLSX and DOCX
 * added it would have meant three copies of "what counts as a row" per
 * report, drifting apart the first time a column moved.
 *
 * The printed PDF deliberately does NOT come through here. It renders the
 * Blade view, because that layout is the one the museum already signs and
 * files, and a second hand-built layout would only diverge from it.
 */
class ReportBuilder
{
    public function __construct(private AttendanceStatusService $status) {}

    /** The reports that exist, and the formats each may be downloaded as. */
    public const FORMATS = [
        'logbook'  => ['csv', 'xlsx', 'pdf'],
        'dtr'      => ['csv', 'xlsx', 'pdf'],
        'visitors' => ['csv', 'xlsx', 'pdf'],
        'exhibits' => ['csv', 'docx', 'pdf'],
        'feedback' => ['csv', 'docx', 'pdf'],
        'audit'    => ['csv', 'pdf'],
    ];

    public static function supports(string $key, string $format): bool
    {
        return in_array($format, self::FORMATS[$key] ?? [], true);
    }

    // -- Daily logbook -----------------------------------------------------

    public function logbook(Carbon $date): ReportDataset
    {
        $visitors = Visitor::whereDate('created_at', $date)->whereNull('group_id')->orderBy('created_at')->get();
        $groups   = VisitGroup::whereDate('visit_date', $date)->orderBy('created_at')->get();

        $rows = [];

        foreach ($visitors as $v) {
            $rows[] = [
                $v->created_at->format('g:i A'), $v->full_name, $v->visitor_type, 1,
                $v->visitor_type === 'Local' ? 1 : 0,
                $v->city ?: $v->country, $v->source,
                (float) $v->admission_fee, 0.0, $v->payment_status,
            ];
        }

        foreach ($groups as $g) {
            $rows[] = [
                $g->created_at->format('g:i A'),
                ($g->group_name ?: $g->contact_name) . ' (group)',
                $g->visitor_type, (int) $g->headcount,
                $g->visitor_type === 'Local' ? (int) $g->headcount : (int) $g->local_count,
                $g->city ?: $g->country, 'desk',
                (float) $g->total_fee, (float) $g->refunded_amount,
                $g->payment_status,
            ];
        }

        $refunded  = (float) $groups->sum('refunded_amount');
        $collected = (float) ($visitors->where('payment_status', 'Paid')->sum('admission_fee')
                   + $groups->sum(fn (VisitGroup $g) => $g->grossCollected()));
        $headcount = (int) ($visitors->count() + $groups->sum('headcount'));

        return new ReportDataset(
            key: 'logbook',
            title: 'Daily Visitor Logbook',
            meta: $date->format('l, F j, Y'),
            filename: 'logbook-' . $date->toDateString(),
            sections: [new ReportSection(
                heading: null,
                columns: ['Time', 'Name', 'Type', 'Pax', 'Locals', 'From', 'Source', 'Fee', 'Refunded', 'Payment'],
                rows: $rows,
                footer: ['', 'TOTAL', '', $headcount, '', '', '', $collected, $refunded, ''],
                numeric: [3, 4, 7, 8],
            )],
            summary: [
                'Headcount' => (string) $headcount,
                'Collected' => number_format($collected, 2),
                'Refunded'  => number_format($refunded, 2),
                'Net'       => number_format($collected - $refunded, 2),
            ],
        );
    }

    // -- Staff DTR ---------------------------------------------------------

    public function dtr(Staff $staff, Carbon $month): ReportDataset
    {
        $to = $month->copy()->endOfMonth();
        if ($to->isFuture()) {
            $to = today();
        }

        $days = $this->status->rangeFor($staff, $month->copy()->startOfMonth(), $to);

        $rows = [];
        foreach ($days as $day) {
            $schedule = $day['schedule'] ?? null;
            $shift = $schedule && !$schedule->is_rest_day
                ? Carbon::parse($schedule->shift_start)->format('g:i A') . '-' . Carbon::parse($schedule->shift_end)->format('g:i A')
                : ($schedule ? 'Rest day' : '');

            $rows[] = [
                $day['date']->format('Y-m-d'),
                $day['date']->format('D'),
                $shift,
                $day['in']?->scanned_at->format('g:i A') ?? '',
                $day['out']?->scanned_at->format('g:i A') ?? '',
                $day['worked_minutes'] !== null ? round($day['worked_minutes'] / 60, 2) : null,
                (int) ($day['late_minutes'] ?? 0),
                $day['status'],
                $day['is_manual'] ? 'Manual' : '',
            ];
        }

        $minutes = (int) $days->sum('worked_minutes');

        return new ReportDataset(
            key: 'dtr',
            title: 'Daily Time Record',
            meta: $staff->name . ' | ' . $staff->role_label . ' | ' . $month->format('F Y'),
            filename: 'dtr-' . str($staff->name)->slug() . '-' . $month->format('Y-m'),
            sections: [new ReportSection(
                heading: null,
                columns: ['Date', 'Day', 'Schedule', 'Time In', 'Time Out', 'Hours', 'Late (min)', 'Status', 'Source'],
                rows: $rows,
                footer: ['', '', '', '', 'TOTAL', round($minutes / 60, 2), '', '', ''],
                numeric: [5, 6],
            )],
            summary: [
                'Present'      => (string) $days->where('status', AttendanceStatusService::PRESENT)->count(),
                'Late'         => (string) $days->where('status', AttendanceStatusService::LATE)->count(),
                'Absent'       => (string) $days->where('status', AttendanceStatusService::ABSENT)->count(),
                'Manual entry' => (string) $days->where('is_manual', true)->count(),
                'Total hours'  => intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm',
            ],
        );
    }

    // -- Visitors and admission -------------------------------------------

    public function visitors(Carbon $from, Carbon $to): ReportDataset
    {
        $visitors = Visitor::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
        $groups   = VisitGroup::whereBetween('visit_date', [$from->toDateString(), $to->toDateString()])->get();

        $daily = [];
        $heads = 0;
        $money = 0.0;

        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key   = $d->toDateString();
            $count = $visitors->filter(fn ($v) => $v->created_at->toDateString() === $key && !$v->group_id)->count()
                   + $groups->filter(fn ($g) => $g->visit_date->toDateString() === $key)->sum('headcount');
            $sum   = $visitors->filter(fn ($v) => $v->created_at->toDateString() === $key && $v->payment_status === 'Paid')->sum('admission_fee')
                   + $groups->filter(fn ($g) => $g->visit_date->toDateString() === $key && $g->payment_status === 'Paid')->sum('total_fee');

            $daily[] = [$d->format('Y-m-d'), $d->format('D'), (int) $count, (float) $sum];
            $heads  += (int) $count;
            $money  += (float) $sum;
        }

        $byType = [
            'Local'   => $visitors->where('visitor_type', 'Local')->count()   + $groups->where('visitor_type', 'Local')->sum('headcount'),
            'Tourist' => $visitors->where('visitor_type', 'Tourist')->count() + $groups->where('visitor_type', 'Tourist')->sum('headcount'),
            'Foreign' => $visitors->where('visitor_type', 'Foreign')->count() + $groups->where('visitor_type', 'Foreign')->sum('headcount'),
        ];

        $typeRows = [];
        foreach ($byType as $type => $n) {
            $typeRows[] = [$type, (int) $n, $heads ? round($n / $heads * 100, 1) : 0];
        }

        $sourceRows = [];
        foreach ($visitors->groupBy('source')->map->count() as $source => $n) {
            $sourceRows[] = [$source ?: 'unknown', (int) $n];
        }

        $outstanding = (float) ($visitors->where('payment_status', 'Unpaid')->sum('admission_fee')
                     + $groups->where('payment_status', 'Unpaid')->sum('total_fee'));

        return new ReportDataset(
            key: 'visitors',
            title: 'Visitors and Admission',
            meta: $from->format('F j, Y') . ' - ' . $to->format('F j, Y'),
            filename: 'visitors-' . $from->toDateString() . '-to-' . $to->toDateString(),
            sections: [
                new ReportSection('Daily totals', ['Date', 'Day', 'Headcount', 'Collected'], $daily,
                    ['', 'TOTAL', $heads, $money], [2, 3]),
                new ReportSection('By visitor type', ['Type', 'Headcount', 'Share %'], $typeRows, [], [1, 2]),
                new ReportSection('By registration source', ['Source', 'Count'], $sourceRows, [], [1]),
            ],
            summary: [
                'Total headcount' => (string) $heads,
                'Total collected' => number_format($money, 2),
                'Outstanding'     => number_format($outstanding, 2),
            ],
        );
    }

    // -- Exhibit engagement ------------------------------------------------

    public function exhibits(Carbon $from, Carbon $to): ReportDataset
    {
        $scans = Scan::with('exhibit')
            ->whereBetween('scanned_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        $total = $scans->count();

        $byExhibit = $scans->groupBy('exhibit_id')
            ->map(fn ($g) => ['exhibit' => $g->first()->exhibit, 'count' => $g->count()])
            ->sortByDesc('count')->values();

        $exhibitRows = [];
        foreach ($byExhibit as $row) {
            $exhibitRows[] = [
                $row['exhibit']?->name ?? 'Removed exhibit',
                $row['exhibit']?->exhibit_code ?? '',
                (int) $row['count'],
                $total ? round($row['count'] / $total * 100, 1) : 0,
            ];
        }

        $langRows = [];
        foreach ($scans->groupBy('language_code')->map->count()->sortDesc() as $code => $n) {
            $langRows[] = [$code ?: 'unknown', (int) $n];
        }

        return new ReportDataset(
            key: 'exhibits',
            title: 'Exhibit Engagement',
            meta: $from->format('F j, Y') . ' - ' . $to->format('F j, Y'),
            filename: 'exhibits-' . $from->toDateString() . '-to-' . $to->toDateString(),
            sections: [
                new ReportSection('Scans by exhibit', ['Exhibit', 'Code', 'Scans', 'Share %'], $exhibitRows,
                    ['TOTAL', '', $total, ''], [2, 3]),
                new ReportSection('Scans by language', ['Language', 'Scans'], $langRows, [], [1]),
            ],
            summary: [
                'Total scans'     => (string) $total,
                'Exhibits viewed' => (string) $byExhibit->count(),
                'Guided tours'    => (string) Tour::whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count(),
            ],
        );
    }

    // -- Feedback / CSM ----------------------------------------------------

    public function feedback(Carbon $from, Carbon $to): ReportDataset
    {
        $all = Feedback::with(['staff', 'visitor', 'answers'])
            ->whereBetween('submitted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        $csm = CsmReport::build($all);

        // The wide per-response sheet, in the column order of the paper tally
        // sheet, so the office can paste it into its ARTA submission. N/A is
        // written out rather than left blank, so it cannot be mistaken for a
        // question nobody answered.
        $rows = $all->filter(fn ($f) => $f->answers->isNotEmpty())->sortBy('submitted_at')->values();

        $codes = SurveyQuestion::orderBy('sort_order')->pluck('code')
            ->merge($rows->flatMap->answers->pluck('code')->unique())
            ->unique()
            ->filter(fn ($c) => $rows->flatMap->answers->contains('code', $c))
            ->values();

        $responseRows = [];
        foreach ($rows as $f) {
            $byCode = $f->answers->keyBy('code');
            $line = [
                $f->feedback_id,
                $f->submitted_at?->format('Y-m-d H:i'),
                $f->client_type ?: '',
                $f->visitor?->sex ?: '',
                $f->visitor?->age ?: '',
                $f->region ?: '',
                $f->visitor?->visitor_type ?: '',
            ];
            foreach ($codes as $code) {
                $a = $byCode->get($code);
                $line[] = $a === null ? '' : ($a->value === null ? 'N/A' : $a->value);
            }
            $line[] = $f->rating;
            $line[] = $f->comment ?: '';
            $responseRows[] = $line;
        }

        $starRows = [];
        foreach (range(5, 1) as $n) {
            $starRows[] = [$n . ' star', $all->where('rating', $n)->count()];
        }

        $sqdRows = [];
        foreach ($csm['sqd'] as $q) {
            $sqdRows[] = [
                $q['code'], $q['text'], (int) $q['responses'], (int) $q['satisfied'],
                $q['score'], $q['mean'], (int) $q['na'],
            ];
        }

        return new ReportDataset(
            key: 'feedback',
            title: 'Visitor Feedback and CSM',
            meta: $from->format('F j, Y') . ' - ' . $to->format('F j, Y'),
            filename: 'csm-' . $from->toDateString() . '-to-' . $to->toDateString(),
            sections: [
                new ReportSection('Service quality dimensions',
                    ['Code', 'Question', 'Responses', 'Satisfied', 'Score %', 'Mean', 'N/A'],
                    $sqdRows, [], [2, 3, 4, 5, 6]),
                new ReportSection('Star rating breakdown', ['Rating', 'Count'], $starRows, [], [1]),
                new ReportSection('Responses',
                    array_merge(
                        ['Control No.', 'Date', 'Client type', 'Sex', 'Age', 'Region', 'Visitor type'],
                        $codes->all(),
                        ['Star rating', 'Suggestions']
                    ),
                    $responseRows, [], [4]),
            ],
            summary: [
                'Responses'      => (string) $all->count(),
                'Average rating' => $all->count() ? (string) round($all->avg('rating'), 2) : '-',
                'CSM score'      => $csm['sqd_score'] !== null ? $csm['sqd_score'] . '%' : '-',
                'CSM rating'     => CsmReport::rating($csm['sqd_score']) ?: '-',
                'CC awareness'   => $csm['cc_awareness'] !== null ? $csm['cc_awareness'] . '%' : '-',
            ],
            // The per-response sheet, not the summaries: this is the file the
            // office pastes into its ARTA submission, and it was the whole of
            // the feedback CSV before the other formats existed.
            primary: 2,
        );
    }

    // -- Audit trail -------------------------------------------------------

    public function audit(Carbon $from, Carbon $to): ReportDataset
    {
        $rows = [];

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

        return new ReportDataset(
            key: 'audit',
            title: 'Audit Trail',
            meta: $from->format('F j, Y') . ' - ' . $to->format('F j, Y'),
            filename: 'audit-log-' . $from->toDateString() . '-to-' . $to->toDateString(),
            sections: [new ReportSection(
                heading: null,
                columns: ['Timestamp', 'User', 'Role', 'Action', 'Details', 'IP'],
                rows: $rows,
            )],
            summary: ['Entries' => (string) count($rows)],
        );
    }
}
