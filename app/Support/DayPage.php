<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;

/**
 * One day per page.
 *
 * The logbook and the audit trail are read a day at a time - "what happened
 * on Tuesday" - not a hundred rows at a time. Paging by row count split a
 * busy day across two pages and put a quiet week on one, so neither page
 * answered that question. Here a page *is* a day: every row for that date,
 * however many, and the pager steps one day back or forward through the days
 * that actually have rows. Days with nothing in them are skipped rather than
 * shown as empty pages.
 */
class DayPage
{
    /**
     * @param  Builder  $query   the filtered query; it is cloned, not consumed
     * @param  string   $column  the date/datetime column the day comes from
     * @param  bool     $newestFirst  which end of the calendar page 1 is
     */
    public static function of(Builder $query, string $column, bool $newestFirst = true, string $pageName = 'page'): self
    {
        return new self($query, $column, $newestFirst, $pageName);
    }

    public readonly ?Carbon $date;

    /** The day either side of this one, or null at the ends of the list. */
    public readonly ?Carbon $previous;
    public readonly ?Carbon $next;

    /** Every row on this day, in the query's own order. */
    public readonly mixed $rows;

    /** Stands in for a normal paginator: ->links(), ->total(), ->currentPage(). */
    public readonly LengthAwarePaginator $pages;

    private function __construct(Builder $query, string $column, bool $newestFirst, string $pageName)
    {
        // The days that actually have rows, under whatever filters are on.
        // Pulled as a plain list of dates: one short column, and a museum's
        // opening days are counted in hundreds, not millions.
        $days = (clone $query)
            ->reorder()
            ->selectRaw("DATE({$column}) as day")
            ->whereNotNull($column)
            ->distinct()
            ->orderBy('day', $newestFirst ? 'desc' : 'asc')
            ->pluck('day')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->values();

        $page = max(1, (int) Paginator::resolveCurrentPage($pageName));
        $page = min($page, max(1, $days->count()));

        $this->date     = $days->isEmpty() ? null : Carbon::parse($days[$page - 1]);
        $this->previous = isset($days[$page - 2]) ? Carbon::parse($days[$page - 2]) : null;
        $this->next     = isset($days[$page])     ? Carbon::parse($days[$page])     : null;

        $this->rows = $this->date
            ? (clone $query)->whereDate($column, $this->date->toDateString())->get()
            : collect();

        // perPage 1: the paginator counts days, so its "page 3 of 12" reads
        // as the third day of twelve, and prev/next step one day.
        $this->pages = new LengthAwarePaginator(
            [$this->date],
            $days->count(),
            1,
            $page,
            [
                'path'     => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        );
    }

    /** The URL for one of the neighbouring days. */
    public function urlFor(?Carbon $day): ?string
    {
        if (!$day) {
            return null;
        }

        return $this->pages->url($this->pages->currentPage() + ($this->previous && $day->equalTo($this->previous) ? -1 : 1));
    }

    /** "Today", "Yesterday", or "Tue, 23 Sep 2026". */
    public function label(): string
    {
        if (!$this->date) {
            return 'No records yet';
        }
        if ($this->date->isToday()) {
            return 'Today';
        }
        if ($this->date->isYesterday()) {
            return 'Yesterday';
        }

        return $this->date->format('D, j M Y');
    }

    public function count(): int
    {
        return $this->rows->count();
    }

    public function isEmpty(): bool
    {
        return $this->rows->isEmpty();
    }
}
