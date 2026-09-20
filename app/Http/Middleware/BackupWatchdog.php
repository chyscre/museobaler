<?php

namespace App\Http\Middleware;

use App\Support\Alerts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notices when the nightly backup has stopped.
 *
 * The backup is scheduled, and the scheduler only runs if the operating
 * system keeps waking it. When that stops - a reinstalled machine, a Task
 * Scheduler entry somebody disabled - nothing else in the system notices,
 * and the next time anyone looks is the day they need a restore.
 *
 * So the check rides on ordinary traffic instead: once an hour, on the
 * first signed-in request, look at the newest dump and raise an alert if
 * it is too old. Production only; a laptop is not expected to have
 * backups. The work is done after the response is sent, so no page waits
 * on a directory listing.
 */
class BackupWatchdog
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (!app()->environment('production') || !$request->user()) {
            return;
        }

        // One check an hour across every worker and every staff member.
        if (!Cache::add('backup-watchdog:checked', now()->toDateTimeString(), now()->addHour())) {
            return;
        }

        Alerts::checkBackupFreshness();
    }
}
