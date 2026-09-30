<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One day per page.
 *
 * The logbook and the audit trail are read a day at a time - "what happened
 * on Tuesday" - not a hundred rows at a time. Paging by row count split a
 * busy day across two pages and put a quiet week on one, so neither page
 * answered that question. Here a page *is* a day: every row for that date,
 * however many, and the pager steps one day back or forward through the days
 * that actually have rows. Days with nothing in them are skipped rather than
 * shown as empty pages - unless somebody asks for one by date, in which case
 * they get that day, empty, rather than being quietly sent to another.
 */
class DayPage
{
    /** The query-string key that asks for a day by its date. */
    public const DATE_PARAM = 'day';

    /**
     * Rows belong to the day one column falls on.
     *
     * @param  Builder  $query   the filtered query; it is cloned, not consumed
     * @param  string   $column  the date/datetime column the day comes from
     * @param  bool     $newestFirst  which end of the calendar page 1 is
     */
    public static function of(Builder $query, string $column, bool $newestFirst = true, string $pageName = 'page'): self
    {
        return self::using(
            $query,
            days: fn (Builder $q) => (clone $q)
                ->reorder()
                ->selectRaw("DATE({$column}) as day")
                ->whereNotNull($column)
                ->distinct()
                ->pluck('day'),
            onDay: fn (Builder $q, string $date) => $q->whereDate($column, $date),
            newestFirst: $newestFirst,
            pageName: $pageName,
        );
    }

    /**
     * Rows belong to whichever days the caller says - for records that can
     * sit on more than one day, like a visitor who registered on Monday and
     * came back on Thursday.
     *
     * @param  Closure(Builder): iterable  $days   every day with rows, any order, duplicates fine
     * @param  Closure(Builder, string): mixed  $onDay  narrows the query to one Y-m-d day
     */
    public static function using(Builder $query, Closure $days, Closure $onDay, bool $newestFirst = true, string $pageName = 'page'): self
    {
        return new self($query, $days, $onDay, $newestFirst, $pageName);
    }

    public readonly ?Carbon $date;

    /** The day either side of this one, or null at the ends of the list. */
    public readonly ?Carbon $previous;
    public readonly ?Carbon $next;

    /** Every row on this day, in the query's own order. */
    public readonly mixed $rows;

    /** Stands in for a normal paginator: ->links(), ->total(), ->currentPage(). */
    public readonly LengthAwarePaginator $pages;

    private function __construct(Builder $query, Closure $days, Closure $onDay, bool $newestFirst, string $pageName)
    {
        // The days that actually have rows, under whatever filters are on.
        // Pulled as a plain list of dates: one short column, and a museum's
        // opening days are counted in hundreds, not millions.
        /** @var Collection<int, string> $days */
        $days = collect($days($query))
            ->filter()
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->sort()
            ->values();
        if ($newestFirst) {
            $days = $days->reverse()->values();
        }

        // A day picked from the calendar wins over the page number. It may
        // be a day with nothing in it - then that is what the page says -
        // but never one in the future, which cannot have happened yet.
        $asked = self::askedFor();

        if ($asked) {
            $date = $asked;
            $page = $days->search($date);
            $page = $page === false ? $this->insertionPoint($days, $date, $newestFirst) : $page + 1;
        } else {
            $page = max(1, (int) Paginator::resolveCurrentPage($pageName));
            $page = min($page, max(1, $days->count()));
            $date = $days[$page - 1] ?? null;
        }

        $this->date = $date ? Carbon::parse($date) : null;

        // The neighbours are the nearest days WITH rows on either side, which
        // for a picked empty day means the arrows still lead somewhere.
        $before = $date ? $days->filter(fn ($d) => $newestFirst ? $d > $date : $d < $date) : collect();
        $after  = $date ? $days->filter(fn ($d) => $newestFirst ? $d < $date : $d > $date) : collect();
        $this->previous = $before->isNotEmpty() ? Carbon::parse($before->last()) : null;
        $this->next     = $after->isNotEmpty()  ? Carbon::parse($after->first()) : null;

        $this->rows = $date
            ? tap(clone $query, fn ($q) => $onDay($q, $date))->get()
            : collect();

        // perPage 1: the paginator counts days, so its "page 3 of 12" reads
        // as the third day of twelve, and prev/next step one day.
        $this->pages = new LengthAwarePaginator(
            [$this->date],
            $days->count(),
            1,
            max(1, $page),
            [
                'path'     => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
                'query'    => collect(request()->query())->except([$pageName, self::DATE_PARAM])->all(),
            ]
        );
    }

    /** The ?day= the request asked for, if it is a real date not after today. */
    private static function askedFor(): ?string
    {
        $raw = request()->query(self::DATE_PARAM);
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        try {
            $day = Carbon::createFromFormat('!Y-m-d', $raw);
        } catch (\Throwable) {
            return null;
        }
        if (!$day || $day->toDateString() !== $raw) {
            return null; // 2026-02-31 and friends
        }

        return $day->isAfter(today()) ? today()->toDateString() : $raw;
    }

    /** Where a day with no rows would sit in the list, as a 1-based page. */
    private function insertionPoint(Collection $days, string $date, bool $newestFirst): int
    {
        return $days->filter(fn ($d) => $newestFirst ? $d > $date : $d < $date)->count() + 1;
    }

    /**
     * The URL for a day. Addressed by date rather than page number, so the
     * link still lands on the same day after a new day has pushed every page
     * number along by one.
     */
    public function urlFor(?Carbon $day): ?string
    {
        if (!$day) {
            return null;
        }

        return $this->pages->path() . '?' . http_build_query(
            array_merge($this->carriedQuery(), [self::DATE_PARAM => $day->toDateString()])
        );
    }

    /**
     * The filters and tab the page is showing, for the calendar form to send
     * back alongside the date it picks.
     *
     * @return array<string, mixed>
     */
    public function carriedQuery(): array
    {
        return $this->pages->getOptions()['query'] ?? [];
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
