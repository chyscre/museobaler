<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * The nightly backup.
 *
 * 02:30 because the museum is shut and nobody is mid-registration at the
 * desk; the dump is consistent either way, but a quiet hour keeps it quick.
 *
 * Nothing here runs on its own. Laravel's scheduler needs waking once a
 * minute by the operating system - a Windows Task Scheduler entry, or a cron
 * line on a Linux host, running:
 *
 *     php artisan schedule:run
 *
 * Without that, this is a command nobody calls. `php artisan schedule:list`
 * shows what is registered; `php artisan db:backup` runs one by hand.
 */
Schedule::command('db:backup')->dailyAt('02:30');
