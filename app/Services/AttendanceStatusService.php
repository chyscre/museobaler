<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\StaffSchedule;
use Illuminate\Support\Carbon;

/**
 * Turns raw scan timestamps into the words the Tourism office reports on.
 *
 * A timestamp on its own says nothing — 8:31am is only "late" relative to a
 * shift. Everything here is derived on read rather than stored, so correcting
 * somebody's schedule immediately fixes their history instead of leaving
 * stale statuses behind.
 */
class AttendanceStatusService
{
    public const PRESENT  = 'Present';
    public const LATE     = 'Late';
    public const ABSENT   = 'Absent';
    public const REST_DAY = 'Rest Day';
    public const NO_SHIFT = 'No Schedule';

    /**
     * One person's day: the in/out rows, the derived status, and the minutes
     * worked. $rows is that staff member's attendance for the date.
     */
    public function summarise(Staff $staff, Carbon $date, $rows, ?StaffSchedule $schedule = null): array
    {
        $in  = $rows->firstWhere('type', 'in');
        $out = $rows->firstWhere('type', 'out');

        $schedule ??= $staff->schedules->firstWhere('weekday', (int) $date->dayOfWeek);

        $result = [
            'staff'         => $staff,
            'date'          => $date->copy(),
            'in'            => $in,
            'out'           => $out,
            'schedule'      => $schedule,
            'status'        => self::NO_SHIFT,
            'late_minutes'  => 0,
            'worked_minutes'=> null,
            'is_manual'     => ($in && $in->method === 'manual') || ($out && $out->method === 'manual'),
        ];

        if ($in && $out) {
            // Carbon 3 returns a float here; everything downstream formats
            // this as whole minutes, so round it once at the source.
            $result['worked_minutes'] = max(0, (int) round($in->scanned_at->diffInMinutes($out->scanned_at)));
        }

        if ($schedule && $schedule->is_rest_day) {
            // A scan on a rest day still counts as present — someone came in.
            $result['status'] = $in ? self::PRESENT : self::REST_DAY;
            return $result;
        }

        if (!$schedule) {
            $result['status'] = $in ? self::PRESENT : self::NO_SHIFT;
            return $result;
        }

        if (!$in) {
            // Only call it absent once the day is actually over, otherwise
            // everyone reads as absent first thing in the morning.
            $result['status'] = $date->isToday() && now()->format('H:i:s') < $schedule->shift_end
                ? self::NO_SHIFT
                : self::ABSENT;
            return $result;
        }

        $cutoff = $date->copy()
            ->setTimeFromTimeString($schedule->shift_start)
            ->addMinutes((int) $schedule->grace_minutes);

        if ($in->scanned_at->greaterThan($cutoff)) {
            $result['status']       = self::LATE;
            $result['late_minutes'] = (int) round($cutoff->diffInMinutes($in->scanned_at));
        } else {
            $result['status'] = self::PRESENT;
        }

        return $result;
    }

    /**
     * Every active museum staff member's day, for the attendance board.
     *
     * The Tourism office is excluded: the head of tourism works from the
     * municipal office and does not clock in, so listing her here would put a
     * permanent "Absent" on the board and skew every count on it.
     */
    public function boardFor(Carbon $date)
    {
        $staff = Staff::where('status', true)
            ->where('role', Staff::ROLE_ADMIN)
            ->with('schedules')
            ->orderBy('name')
            ->get();

        $rows = StaffAttendance::whereDate('work_date', $date)
            ->get()
            ->groupBy('staff_id');

        return $staff->map(fn (Staff $member) => $this->summarise(
            $member, $date, $rows->get($member->staff_id) ?? collect()
        ));
    }

    /** One person across a date range — the shape a printed DTR needs. */
    public function rangeFor(Staff $staff, Carbon $from, Carbon $to)
    {
        $staff->loadMissing('schedules');

        $rows = StaffAttendance::where('staff_id', $staff->staff_id)
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn ($row) => $row->work_date->toDateString());

        $days = collect();

        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $days->push($this->summarise($staff, $d, $rows->get($d->toDateString()) ?? collect()));
        }

        return $days;
    }
}
