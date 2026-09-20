<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Who gets told when something breaks
    |--------------------------------------------------------------------------
    |
    | An email address - the Tourism office's, or whoever looks after the
    | server. Leave it empty and alerts are written to the log only, which
    | is the same as nobody being told, so set it on any machine that
    | matters. Several addresses can be separated by commas.
    |
    | Alerts go out through the ordinary mailer (MAIL_* in .env). With
    | MAIL_MAILER=log they land in storage/logs, which is fine on a laptop
    | and useless on the server.
    |
    */

    'email' => env('ALERT_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | How often the same alert may repeat
    |--------------------------------------------------------------------------
    |
    | One mail per distinct problem per this many minutes. A crash that hits
    | on every page load would otherwise send a mail per page load, and an
    | inbox with a thousand copies of the same error is one nobody reads.
    |
    */

    'throttle_minutes' => (int) env('ALERT_THROTTLE_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Backup watchdog
    |--------------------------------------------------------------------------
    |
    | Raise an alert when the newest dump in the backup directory is older
    | than this. The nightly backup depends on the operating system waking
    | Laravel's scheduler every minute, and the most likely failure is that
    | it quietly stops - a reinstalled machine, a task somebody disabled. A
    | backup that silently stopped a month ago is the failure this exists to
    | catch. 36 hours allows one missed night before anyone is bothered.
    |
    | Checked in production only, at most once an hour, from ordinary panel
    | traffic - so it works even when the scheduler is the thing that broke.
    |
    */

    'backup_max_age_hours' => (int) env('ALERT_BACKUP_MAX_AGE_HOURS', 36),

];
