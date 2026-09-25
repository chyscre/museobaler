# Deployment

How the system gets onto a server, how an update goes live without
downtime, and how it comes back off if the update is bad.

## The shape of it

```
/var/www/museobaler/
├── current  -> releases/20260919143000     ← the web server's docroot is current/public
├── releases/
│   ├── 20260918090000/                     ← the previous release, kept for rollback
│   └── 20260919143000/                     ← live
├── shared/
│   ├── .env                                ← secrets; never inside a release
│   ├── storage/                            ← logs, sessions, backups
│   └── public/                             ← uploaded media + trained model
│       ├── images/{exhibits,training,branding}
│       ├── audio/
│       └── visitor/model/                  ← seeded from the release, then staff's
└── PREVIOUS                                ← path of the release before this one
```

Every release is a complete, self-contained copy of the application. The
only thing that changes at go-live is where the `current` symlink points.
That is the whole of the blue/green idea: the new copy ("green") is built,
migrated and health-checked while the old one ("blue") keeps serving, and
the switch between them is one atomic rename.

## First-time server setup (Ubuntu, Apache or nginx, PHP 8.3, MySQL 8)

1. Install `php8.3-fpm` with `mbstring gd sodium mysql xml curl zip`, plus
   `composer`, `node` 20, `git`, `mysql-server`.
2. Create the layout above: `mkdir -p /var/www/museobaler/{releases,shared/storage,shared/public}`.
3. Copy `.env.example` to `shared/.env` and fill it in. On a server these are
   not optional:
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`
   - `DB_*` for a MySQL user scoped to this one database
   - `SESSION_SECURE_COOKIE=true`
   - `MAIL_*` real, and `ALERT_EMAIL` set, or nobody hears when something breaks
   - `BACKUP_ENCRYPTION_KEY` from `php artisan db:backup:key`, with a copy of
     the key kept off the machine
   - `BACKUP_COPY_TO` pointing at a different disk or a synced folder
   - `APP_TIMEZONE=Asia/Manila` (the default). Both database connections
     pin MySQL's session `time_zone` to this offset, so a server whose MySQL
     runs in UTC still dates a survey filed at 1 AM on the right day. Nothing
     to configure on the MySQL side.
   - `ATTENDANCE_GEOFENCE` and `VISITOR_GEOFENCE` are ignored in production;
     both fences always run. Leave them unset.
4. Point the web server's docroot at `/var/www/museobaler/current/public`,
  with TLS. If using nginx, deny direct access to protected exhibit media
  before the generic static-file rule:
  `location ~ ^/(images/exhibits|audio)/ { return 404; }`.
  Behind Cloudflare, the proxy IP ranges in `bootstrap/app.php`
   are already trusted.
5. Add the cron line that wakes the scheduler (this is what runs the nightly
   backup):
   ```
   * * * * * cd /var/www/museobaler/current && php artisan schedule:run >> /dev/null 2>&1
   ```
6. Set `REPO` in `deploy/deploy.sh` (or export it) to the repository URL,
   and run the first deploy.

## Deploying

```
deploy/deploy.sh v1.4.0        # a tag, branch or commit
```

What it does, in order — and where it stops if anything fails:

| Step | What | If it fails |
|---|---|---|
| 1 | Clone the exact ref into a new `releases/<timestamp>` | nothing changed |
| 2 | Link `shared/.env`, `shared/storage`, shared media into it | nothing changed |
| 3 | `composer install --no-dev`, `npm ci && npm run build`, cache config/routes/views | nothing changed |
| 4 | **Take a database backup**, then `migrate --force` | old code still live; restore the dump if a migration half-applied |
| 5 | Boot the new release on a private port and GET `/up`; require 200 | old code still live |
| 6 | Atomically move `current` to the new release; reload PHP-FPM | — |
| 7 | Delete releases older than the last three | — |

Nothing a visitor or a staff member can see changes until step 6, and step 6
is a single rename. There is no maintenance window.

## Rolling back

```
deploy/rollback.sh
```

Moves `current` back to the release recorded in `PREVIOUS` and reloads
PHP-FPM. Takes about a second. Run it again to go forward again.

The database is deliberately not touched. Migrations in this project only
add columns and tables, so the previous code runs fine against the newer
schema. If a migration itself did damage, the dump taken in step 4 is in
`shared/storage/app/backups` — decrypt it with `php artisan db:backup:decrypt`
and restore it with `mysql`. That is a decision a person makes, not
something a script should do on its own. The uploaded pictures and audio
are in the `-media.tar.gz` beside each dump; `docs/RESTORE.md` walks
through both, in the order to do them.

## What CI guarantees before any of this

`.github/workflows/ci.yml` runs on every push: the full test suite on
in-memory sqlite (the visitor API included, under `tests/Feature/Api`),
`composer audit` and `npm audit`. Deploy from a ref that is green. Dependabot opens a pull
request every Monday for dependency updates, which CI tests the same way.

## The visitor app's service worker

Phones cache the app (`public/visitor/sw.js`) under a name that carries
the release: step 1 of `deploy.sh` writes the release hash into `VERSION`
in `sw.js`, and `sw.js` is served `no-cache` (`public/.htaccess`), so a
phone that opens the app after a deploy installs the new release into a
fresh cache and drops the old one. Nothing to do by hand. If a phone ever
does look stuck, the release hash is visible at
`DevTools > Application > Cache Storage` as `museobaler-shell-<hash>`.

## On the Windows machine this was built on

There is no blue/green on a Laragon laptop; there is just the working tree.
What the laptop does need is the scheduler, or the backup never runs:

```
powershell -ExecutionPolicy Bypass -File deploy\windows\install-scheduler-task.ps1
```

registers a Task Scheduler entry that calls `php artisan schedule:run` every
minute as SYSTEM. Run it once from an elevated PowerShell.

The service worker's `VERSION` stays `dev` here; the shell is refreshed in
the background on every open anyway, so an edited `app.js` shows up on the
next load. Only a precached file being *removed* needs a bump: change
`VERSION` in `sw.js`, or unregister the worker in DevTools.
