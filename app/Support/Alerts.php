<?php

namespace App\Support;

use App\Mail\AlertMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Telling a person that something broke.
 *
 * The logs record everything; nobody reads them until they already know
 * there is a problem. This is the other direction: a short email to whoever
 * is on config('alerts.email') when the system hits something it cannot
 * recover from on its own - an unhandled exception in production, a backup
 * that failed or stopped running.
 *
 * Every alert is also written to the log at critical level, so a machine
 * with no ALERT_EMAIL still has a record, and one whose mail is broken does
 * too.
 */
class Alerts
{
    /**
     * Send one alert, unless the same one went out recently.
     *
     * $key names the problem, not the occurrence: two exceptions from the
     * same line share a key and produce one mail per throttle window. Pass
     * null to send every time.
     */
    public static function send(string $subject, string $body, ?string $key = null): bool
    {
        Log::critical("ALERT: {$subject}", ['detail' => $body]);

        if ($key !== null && !self::firstInWindow($key)) {
            return false;
        }

        $recipients = self::recipients();

        if ($recipients === []) {
            return false;
        }

        try {
            Mail::to($recipients)->send(new AlertMail($subject, self::plainText($subject, $body)));
        } catch (Throwable $e) {
            // The alert channel must never become a second failure. Logged,
            // and the throttle window is left in place so a dead mailer
            // does not retry on every request.
            Log::error('Alert email could not be sent: ' . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * An unhandled exception, as an alert.
     *
     * Only in production: on a developer's machine the screen already shows
     * it. Keyed on class, file and line so a bug on one page produces one
     * mail per window, however many people hit it.
     */
    public static function exception(Throwable $e): void
    {
        if (!app()->environment('production')) {
            return;
        }

        $where = $e->getFile() . ':' . $e->getLine();

        self::send(
            'Unhandled error: ' . class_basename($e),
            get_class($e) . "\n" . $e->getMessage() . "\n\nat " . $where
                . "\n\nURL: " . (request()?->fullUrl() ?? 'console')
                . "\nStaff: " . (auth()->id() ?? 'none'),
            'exception:' . md5(get_class($e) . $where)
        );
    }

    /**
     * Raise an alert if the newest backup is too old.
     *
     * Called from panel traffic rather than the scheduler, because the
     * scheduler not running is precisely the failure being watched for.
     * Returns the age in hours when it alerted, null otherwise.
     */
    public static function checkBackupFreshness(): ?float
    {
        $maxHours  = (int) config('alerts.backup_max_age_hours');
        $directory = config('backup.directory');

        if ($maxHours < 1) {
            return null;
        }

        $newest = 0;
        foreach (glob($directory . '/*.sql.gz*') ?: [] as $dump) {
            $newest = max($newest, filemtime($dump) ?: 0);
        }

        $ageHours = $newest > 0 ? (time() - $newest) / 3600 : INF;

        if ($ageHours <= $maxHours) {
            return null;
        }

        $age = is_infinite($ageHours) ? 'no backup has ever been written' : round($ageHours) . ' hours old';

        self::send(
            'Database backup is stale',
            "The newest dump in {$directory} is {$age}; the limit is {$maxHours} hours.\n\n"
                . "The nightly backup runs only if the operating system calls `php artisan schedule:run` "
                . "every minute. Check that the Task Scheduler entry (or cron line) still exists and is enabled, "
                . "then run `php artisan db:backup` by hand and read what it says.",
            'backup-stale'
        );

        return $ageHours;
    }

    /** True the first time a key is seen within the throttle window. */
    private static function firstInWindow(string $key): bool
    {
        $minutes = max(1, (int) config('alerts.throttle_minutes'));

        return Cache::add('alerts:' . $key, now()->toDateTimeString(), now()->addMinutes($minutes));
    }

    /** @return list<string> */
    private static function recipients(): array
    {
        $configured = (string) config('alerts.email');

        return array_values(array_filter(
            array_map('trim', explode(',', $configured)),
            fn ($address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false
        ));
    }

    private static function plainText(string $subject, string $body): string
    {
        return $subject . "\n" . str_repeat('=', mb_strlen($subject)) . "\n\n"
            . $body . "\n\n"
            . 'Server time: ' . now()->toDateTimeString() . "\n"
            . 'Host: ' . gethostname() . "\n";
    }
}
