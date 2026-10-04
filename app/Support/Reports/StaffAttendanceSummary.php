<?php

namespace App\Support\Reports;

use App\Models\AttendanceCorrection;
use App\Models\Staff;
use App\Services\AttendanceStatusService;
use Illuminate\Support\Carbon;

/**
 * Every museum staff member's attendance over one period, one line each.
 *
 * The DTR is one person's month, day by day, and is what gets signed. This
 * is the sheet that sits on top of a stack of them: who came in how often,
 * how many hours that added up to, and whether any of it was entered by
 * hand rather than scanned.
 *
 * Present and Absent only. A late check-in is still a day present - the
 * minutes late are on that person's DTR, which is where anyone acting on
 * them will look. Rest days and days with no shift on file are neither, so
 * they count toward nothing and the rate is present over the two.
 *
 * Shared by the printable page and every download format, so the numbers
 * cannot differ between the copy on screen and the file that is sent.
 */
class StaffAttendanceSummary
{
    /** A longer period is a different question - and a slow page. */
    public const MAX_DAYS = 366;

    public static function build(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to   = $to->copy()->startOfDay();

        // Never count days that have not happened yet as absences.
        if ($to->greaterThan(today())) {
            $to = today();
        }
        if ($from->greaterThan($to)) {
            $from = $to->copy();
        }
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
        }

        $status = app(AttendanceStatusService::class);

        $staff = Staff::where('status', true)
            ->where('role', Staff::ROLE_ADMIN)
            ->with('schedules')
            ->orderBy('name')
            ->get();

        // Filed but not yet decided: a day that may still change, which is
        // worth knowing before the sheet is signed.
        $pending = AttendanceCorrection::where('status', 'Pending')
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->countBy('staff_id');

        $rows = $staff->map(function (Staff $member) use ($status, $from, $to, $pending) {
            $days = $status->rangeFor($member, $from, $to);

            $present = $days->whereIn('status', [AttendanceStatusService::PRESENT, AttendanceStatusService::LATE])->count();
            $absent  = $days->where('status', AttendanceStatusService::ABSENT)->count();
            $manual  = $days->where('is_manual', true)->count();
            $waiting = (int) ($pending[$member->staff_id] ?? 0);

            return [
                'staff'    => $member,
                'present'  => $present,
                'absent'   => $absent,
                'rate'     => self::rate($present, $absent),
                'minutes'  => (int) $days->sum('worked_minutes'),
                'manual'   => $manual,
                'pending'  => $waiting,
                'verified' => self::verification($manual, $waiting),
            ];
        })->values();

        $present = $rows->sum('present');
        $absent  = $rows->sum('absent');

        return [
            'from'   => $from,
            'to'     => $to,
            'rows'   => $rows,
            'totals' => [
                'staff'   => $rows->count(),
                'present' => $present,
                'absent'  => $absent,
                'rate'    => self::rate($present, $absent),
                'minutes' => $rows->sum('minutes'),
                'manual'  => $rows->sum('manual'),
                'pending' => $rows->sum('pending'),
            ],
        ];
    }

    /** Whole-number percentage present, or null when nobody was due in. */
    public static function rate(int $present, int $absent): ?int
    {
        $due = $present + $absent;

        return $due > 0 ? (int) round($present / $due * 100) : null;
    }

    public static function verification(int $manual, int $pending): string
    {
        $parts = [];
        if ($manual)  $parts[] = $manual . ' manual';
        if ($pending) $parts[] = $pending . ' pending';

        return $parts ? implode(', ', $parts) : 'All scanned';
    }

    public static function hours(int $minutes): string
    {
        return intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
    }
}
