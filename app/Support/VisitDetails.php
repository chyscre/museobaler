<?php

namespace App\Support;

use App\Models\AdmissionPayment;
use App\Models\Attendance;
use App\Models\Scan;
use App\Models\Visit;
use App\Models\VisitGroup;
use App\Models\Visitor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything Records knows about one day's visit, for the View Details
 * window: the admission and its transaction numbers, the geofence's record
 * of when they arrived and were last seen, and what they scanned.
 *
 * Read for a DAY, not from the visitor row. That row describes the latest
 * visit only, so a row on last Tuesday's page would otherwise show today's
 * fee. Each part comes from what kept that day: the visits table, the
 * ledger, the attendance row and the scans.
 */
final class VisitDetails
{
    /** One visitor on one day. */
    public static function forVisitor(Visitor $visitor, Carbon $day): array
    {
        $visit = Visit::with('group')->where('visitor_id', $visitor->visitor_id)
            ->whereDate('visit_date', $day)->first();
        $group = $visit?->group;

        $attendance = Attendance::where('visitor_id', $visitor->visitor_id)
            ->whereDate('visit_date', $day)->first();

        // A member's admission is the party's; their own ledger has nothing.
        $entries = $group
            ? AdmissionPayment::where('group_id', $group->group_id)->orderBy('recorded_at')->get()
            : AdmissionPayment::where('visitor_id', $visitor->visitor_id)
                ->where('payer', AdmissionPayment::INDIVIDUAL)
                ->whereDate('recorded_at', $day)->orderBy('recorded_at')->get();

        return [
            'visitor'    => $visitor,
            'day'        => $day,
            'visit'      => $visit,
            'group'      => $group,
            'account'    => self::account($visitor),
            'category'   => $visit ? self::category($visit->visitor_type, $visit->discount_name, $visit->discount_percent) : null,
            'fee'        => self::fee($visit, $group),
            'status'     => self::admissionStatus($visitor, $visit, $day),
            'admitted'   => self::admittedAt($visit, $group),
            'entries'    => $entries,
            'companions' => $visit?->companions ?? [],
            'headcount'  => $visit?->headcount ?? 1,
            'presence'   => self::presence($attendance, $visitor, $day),
            'scans'      => self::scans($visitor->visitor_id, $day),
        ];
    }

    /** An attendance row with nobody behind it: the fence's record alone. */
    public static function forAnonymous(Attendance $attendance): array
    {
        return [
            'attendance' => $attendance,
            'day'        => $attendance->visit_date,
            'presence'   => self::presence($attendance, null, $attendance->visit_date),
        ];
    }

    /** A party: its transactions and everyone who joined it with the code. */
    public static function forGroup(VisitGroup $group): array
    {
        // From the visits table, which keeps the day's party for good. The
        // visitor row's group_id is overwritten by a member's next visit,
        // so reading members through it loses everyone who has been back.
        $visits = Visit::with('visitor')->where('group_id', $group->group_id)
            ->orderByRaw('joined_group_at IS NULL')->orderBy('joined_group_at')->orderBy('visit_id')
            ->get()
            ->filter(fn (Visit $v) => $v->visitor !== null);

        $scans = Scan::with('exhibit.museumHall')
            ->whereIn('visitor_id', $visits->pluck('visitor_id'))
            ->whereDate('scanned_at', $group->visit_date)
            ->orderBy('scanned_at')
            ->get()
            ->groupBy('visitor_id');

        // VisitGroup::clearsMembers() without its "today" condition: how the
        // party stood, on whichever day this was.
        $cleared = $group->visitor_type === 'Local' || $group->payment_status === 'Paid'
            || (float) $group->total_fee <= 0;

        $members = $visits->map(fn (Visit $v) => [
            'visitor'  => $v->visitor,
            'category' => self::category($v->visitor_type, $v->discount_name, $v->discount_percent),
            'status'   => $cleared ? 'Cleared' : 'Waiting for payment',
            'joined'   => $v->joined_group_at,
            'scans'    => $scans->get($v->visitor_id, collect()),
        ])->values();

        return [
            'group'    => $group,
            'entries'  => AdmissionPayment::where('group_id', $group->group_id)->orderBy('recorded_at')->get(),
            'members'  => $members,
            'notInApp' => max(0, (int) $group->headcount - $members->count()),
        ];
    }

    // -- Pieces ------------------------------------------------------------

    /**
     * What they were admitted as, in the words the receipt uses: "Full
     * admission", "Baler resident", "Senior citizen (free)", "Student (20% off)".
     */
    public static function category(?string $type, ?string $discountName, ?int $percent): string
    {
        return Admission::visitorLines((string) $type, 0, $discountName, $percent)[0]['label'];
    }

    /** Where the record came from, and whether the app is signed in now. */
    private static function account(Visitor $v): string
    {
        $how = match (true) {
            $v->companion_of !== null => 'Companion of ' . ($v->holder?->full_name ?? 'a visitor'),
            $v->source === 'express'  => 'Express entry at the desk',
            $v->source === 'desk'     => 'Registered at the desk',
            $v->source === 'kiosk'    => 'Registered at the counter tablet',
            default                   => 'Visitor app account',
        };

        if ($v->email && $v->source === 'app') {
            $how .= $v->hasVerifiedEmail() ? ' · email confirmed' : ' · email not confirmed';
        }

        return $how;
    }

    /** "₱50.00 · Paid", "Free", "Covered by Maria's group". */
    private static function fee(?Visit $visit, ?VisitGroup $group): string
    {
        if ($group) {
            return 'Covered by ' . $group->label;
        }
        if ($visit === null || $visit->payment_status === null) {
            return '—';
        }
        if ($visit->payment_status === 'Free') {
            return '₱0.00 · Free';
        }

        return '₱' . number_format((float) $visit->admission_fee, 2) . ' · ' . $visit->payment_status;
    }

    /** Today: the gate's own answer. An earlier day: how that visit ended. */
    private static function admissionStatus(Visitor $visitor, ?Visit $visit, Carbon $day): string
    {
        if ($day->isToday()) {
            return $visitor->clearance() === 'cleared' ? 'Cleared' : $visitor->clearanceLabel();
        }

        return $visit?->summary ?? 'Came (details from before visits were kept)';
    }

    /**
     * When the desk let them in: the fee taken, the ID sighted, or - for a
     * member - the party paid (or was signed in, for a party that owed
     * nothing).
     */
    private static function admittedAt(?Visit $visit, ?VisitGroup $group): ?Carbon
    {
        if ($group) {
            return $group->paid_at ?? ((float) $group->total_fee <= 0 ? $group->created_at : null);
        }

        return $visit?->paid_at ?? ($visit?->id_verified ? $visit->verified_at : null);
    }

    /**
     * The geofence's account of the day.
     *
     * The app reports "exit" whenever it is put away as well as when it
     * leaves the fence - it cannot watch location in the background - so
     * exited_at is the last moment they were confirmed on site, not proof
     * they walked out. The labels say so rather than claiming more.
     *
     * @return array{state: string, tone: string, arrived: ?Carbon, last_seen: ?Carbon, minutes: ?int, duration: ?string}
     */
    private static function presence(?Attendance $a, ?Visitor $visitor, Carbon $day): array
    {
        if ($a === null) {
            return ['state' => 'No geofence check-in', 'tone' => 'gray', 'arrived' => null,
                    'last_seen' => null, 'minutes' => null, 'duration' => null];
        }

        $minutes = $a->duration_mins !== null
            ? (int) $a->duration_mins
            : ($a->exited_at ? (int) $a->created_at->diffInMinutes($a->exited_at) : null);

        // Signed out, revoked, or the day's token run out: the app can no
        // longer report anything, so what was last seen is final.
        $sessionOver = $visitor !== null
            && ($visitor->api_token === null || $visitor->token_expires_at === null || $visitor->token_expires_at->isPast());

        [$state, $tone] = match (true) {
            !$day->isToday()         => ['Visit ended', 'gray'],
            $sessionOver             => ['Session ended', 'gray'],
            $a->exited_at === null   => ['Still inside', 'green'],
            default                  => ['Last seen on site ' . $a->exited_at->format('g:i A'), 'gold'],
        };

        return [
            'state'     => $state,
            'tone'      => $tone,
            'arrived'   => $a->created_at,
            'last_seen' => $a->exited_at,
            'minutes'   => $minutes,
            'duration'  => $minutes === null ? null : self::duration($minutes),
        ];
    }

    /** "1 hr 18 mins", "45 mins", "2 hrs". */
    public static function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        $parts = [];
        if ($h > 0) {
            $parts[] = $h . ' ' . ($h === 1 ? 'hr' : 'hrs');
        }
        if ($m > 0 || $h === 0) {
            $parts[] = $m . ' ' . ($m === 1 ? 'min' : 'mins');
        }

        return implode(' ', $parts);
    }

    /** @return Collection<int, Scan> the day's scans, oldest first */
    private static function scans(int $visitorId, Carbon $day): Collection
    {
        return Scan::with('exhibit.museumHall')
            ->where('visitor_id', $visitorId)
            ->whereDate('scanned_at', $day)
            ->orderBy('scanned_at')
            ->get();
    }
}
