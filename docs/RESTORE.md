# If the museum system is lost

This is the page to open when the computer that runs Museo de Baler's system
has died, been stolen, or had its database wiped. It is written for the
person holding the backup drive, with the technical steps marked for whoever
is helping. Nothing here is urgent enough to skip the first section.

## Before anything else

Find these three things. Without all three, stop and get help; without the
key in particular, the backups cannot be opened by anyone, including the
people who wrote this system.

1. **The backup files.** Every night at 02:30 the system writes two files
   with the same date and time in their names:

   | File | What it holds |
   |---|---|
   | `museobaler-2026-09-21_023000.sql.gz.enc` | the records: visitors, attendance, feedback, staff, exhibits |
   | `museobaler-2026-09-21_023000-media.tar.gz.enc` | the pictures and audio guides |

   They are in `storage/app/backups` on the museum computer, and a second
   copy is on the drive or folder named `BACKUP_COPY_TO` in the settings
   file. Fourteen nights are kept. Take the newest pair whose date is
   before the problem started.

2. **The backup key.** A long line of letters and numbers, kept separately
   from the computer — the Tourism Office holds it. It was printed once by
   `php artisan db:backup:key` when the system was set up. If the files
   end in `.enc`, this key is required.

3. **A machine to restore onto**, set up as far as "first deploy" in
   `DEPLOYMENT.md`, with the same `.env` settings file (or a rebuilt one).

## Restoring (a technician does these)

Run every command from the project folder.

**1. Put the key back.** In `.env`, set
`BACKUP_ENCRYPTION_KEY=` to the key from step 2 above.

**2. Open and load the records.**

```
php artisan db:backup:decrypt storage/app/backups/museobaler-DATE.sql.gz.enc
gunzip -c storage/app/backups/museobaler-DATE.sql.gz | mysql -u USER -p museobaler
```

If the files do not end in `.enc`, skip the first line. If `mysql` is not
on the PATH, it is beside `mysqldump` — on Laragon, under `bin\mysql\...\bin`.

**3. Open and put back the pictures and audio.**

```
php artisan db:backup:decrypt storage/app/backups/museobaler-DATE-media.tar.gz.enc
tar -xzf storage/app/backups/museobaler-DATE-media.tar.gz -C public
```

The archive's paths already start with `images/` and `audio/`, so `-C
public` drops everything exactly where the app reads it.

**4. Bring the schema up to date**, in case the backup is older than the
code: `php artisan migrate --force`.

**5. Check, before telling anyone it is fixed.** Open the admin panel:

- Exhibits page: every exhibit has its picture; open one and play the audio.
- Records page: the logbook shows the visitors from the day before the backup.
- Staff page: every staff member is listed. Their passwords are in the
  backup too; nobody needs a new one.
- Visitor app on a phone: scan an exhibit label; it opens.

**6. Make sure tonight's backup will run.** The scheduler must be woken
by the operating system — `deploy/windows/install-scheduler-task.ps1` on
Windows, the cron line in `DEPLOYMENT.md` on Linux. Then run
`php artisan db:backup` once by hand and confirm two new files appear.

## What is not in the backup

- The code. It is in the git repository; `DEPLOYMENT.md` reinstalls it.
- The `.env` settings file. Keep a copy with the key — it holds the
  database password, the email settings and the Gemini key.
- Printed QR labels. The Exhibits page regenerates them.

## Restore drill

A backup nobody has tried to restore is a hope, not a backup. Once a
quarter, do steps 2–5 on a spare machine or a scratch database
(`DB_DATABASE=museobaler_drill`) and note it here.

| Date | Who | Backup used | Result |
|---|---|---|---|
| | | | |
