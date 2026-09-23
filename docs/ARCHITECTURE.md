# System Architecture

Museo de Baler runs as one Laravel 12 application on PHP 8.3 that presents three
faces: a staff panel rendered server-side with Blade, a JSON API under
`/api/v1`, and an installable visitor web app of static files served from
`public/visitor/` that talks only to that API.

Diagrams below are Mermaid. Paste a block into <https://mermaid.live> to export
PNG or SVG for the manuscript.

---

## 1. Context: who touches the system

```mermaid
flowchart TB
    visitor["Visitor<br/><i>own phone, mobile browser</i>"]
    staff["Museum staff<br/><i>desk, curator, guide</i>"]
    admin["Administrator<br/><i>museum configuration</i>"]
    tourism["Tourism Office<br/><i>accounts, ARTA reports</i>"]

    system(["<b>Museo de Baler</b><br/>Laravel 12 / PHP 8.3 / MySQL"])

    gemini["Google Gemini API<br/><i>translation + narration</i>"]
    mail["SMTP<br/><i>alerts</i>"]

    visitor -->|"scans a QR, opens an exhibit,<br/>answers the survey"| system
    staff -->|"registers walk-ins, clocks in,<br/>edits the collection"| system
    admin -->|"fee, hours, geofence, halls"| system
    tourism -->|"issues accounts, pulls reports"| system

    system -.->|"drafts a translation<br/>(optional, degrades gracefully)"| gemini
    system -.->|"backup failed, disk low"| mail

    style system fill:#1f4e5f,stroke:#0d2b36,color:#fff
    style gemini stroke-dasharray: 4 4
    style mail stroke-dasharray: 4 4
```

Both dashed dependencies are optional by design. If Gemini is unreachable the
museum still runs: translations are simply written by hand. See
[LIMITATIONS.md](LIMITATIONS.md).

## 2. The three faces

```mermaid
flowchart LR
    subgraph phone["Visitor phone"]
        pwa["<b>Visitor app</b><br/>public/visitor/<br/>index.html, js/app.js<br/>vanilla JS, no build step"]
        sw["sw.js<br/><i>service worker cache</i>"]
        tm["model/<br/><i>Teachable Machine<br/>image classifier</i>"]
        pwa --- sw
        pwa --- tm
    end

    subgraph desktop["Staff desktop"]
        panel["<b>Staff panel</b><br/>Blade views<br/>resources/views/"]
    end

    subgraph server["Laravel application"]
        api["<b>JSON API</b><br/>routes/api.php<br/>/api/v1<br/>guard: visitor (bearer token)"]
        web["<b>Web routes</b><br/>routes/web.php<br/>guard: web (session)"]
        mw["Middleware<br/><i>roles, admission gate,<br/>sanitising, headers, audit</i>"]
        svc["Services + Support<br/><i>Gemini, geofence, recognition,<br/>QR, ARTA survey, reports</i>"]
        models["Eloquent models"]
    end

    db[("MySQL")]
    files[["public/images, public/audio<br/><i>uploaded media</i>"]]

    pwa -->|"fetch, JSON only"| api
    panel --> web
    api --> mw
    web --> mw
    mw --> svc
    svc --> models
    models --> db
    svc --> files

    style pwa fill:#2d5a3d,stroke:#16301f,color:#fff
    style panel fill:#4a3d6b,stroke:#241d38,color:#fff
    style api fill:#1f4e5f,stroke:#0d2b36,color:#fff
    style web fill:#1f4e5f,stroke:#0d2b36,color:#fff
```

The visitor app never renders a Blade view and never holds a session cookie; the
panel never serves JSON to a phone. The `DesktopOnly` middleware enforces the
second half of that and `tests/Feature/DesktopOnlyTest.php` pins it.

## 3. Request pipeline

Every request passes the same ordered gates. The order matters: input is
sanitised before a controller sees it, and the audit log records the act only
after the role check has allowed it.

```mermaid
flowchart TB
    req(["Request"]) --> headers["SecurityHeaders<br/><i>CSP, HSTS, frame-deny</i>"]
    headers --> sanitize["SanitizeInput<br/><i>strips control chars, trims</i>"]
    sanitize --> fork{"Which face?"}

    fork -->|"/api/v1/*"| vauth["AuthenticateVisitor<br/><i>bearer token to Visitor</i>"]
    vauth --> cleared["EnsureVisitorCleared<br/><i>paid or waived at the door</i>"]
    cleared --> apihdr["ApiResponseHeaders"]
    apihdr --> ctrl

    fork -->|"panel"| desk["DesktopOnly<br/><i>no phones in the panel</i>"]
    desk --> rate["LoginRateLimiter"]
    rate --> sess["web guard<br/><i>session, Staff model</i>"]
    sess --> pwchange["RequirePasswordChange"]
    pwchange --> role["EnsureRole<br/><i>superadmin, administrator,<br/>curator, frontdesk, guide</i>"]
    role --> audit["AuditLog<br/><i>writes to logs table</i>"]
    audit --> watchdog["BackupWatchdog<br/><i>warns on a stale dump</i>"]
    watchdog --> ctrl

    ctrl["Controller"] --> svc2["Service / Support class"]
    svc2 --> resp(["Response"])
```

## 4. Three flows worth walking through

A panel will usually ask you to trace one request end to end. These are the
three that show the most.

### A visitor checks in at the door

```mermaid
sequenceDiagram
    participant V as Visitor phone
    participant A as /api/v1/attendance
    participant G as GeofenceService
    participant D as MySQL

    V->>V: Scan the wall QR
    V->>V: Ask the browser for coordinates
    V->>A: POST lat, lng, accuracy
    A->>G: Inside the geofence?
    G->>D: Read museum_info lat/lng/radius
    G-->>A: distance in metres
    alt outside the radius
        A-->>V: 422 refused, with the distance
    else inside
        A->>D: INSERT attendances
        A-->>V: 201 checked in
    end
```

The geofence centre and radius are rows in `museum_info`, not constants, so an
Administrator moves the boundary from the panel. This is also why a scan from
home is refused — the most common surprise during testing.

### A visitor identifies an exhibit by camera

```mermaid
sequenceDiagram
    participant V as Visitor phone
    participant M as Teachable Machine model<br/>(in the browser)
    participant A as /api/v1/recognition
    participant D as MySQL

    V->>M: Camera frame
    M-->>V: class + confidence
    alt confident enough
        V->>A: POST the predicted class
        A->>D: Resolve to an exhibit
        A-->>V: exhibit payload
    else not confident
        V-->>V: Keep sampling, or fall back to the QR scanner
    end
```

Classification runs **on the phone**, not the server: the model is 3 MB of
static files in `public/visitor/model/` and the server is only asked to resolve a
class name to an exhibit row. That is what makes the feature work on a weak
signal, and why retraining means regenerating those files from
`exhibit_training_images`.

### Tourism exports the ARTA report

```mermaid
sequenceDiagram
    participant T as Tourism
    participant R as ReportController
    participant B as ReportBuilder
    participant E as CsvExporter / XlsxExporter<br/>DocxExporter / PdfExporter
    participant D as MySQL

    T->>R: Pick a report, range and format
    R->>B: Build the dataset
    B->>D: Query, grouped by ARTA definitions
    D-->>B: rows
    B->>E: ReportDataset + ReportSection
    E->>D: Read museum_info letterhead
    E-->>T: File download
```

One dataset, four exporters. Adding a format means one new class in
`app/Support/Reports/`, not a fifth copy of the query.

## 5. Deployment

```mermaid
flowchart TB
    subgraph host["Museum host (Windows or Linux)"]
        nginx["Web server<br/><i>document root: public/</i>"]
        app["Laravel release<br/><i>deploy/deploy.sh</i>"]
        shared[["Shared, outlives a release:<br/>.env<br/>public/images, public/audio<br/>storage/"]]
        cron["Scheduler<br/><i>deploy/windows/install-scheduler-task.ps1</i>"]
        mysql[("MySQL")]
    end

    nginx --> app
    app --- shared
    app --> mysql
    cron -->|"02:30 db:backup<br/>02:45 reports:export"| app
    app -->|"encrypted dumps"| backups[["storage/app/backups<br/><i>pruned to config/backup.keep</i>"]]
    backups -.->|"BACKUP_COPY_TO"| offsite[["Second drive or<br/>synced folder"]]
```

Full steps in [DEPLOYMENT.md](DEPLOYMENT.md); recovery in [RESTORE.md](RESTORE.md).

## 6. What is authored, and what is generated

A frequent question, because the folder on disk is much larger than the project.
**353 files are checked into version control.** Everything else on disk is either
generated by a tool or uploaded by a user, and all of it is listed in
`.gitignore`.

| On disk | Size | In the repo? | Where it comes from |
|---|---|---|---|
| `app/`, `routes/`, `resources/`, `config/`, `database/`, `tests/`, `docs/`, `public/visitor/` | ~4 MB | **Yes** — this is the project | Written by the team |
| `vendor/` | 170 MB | No | `composer install` reads `composer.lock` |
| `node_modules/` | 36 MB | No | `npm ci` reads `package-lock.json` |
| `public/images/`, `public/audio/` | 103 MB | No (`.gitkeep` only) | Uploaded exhibit photos and narration |
| `storage/app/backups/` | ~108 MB | No | `php artisan db:backup`, pruned automatically |
| `storage/logs/`, `storage/framework/` | ~11 MB | No | Runtime logs and compiled Blade cache |
| `public/build/` | ~1 MB | No | `npm run build` (Vite) |

The two lock files are the point: `composer.lock` and `package-lock.json` *are*
committed, and they pin every dependency to an exact version. That is why
`vendor/` does not need to be — any machine can rebuild it identically, and CI
does exactly that on every push before running the suite.

## 7. Directory map

```
app/
  Console/Commands/     backup, restore-key, report export, clear records, set password
  Http/Controllers/     the panel (18)
  Http/Controllers/Api/ the visitor API (/api/v1)
  Http/Middleware/      the 11 gates in section 3
  Models/               Eloquent (22); Visitor carries the door's logic
  Rules/                custom validation
  Services/             Gemini, geofence, recognition, image search, QR
  Support/              ARTA survey, password policy, backups, alerts
  Support/Reports/      ReportBuilder + the four exporters
bootstrap/, config/     framework wiring
database/
  migrations/           50 migrations; the schema in ERD.md
  seeders/media/        the seeded exhibits' pictures and audio
deploy/                 deploy.sh, rollback.sh, the Windows scheduler task
docs/                   this file and its neighbours
public/
  visitor/              the visitor app: index.html, js/app.js, sw.js, model/
  images/, audio/       uploads (git-ignored, shared across releases)
resources/views/        Blade: the panel, the print layouts, the mail
routes/
  web.php               the panel (118 routes)
  api.php               the visitor API
  console.php           the scheduled tasks
tests/
  Feature/              the panel, 45 files
  Feature/Api/          the visitor API
  Unit/                 pure logic
  js/                   the service worker, run by `npm test`
```
