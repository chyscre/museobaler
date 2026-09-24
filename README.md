# Museo de Baler

The visitor and operations system for Museo de Baler, the municipal museum
of Baler, Aurora. One codebase, three faces:

| | What | Who uses it | Where |
|---|---|---|---|
| **Admin panel** | Front desk, exhibits, translations and narration, QR labels, the map, staff attendance, reports, the ARTA survey | Museum staff and the Tourism office, on a computer or tablet | `/` — Laravel + Blade, `app/Http/Controllers` |
| **Visitor app** | A PWA visitors open on their own phone: register, pay at the desk, then scan or point the camera at exhibits, read and listen in their language, leave feedback | Visitors | `/visitor/` — `public/visitor/` (plain HTML/CSS/JS, no build step), with a service worker for weak signal |
| **API v1** | What the visitor app talks to; JSON, bearer tokens, behind an admission gate | The visitor app | `/api/v1/` — `routes/api.php`, `app/Http/Controllers/Api` |

Laravel 12, PHP 8.2+, MySQL 8. Tests run on in-memory SQLite.

## How it fits together

```
visitor's phone                       museum's computer / tablet
┌──────────────────┐                  ┌──────────────────────┐
│ public/visitor/  │                  │ admin panel (Blade)  │
│ index.html       │                  │ /desk /exhibits /map │
│ app.js + sw.js   │                  └──────────┬───────────┘
└────────┬─────────┘                             │ session
         │ bearer token                          │
         ▼                                       ▼
┌────────────────────────────────────────────────────────────┐
│ Laravel                                                    │
│  routes/api.php  ──  visitor.auth ── visitor.cleared ──►   │
│  routes/web.php  ──  auth ── role:Administrator|TourismHead│
│  Eloquent models, one database                             │
└──────────────────────────┬─────────────────────────────────┘
                           ▼
                        MySQL           + nightly encrypted backup
                                          (db + media) to storage/app/backups
                                          and BACKUP_COPY_TO
```

The admission gate is the one idea to understand first: a visitor's phone
gets a token at registration, but exhibit content is only served once the
desk has marked the fee paid or a local's ID verified
(`app/Http/Middleware/EnsureVisitorCleared.php`). The app's waiting screen
is a rendering of that answer, never the check itself.

## Running it locally

Needs PHP 8.2+ with `mbstring gd sodium pdo_mysql zip`, Composer, Node 20+,
MySQL 8. On Laragon: uncomment `extension=zip` in `php.ini` (it ships
disabled) and restart Apache.

```bash
composer install
npm ci
cp .env.example .env            # DB_* for a local MySQL; the rest can wait
php artisan key:generate
php artisan migrate --seed      # schema, the ARTA survey, sample exhibits with pictures
npm run build                   # the panel's stylesheet (Vite)
php artisan serve               # http://127.0.0.1:8000  — or point Apache/Laragon at public/
```

The seeder prints the two starting logins (an Administrator and a Tourism
Head) once; both must change their password at first sign-in. Set
`SEED_ADMIN_PASSWORD` / `SEED_TOURISM_PASSWORD` in `.env` first to choose
them instead.

The visitor app is at `http://127.0.0.1:8000/visitor/`. On a phone it needs
HTTPS for the camera, the service worker and location — use a
`cloudflared` tunnel to your local server, or test those parts on the
deployed site. `VISITOR_GEOFENCE=false` and `ATTENDANCE_GEOFENCE=false` in
`.env` let you exercise the entry flow and staff clock-in from a desk that
is not in Baler; production ignores both.

Optional: `GEMINI_API_KEY` (Google AI Studio) for AI translations and
narration. Without it those buttons say so and everything else works.

```bash
php artisan test        # 310 PHPUnit tests, incl. tests/Feature/Api
npm test                # the service worker's routing rules (node --test)
```

## Backups and restore

```bash
php artisan db:backup:key        # once; put the output in .env as BACKUP_ENCRYPTION_KEY,
                                 # and a copy OFF the machine — see docs/OWNERSHIP.md
php artisan db:backup            # a dump + a media tarball into storage/app/backups
php artisan db:backup:decrypt storage/app/backups/museobaler-<stamp>.sql.gz.enc
```

The nightly run is `Schedule::command('db:backup')` at 02:30
(`routes/console.php`); the OS has to wake the scheduler every minute
(`deploy/windows/install-scheduler-task.ps1`, or a cron line). There is no
`db:restore` command by design — restoring is a person's decision, and
`docs/RESTORE.md` walks through it step by step.

## Deploying

`deploy/deploy.sh <ref>` does a blue/green deploy on a Linux host and
`deploy/rollback.sh` undoes it in a second. `docs/DEPLOYMENT.md` has the
first-time server setup, the `.env` keys that are not optional, and what
CI guarantees before any of it.

## Documentation

| For | Read |
|---|---|
| Museum staff and the Tourism office — how to do the job | [docs/STAFF_MANUAL.md](docs/STAFF_MANUAL.md) |
| How the pieces fit: context, request pipeline, deployment, what is generated | [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) |
| The database: 24 tables, their keys, and the joins that are missing on purpose | [docs/ERD.md](docs/ERD.md) |
| Whoever holds the keys — accounts, credentials, the backup key, who to call | [docs/OWNERSHIP.md](docs/OWNERSHIP.md) |
| The person holding the backup drive on a bad morning | [docs/RESTORE.md](docs/RESTORE.md) |
| Buying the hosting, setting it up from scratch, handing the bill over | [docs/HOSTING.md](docs/HOSTING.md) |
| Putting it on a server, updating it, rolling back | [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) |
| What is protected how; Data Privacy Act notes | [docs/SECURITY.md](docs/SECURITY.md) |
| What it deliberately does not do, and what will trip a developer | [docs/LIMITATIONS.md](docs/LIMITATIONS.md) |
| The recognition model the camera uses | [public/visitor/model/README.md](public/visitor/model/README.md) |

## Layout

```
app/Http/Controllers/       the panel
app/Http/Controllers/Api/   the visitor API (/api/v1)
app/Http/Middleware/        roles, the admission gate, sanitising, headers
app/Models/                 Eloquent; Visitor carries the door's logic
app/Services/               Gemini, geofence, recognition, image search, QR
app/Support/                the ARTA survey, password policies, backups, alerts
database/seeders/media/     the seeded exhibits' pictures and audio
public/visitor/             the app: index.html, js/app.js, sw.js, model/
public/images, public/audio uploads (git-ignored; shared across deploys)
deploy/                     deploy.sh, rollback.sh, the Windows scheduler task
docs/                       everything listed above
tests/Feature/Api/          the visitor API
tests/js/                   the service worker
```
