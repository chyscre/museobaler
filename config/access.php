<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Desktop-only admin panel
    |--------------------------------------------------------------------------
    |
    | The admin panel is laid out for a monitor and is not usable on a phone.
    | With this on, a phone session can reach only the pages that genuinely
    | need a phone - clocking in, which needs the camera, and setting your own
    | password. Tablets are unaffected: the front desk register runs on one.
    |
    | Turn it off only to debug the panel on a phone. It is a fit-for-purpose
    | guard, never a security boundary - EnsureRole is what actually keeps
    | people out of things, on every device.
    |
    */

    'desktop_only' => (bool) env('ADMIN_DESKTOP_ONLY', true),

    /*
    |--------------------------------------------------------------------------
    | Staff attendance geofence
    |--------------------------------------------------------------------------
    |
    | Staff can only clock in from inside the circle drawn around the museum's
    | pin on the Museum Info screen. That is one of the three legs the whole
    | attendance guarantee stands on, so it is on by default and it is ALWAYS
    | on in production - GeofenceService ignores this setting there.
    |
    | Off, for a development machine that is nowhere near Baler. The scan
    | still needs a fresh code and a signed-in account; only the distance
    | check is skipped, and every screen that shows attendance says so.
    |
    */

    'geofence' => (bool) env('ATTENDANCE_GEOFENCE', true),

];
