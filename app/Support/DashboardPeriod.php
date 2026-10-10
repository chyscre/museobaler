<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The stretch of time the dashboard is reporting on.
 *
 * Every tile, chart and table on the dashboard reads the same period from the
 * same query string - the page and the two chart endpoints each build one of
 * these - so the visitor trend can never be showing October while the
 * exhibit rankings beside it are still counting the whole year.
 *
 *   ?period=year&year=2026
 *   ?period=month&year=2026&month=9
 *   ?period=week&week=2026-09-28      (any day in the week; weeks start Monday)
 *   ?period=range&from=2026-09-01&to=2026-09-15
 *
 * Anything missing or unreadable falls back to the current year, week or
 * month rather than to an error page: these are dropdowns, and a half-filled
 * one should still show something sensible.
 *
 * Records and Logs filter their tables by the same control. They open on the
 * current week rather than the year: that includes today, which is what the
 * desk looks for first, and keeps the list short.
 */
class DashboardPeriod
{
    public const PERIODS = [
        'year'  => 'By Year',
        'month' => 'By Month',
        'week'  => 'By Week',
        'range' => 'Custom Range',
    ];

    /** Past this many days a daily chart turns into a barcode; go monthly. */
    private const DAILY_LIMIT = 62;

    private function __construct(
        public readonly string $period,
        public readonly Carbon $from,
        public readonly Carbon $to,
    ) {}

    public static function fromRequest(Request $request, string $default = 'year'): self
    {
        $period = array_key_exists($request->query('period'), self::PERIODS)
            ? $request->query('period')
            : $default;

        $year = (int) $request->query('year', today()->year);
        if ($year < 2000 || $year > today()->year + 1) {
            $year = today()->year;
        }

        switch ($period) {
            case 'month':
                $month = (int) $request->query('month', today()->month);
                if ($month < 1 || $month > 12) {
                    $month = today()->month;
                }
                $from = Carbon::create($year, $month, 1)->startOfMonth();
                return new self($period, $from, $from->copy()->endOfMonth());

            case 'week':
                $day  = self::date($request->query('week')) ?? today();
                $from = $day->copy()->startOfWeek(Carbon::MONDAY);
                return new self($period, $from, $from->copy()->endOfWeek(Carbon::SUNDAY));

            case 'range':
                $from = self::date($request->query('from')) ?? today()->startOfMonth();
                $to   = self::date($request->query('to')) ?? today();
                // A backwards range silently returns nothing, which reads as
                // "no visitors" rather than as the slip it is.
                if ($from->greaterThan($to)) {
                    [$from, $to] = [$to, $from];
                }
                return new self($period, $from->startOfDay(), $to->endOfDay());

            default:
                $from = Carbon::create($year, 1, 1)->startOfYear();
                return new self('year', $from, $from->copy()->endOfYear());
        }
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** [start, end] for whereBetween on a datetime column. */
    public function bounds(): array
    {
        return [$this->from, $this->to];
    }

    /** The query string that reproduces this period, for links and fetches. */
    public function query(): array
    {
        return match ($this->period) {
            'month' => ['period' => 'month', 'year' => $this->from->year, 'month' => $this->from->month],
            'week'  => ['period' => 'week', 'week' => $this->from->toDateString()],
            'range' => ['period' => 'range', 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString()],
            default => ['period' => 'year', 'year' => $this->from->year],
        };
    }

    /** "2026", "September 2026", "Sep 28 – Oct 4, 2026". */
    public function label(): string
    {
        return match ($this->period) {
            'year'  => (string) $this->from->year,
            'month' => $this->from->format('F Y'),
            default => $this->from->isSameDay($this->to)
                ? $this->from->format('M j, Y')
                : ($this->from->year === $this->to->year
                    ? $this->from->format('M j').' – '.$this->to->format('M j, Y')
                    : $this->from->format('M j, Y').' – '.$this->to->format('M j, Y')),
        };
    }

    /** Years offered in the dropdowns: this one back to the first visitor. */
    public static function years(): array
    {
        $first = \App\Models\Visitor::min('created_at');
        $start = $first ? (int) substr((string) $first, 0, 4) : today()->year;

        return range(today()->year, min($start, today()->year));
    }

    /** Whether the trend chart counts per day or per month. */
    public function daily(): bool
    {
        return $this->period !== 'year'
            && $this->from->diffInDays($this->to) < self::DAILY_LIMIT;
    }

    /**
     * Ordered chart buckets: key => axis label. Keys are 'Y-m-d' when daily,
     * 'Y-m' when monthly, and match what bucketOf() returns for a timestamp.
     */
    public function buckets(): array
    {
        $daily   = $this->daily();
        $buckets = [];
        $cursor  = $daily ? $this->from->copy()->startOfDay() : $this->from->copy()->startOfMonth();
        $spansYears = $this->from->year !== $this->to->year;

        while ($cursor->lte($this->to)) {
            if ($daily) {
                $buckets[$cursor->format('Y-m-d')] = $this->period === 'week'
                    ? $cursor->format('D j')
                    : $cursor->format('M j');
                $cursor->addDay();
            } else {
                $buckets[$cursor->format('Y-m')] = $spansYears ? $cursor->format('M Y') : $cursor->format('M');
                $cursor->addMonthNoOverflow();
            }
        }

        return $buckets;
    }

    public function bucketOf(Carbon $at): string
    {
        return $at->format($this->daily() ? 'Y-m-d' : 'Y-m');
    }
}
