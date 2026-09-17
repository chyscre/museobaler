<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;

/**
 * Keeps a calendar-date column stored as a bare Y-m-d string.
 *
 * Eloquent's `date` cast writes through getDateFormat(), which is
 * "Y-m-d H:i:s". MySQL silently truncates that on a DATE column, so it looks
 * fine there - but SQLite keeps the whole string, and then an exact match on
 * "2026-09-09" misses, and a whereBetween drops the final day because
 * "2026-09-09 00:00:00" sorts after "2026-09-09" as text.
 *
 * Writing the date explicitly makes the column behave the same on both.
 */
trait StoresPlainDate
{
    protected function workDate(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Carbon::parse($value)->startOfDay(),
            set: fn ($value) => $value === null ? null : Carbon::parse($value)->toDateString(),
        );
    }
}
