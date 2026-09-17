<?php

namespace App\Services;

use App\Models\StaffAttendanceDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The rotating staff-attendance code.
 *
 * A code that only changes once a day does not actually stop cheating: a
 * staff member photographs it at 8am and scans that photo from home at 4pm,
 * or drops it in the group chat so the whole team "arrives" on time. So the
 * code is derived TOTP-style from a per-day secret and a 60-second window,
 * and the staff-room screen redraws itself as the window turns over.
 *
 * The code carries no identity. It proves "this device is looking at the
 * museum's screen right now" and nothing else — who is checking in comes
 * from the authenticated session on the scanning phone. That is why a leaked
 * code cannot check anybody in.
 */
class AttendanceQrService
{
    /** How long one code stays on screen. */
    public const WINDOW_SECONDS = 60;

    /**
     * Windows either side of the current one that still verify. Covers the
     * gap between a phone reading the screen and the request landing, plus
     * modest clock drift on the kiosk device.
     */
    public const WINDOW_TOLERANCE = 1;

    public const PREFIX = 'MDB-ATT';

    /**
     * Today's secret, created on first use. Rotating with the date means a
     * secret that somehow leaks is worthless tomorrow.
     */
    public function secretFor(Carbon $date, ?int $openedBy = null): string
    {
        $key = $date->toDateString();

        $day = StaffAttendanceDay::find($key);

        if (!$day) {
            $day = StaffAttendanceDay::create([
                'work_date'  => $key,
                'day_secret' => Str::random(64),
                'opened_by'  => $openedBy,
            ]);
        }

        return $day->day_secret;
    }

    public function currentWindow(?Carbon $at = null): int
    {
        return intdiv(($at ?? now())->getTimestamp(), self::WINDOW_SECONDS);
    }

    /** Seconds left before the on-screen code changes — drives the kiosk countdown. */
    public function secondsUntilRotation(?Carbon $at = null): int
    {
        $now = ($at ?? now())->getTimestamp();
        return self::WINDOW_SECONDS - ($now % self::WINDOW_SECONDS);
    }

    /**
     * The payload encoded into the QR image. Reads as
     * MDB-ATT|2026-09-10|29284560|a3f9c1d84e02
     */
    public function payloadFor(Carbon $date, int $window, ?int $openedBy = null): string
    {
        $secret = $this->secretFor($date, $openedBy);

        return implode('|', [
            self::PREFIX,
            $date->toDateString(),
            $window,
            $this->signature($secret, $date->toDateString(), $window),
        ]);
    }

    public function currentPayload(?int $openedBy = null): string
    {
        return $this->payloadFor(today(), $this->currentWindow(), $openedBy);
    }

    /**
     * Checks a scanned payload. Returns null when valid, otherwise a short
     * reason the caller can show the staff member.
     */
    public function verify(string $payload): ?string
    {
        $parts = explode('|', trim($payload));

        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) {
            return 'That is not a Museo de Baler attendance code.';
        }

        [, $dateString, $window, $signature] = $parts;

        if ($dateString !== today()->toDateString()) {
            return 'That code is from another day. Scan the screen in the staff room.';
        }

        if (!ctype_digit((string) $window)) {
            return 'That code is malformed.';
        }

        $window  = (int) $window;
        $current = $this->currentWindow();

        if (abs($current - $window) > self::WINDOW_TOLERANCE) {
            return 'That code has expired. Scan the code showing on screen right now.';
        }

        // The day row must already exist — a valid code cannot predate it.
        $day = StaffAttendanceDay::find($dateString);
        if (!$day) {
            return 'Attendance is not open yet today.';
        }

        $expected = $this->signature($day->day_secret, $dateString, $window);

        if (!hash_equals($expected, $signature)) {
            return 'That code could not be verified.';
        }

        return null;
    }

    private function signature(string $secret, string $dateString, int $window): string
    {
        return substr(hash_hmac('sha256', $dateString . '|' . $window, $secret), 0, 12);
    }
}
