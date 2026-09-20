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

    /*
    |--------------------------------------------------------------------------
    | Encryption at rest
    |--------------------------------------------------------------------------
    |
    | A base64 32-byte key; `php artisan db:backup:key` prints a fresh one.
    | With it set, every dump is written as .sql.gz.enc and only
    | `php artisan db:backup:decrypt` with the same key can open it. Without
    | it, the dump is plain and the command says so on every run.
    |
    | Keep a copy of this key somewhere that is not this machine. A backup
    | whose key died with the server is not a backup.
    |
    */

    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Second copy
    |--------------------------------------------------------------------------
    |
    | A directory on a different disk - a second drive, a network share, a
    | folder that OneDrive or Google Drive syncs. After every run the newest
    | dump is copied here and the same retention applied. Empty means no
    | second copy, which means a dead drive takes the backups with it.
    |
    */

    'copy_to' => env('BACKUP_COPY_TO'),

];
