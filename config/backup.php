<?php

return [

    /*
    |--------------------------------------------------------------------------
    | mysqldump
    |--------------------------------------------------------------------------
    |
    | Full path to the mysqldump binary. Leave empty and the command looks for
    | it on PATH first, then in the places Laragon and XAMPP hide it - neither
    | puts it on PATH, so a bare "mysqldump" fails on a developer machine that
    | plainly has MySQL running.
    |
    */

    'mysqldump' => env('MYSQLDUMP_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Where dumps are written
    |--------------------------------------------------------------------------
    |
    | Under storage/, never under public/: a database dump in the docroot is
    | the whole museum's data one guessed filename away. storage/app is also
    | already git-ignored, so a dump cannot be committed by accident.
    |
    | This disk is still the same disk as the database. It protects against a
    | bad migration or a mistaken delete, not against the machine dying - for
    | that, something has to copy these off the box.
    |
    */

    'directory' => storage_path('app/backups'),

    /*
    |--------------------------------------------------------------------------
    | How many dumps to keep
    |--------------------------------------------------------------------------
    |
    | Oldest beyond this are deleted after each run. Fourteen daily dumps is a
    | fortnight to notice something went wrong, which is longer than anyone
    | takes to notice missing visitor records.
    |
    */

    'keep' => (int) env('BACKUP_KEEP', 14),

];
