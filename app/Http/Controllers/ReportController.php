<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\Log;
use App\Models\Scan;
use App\Models\Staff;
use App\Models\Tour;
use App\Models\Visitor;
use App\Models\VisitGroup;
use App\Models\SurveyQuestion;
use App\Services\AttendanceStatusService;
use App\Support\CsmReport;
use App\Support\Reports\CsvExporter;
use App\Support\Reports\DocxExporter;
use App\Support\Reports\PdfExporter;
use App\Support\Reports\ReportBuilder;
use App\Support\Reports\XlsxExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports for the Tourism office.
 *
 * Each report is a page styled for print, which the museum can still send
 * straight to a printer, plus downloads in the formats that report is
 * actually wanted in - see ReportBuilder::FORMATS.
 *
 * Exports come in two tiers, and the split is deliberate:
 *
 *   - CSV is the machine tier. Plain rows, no letterhead, no totals, written
 *     nightly to storage by ReportsExport as well as offered here, so that
 *     anything reading this system's numbers has a file to read that does
 *     not change shape when somebody restyles a report.
 *   - XLSX, DOCX and PDF are the human tier: the museum's own letterhead on
 *     top, totals at the bottom, and a choice between working with the
 *     figures, editing the write-up, or forwarding something printable.
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

        return view('reports.logbook', $this->logbookData($date));
    }

    /**
     * The logbook page's own view-model.
     *
     * Split out from the action because the PDF export renders this same
     * view, and a PDF built from a second, near-identical query would be a
     * document that disagreed with the screen it was printed from.
     */
    private function logbookData(Carbon $date): array
    {
        $visitors = Visitor::with('registeredBy')
            ->whereDate('created_at', $date)
            ->whereNull('group_id')
            ->orderBy('created_at')
            ->get();

        $groups = VisitGroup::with('registeredBy')
            ->whereDate('visit_date', $date)
            ->orderBy('created_at')
            ->get();

        // Collected is what came in over the counter - for a group that was
        // corrected after paying, that is the fee it owes now PLUS what was
        // handed back, because both of those crossed the counter. Refunded is
        // the part that went back. Net is what the drawer should hold.
        $refunded = (float) $groups->sum('refunded_amount');

        $collected = $visitors->where('payment_status', 'Paid')->sum('admission_fee')
                   + $groups->sum(fn (VisitGroup $g) => $g->grossCollected());

        $outstanding = $visitors->where('payment_status', 'Unpaid')->sum('admission_fee')
                     + $groups->where('payment_status', 'Unpaid')->sum('total_fee');

        return [
            'date'        => $date,
            'visitors'    => $visitors,
            'groups'      => $groups,
            'headcount'   => $visitors->count() + $groups->sum('headcount'),
            'collected'   => $collected,
            'refunded'    => $refunded,
            'net'         => $collected - $refunded,
            'outstanding' => $outstanding,
        ];
    }

    // -- Staff DTR ---------------------------------------------------------

    public function dtr(Request $request, Staff $staff)
    {
        // Only museum staff have hours to report on.
        abort_unless($staff->role === Staff::ROLE_ADMIN, 404);

        $month = $request->filled('month')
            ? Carbon::parse($request->input('month') . '-01')
            : today()->startOfMonth();

        return view('reports.dtr', $this->dtrData($staff, $month));
    }

    private function dtrData(Staff $staff, Carbon $month): array
    {
        $to = $month->copy()->endOfMonth();
        if ($to->isFuture()) {
            $to = today();
        }

        $days = $this->status->rangeFor($staff, $month->copy()->startOfMonth(), $to);

        return [
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
        ];
    }

    // -- Visitors and admission -------------------------------------------

    public function visitors(Request $request)
    {
        [$from, $to] = $this->range($request);

        return view('reports.visitors', $this->visitorsData($from, $to));
    }

    private function visitorsData(Carbon $from, Carbon $to): array
    {
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

        return [
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
        ];
    }

    // -- Feedback ----------------------------------------------------------

    public function feedback(Request $request)
    {
        [$from, $to] = $this->range($request);

        return view('reports.feedback', $this->feedbackData($from, $to));
    }

    private function feedbackData(Carbon $from, Carbon $to): array
    {
        $rows = Feedback::with(['staff', 'visitor', 'answers'])
            ->whereBetween('submitted_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        // The ARTA Client Satisfaction Measurement figures for the range,
        // computed as the ARTA guidelines define them (see CsmReport).
        $csm = CsmReport::build($rows);

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

        return [
            'from'       => $from,
            'to'         => $to,
            'total'      => $rows->count(),
            'average'    => $rows->count() ? round($rows->avg('rating'), 2) : null,
            'breakdown'  => collect(range(5, 1))->mapWithKeys(fn ($n) => [$n => $rows->where('rating', $n)->count()]),
            'byGuide'    => $byGuide,
            'unattributed' => $rows->whereNull('staff_id')->count(),
            'recent'     => $rows->sortByDesc('submitted_at')->take(20),
            'csm'        => $csm,
            'csmRating'  => CsmReport::rating($csm['sqd_score']),
        ];
    }

    // -- Exhibit engagement ------------------------------------------------

    public function exhibits(Request $request)
    {
        [$from, $to] = $this->range($request);

        return view('reports.exhibits', $this->exhibitsData($from, $to));
    }

    private function exhibitsData(Carbon $from, Carbon $to): array
    {
        $scans = Scan::with('exhibit')
            ->whereBetween('scanned_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        return [
            'from'      => $from,
            'to'        => $to,
            'total'     => $scans->count(),
            'byExhibit' => $scans->groupBy('exhibit_id')
                ->map(fn ($g) => ['exhibit' => $g->first()->exhibit, 'count' => $g->count()])
                ->sortByDesc('count')->values(),
            'byLanguage' => $scans->groupBy('language_code')->map->count()->sortDesc(),
            'tours'      => Tour::whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->count(),
        ];
    }

    // -- Downloads ---------------------------------------------------------

    /**
     * One report, one format.
     *
     * The second of the two export tiers. The first is the nightly CSV that
     * ReportsExport writes for anything reading this system's numbers; this
     * is the one a person clicks, which is why it offers a spreadsheet and a
     * PDF and the batch tier does not - nobody is pasting a PDF into a
     * pipeline, and nobody forwards a CSV to the mayor's office.
     *
     * Which formats each report allows is ReportBuilder::FORMATS. Asking for
     * one that is not on the list is a 404 rather than a silent fallback: a
     * link to .docx that quietly returns .csv is a bug that survives to
     * whoever opens the attachment.
     */
    public function export(Request $request, string $report, string $format)
    {
        abort_unless(ReportBuilder::supports($report, $format), 404);

        $builder = app(ReportBuilder::class);

        [$dataset, $view, $viewData] = $this->resolve($request, $report, $builder);

        return match ($format) {
            'csv'  => app(CsvExporter::class)->stream($dataset),
            'xlsx' => app(XlsxExporter::class)->stream($dataset),
            'docx' => app(DocxExporter::class)->stream($dataset),
            'pdf'  => app(PdfExporter::class)->stream($dataset, $view, $viewData),
        };
    }

    /**
     * What the chosen format will contain, before committing to a download.
     *
     * A PDF is handed back as a PDF, inline rather than as an attachment,
     * because the browser renders it and nothing beats seeing the real
     * thing. XLSX and DOCX are container formats no browser can display,
     * and CSV would download rather than render, so those come back as an
     * HTML stand-in built from the same ReportDataset the writer uses -
     * meaning the rows shown are the rows in the file, and only the styling
     * is an approximation. The view says as much.
     */
    public function preview(Request $request, string $report, string $format)
    {
        abort_unless(ReportBuilder::supports($report, $format), 404);

        $builder = app(ReportBuilder::class);

        [$dataset, $view, $viewData] = $this->resolve($request, $report, $builder);

        if ($format === 'pdf') {
            return response(app(PdfExporter::class)->raw($dataset, $view, $viewData), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $dataset->filenameFor('pdf') . '"',
            ]);
        }

        if ($format === 'csv') {
            $section = $dataset->primarySection();
            $rows    = array_slice($section->rows, 0, self::PREVIEW_ROWS);

            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, $section->columns);
            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($c) => $c === null ? '' : $c, $row));
            }
            rewind($handle);
            $csv = stream_get_contents($handle);
            fclose($handle);

            return view('reports.partials.preview', [
                'format'    => 'csv',
                'csv'       => $csv,
                'truncated' => count($section->rows) > self::PREVIEW_ROWS,
                'remaining' => max(0, count($section->rows) - self::PREVIEW_ROWS),
                'sections'  => [],
            ]);
        }

        return view('reports.partials.preview', [
            'format'    => $format,
            'csv'       => '',
            'truncated' => false,
            'remaining' => 0,
            'sections'  => array_map(fn ($s) => [
                'heading' => $s->heading,
                'columns' => $s->columns,
                'rows'    => $s->rows,
            ], $dataset->sections),
        ]);
    }

    /**
     * The audit trail, in its own action.
     *
     * Not a `->defaults('report', 'audit')` on the shared route: Laravel
     * fills a controller's scalar arguments POSITIONALLY, so a default
     * lands after the path parameters rather than in the slot that shares
     * its name. That bound $report to the format and $format to "audit",
     * and the mismatch surfaced as a 404 on a route that had matched
     * perfectly well.
     */
    public function exportAudit(Request $request, string $format)
    {
        return $this->export($request, 'audit', $format);
    }

    public function previewAudit(Request $request, string $format)
    {
        return $this->preview($request, 'audit', $format);
    }

    /**
     * The CSV links that existed before the other formats did.
     *
     * Kept serving the file rather than redirecting to the new URL: half a
     * dozen views point at these, an office bookmarks an export it runs
     * every week, and anything fetching one on a schedule may not follow a
     * 302. They are thin wrappers, not a second code path.
     */
    public function logbookCsv(Request $request)
    {
        return $this->export($request, 'logbook', 'csv');
    }

    public function feedbackCsv(Request $request)
    {
        return $this->export($request, 'feedback', 'csv');
    }

    public function auditCsv(Request $request)
    {
        return $this->export($request, 'audit', 'csv');
    }

    /**
     * One report's dataset, plus the view and view-data a PDF needs.
     *
     * Shared by the download and the preview so that what is previewed is
     * built the same way as what is saved - the point of a preview being
     * that it is not a second opinion.
     *
     * @return array{0: \App\Support\Reports\ReportDataset, 1: string, 2: array}
     */
    private function resolve(Request $request, string $report, ReportBuilder $builder): array
    {
        // PDF renders the Blade view, so it needs the view's own data, not
        // the flat dataset. Everything else needs only the dataset.
        return match ($report) {
            'logbook'  => $this->forLogbook($request, $builder),
            'dtr'      => $this->forDtr($request, $builder),
            'visitors' => $this->forVisitors($request, $builder),
            'exhibits' => $this->forExhibits($request, $builder),
            'feedback' => $this->forFeedback($request, $builder),
            'audit'    => $this->forAudit($request, $builder),
        };
    }

    private function forLogbook(Request $request, ReportBuilder $builder): array
    {
        $date = $request->filled('date') ? Carbon::parse($request->input('date')) : today();

        return [$builder->logbook($date), 'reports.logbook', $this->logbookData($date)];
    }

    private function forDtr(Request $request, ReportBuilder $builder): array
    {
        $staff = Staff::findOrFail($request->integer('staff'));
        abort_unless($staff->role === Staff::ROLE_ADMIN, 404);

        $month = $request->filled('month')
            ? Carbon::parse($request->input('month') . '-01')
            : today()->startOfMonth();

        return [$builder->dtr($staff, $month), 'reports.dtr', $this->dtrData($staff, $month)];
    }

    private function forVisitors(Request $request, ReportBuilder $builder): array
    {
        [$from, $to] = $this->range($request);

        return [$builder->visitors($from, $to), 'reports.visitors', $this->visitorsData($from, $to)];
    }

    private function forExhibits(Request $request, ReportBuilder $builder): array
    {
        [$from, $to] = $this->range($request);

        return [$builder->exhibits($from, $to), 'reports.exhibits', $this->exhibitsData($from, $to)];
    }

    private function forFeedback(Request $request, ReportBuilder $builder): array
    {
        [$from, $to] = $this->range($request);

        return [$builder->feedback($from, $to), 'reports.feedback', $this->feedbackData($from, $to)];
    }

    private function forAudit(Request $request, ReportBuilder $builder): array
    {
        [$from, $to] = $this->range($request);

        // The printed trail is capped. A year of activity is tens of
        // thousands of lines, and a PDF of that is not a document anyone
        // reads - it is a CSV that took four minutes to render. The page
        // says so when it truncates.
        $query = Log::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        $total = (clone $query)->count();
        $rows  = $query->orderBy('created_at')->limit(self::AUDIT_PRINT_LIMIT)->get();

        return [$builder->audit($from, $to), 'reports.audit', [
            'from'      => $from,
            'to'        => $to,
            'rows'      => $rows,
            'total'     => $total,
            'truncated' => $total > self::AUDIT_PRINT_LIMIT,
        ]];
    }

    // -- Shared ------------------------------------------------------------

    /** How many audit lines a printed trail will carry before it gives up. */
    private const AUDIT_PRINT_LIMIT = 2000;

    /** How many rows a CSV preview shows before saying "and more". */
    private const PREVIEW_ROWS = 40;

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
}
