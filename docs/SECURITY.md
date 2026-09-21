# Security and privacy controls

What protects the data, where it is enforced in the code, and how to show
it working. Written to be handed to an evaluator; every line points at a
file.

## Who can do what (role-based access control)

Two staff roles, enforced server-side on every route by
`app/Http/Middleware/EnsureRole.php`, grouped in `routes/web.php`:

| | Administrator (museum staff) | TourismHead (Tourism office) |
|---|---|---|
| Front desk, exhibits, map, tours, clocking in, museum settings | ✔ | ✘ |
| Records, feedback, reports, audit log (read) | ✔ | ✔ |
| Staff accounts, schedules, correction approvals, audit export, survey questions | ✘ | ✔ |

A denied request is a 403 and a line in the security log. Neither role can
file *and* approve an attendance correction (`AttendanceCorrectionController`),
and nobody can file one for themselves.

Visitors are a separate population entirely: they never have a panel
account. The visitor API (`routes/api.php`, under `/api/v1`) identifies a
visitor by a random 64-hex bearer token issued at sign-in, stored only as a
SHA-256 hash, valid for 24 hours (the `visitor` guard in
`AppServiceProvider`, `Visitor::findByToken`). Exhibit content sits behind
`EnsureVisitorCleared` as well: a token gets a visitor as far as the desk,
and only a collected fee or a sighted residency ID gets them past it.
Every write — attendance, scans, feedback — is attributed to the visitor
the token resolves to.
A `visitor_id` in the request body is ignored unless it agrees with the
token, so no visitor can read or write another visitor's record.

**Show it:** sign in as museum staff, paste `/staff` in the address bar —
403 page. Sign in as Tourism, paste `/desk` — 403. `tests/Feature/RoleAccessTest.php`
pins both directions.

## Sign-in

- Passwords: bcrypt, 12 rounds (`BCRYPT_ROUNDS`). Visitor passwords the
  same, under `VisitorPasswordPolicy`: length and a common-password
  blocklist, deliberately without character-class rules (NIST SP 800-63B).
- Staff passwords are issued by Tourism and **must be changed at first
  sign-in** (`RequirePasswordChange`); changing one signs out every other
  session on that account (`AuthenticateSession` pins a session to the password hash it was opened with).
- Login throttle: 5 failed attempts per IP per minute (`LoginRateLimiter`).
  Visitor sign-in: 6 per account per minute, 30 per address per 5 minutes.
- Sessions: 120 minutes, end when the browser closes, encrypted cookie,
  `HttpOnly`, `SameSite=Strict`, secure over TLS (`config/session.php`).
  "Remember me" is deliberately not offered.
- Password reset links, if a reset flow is ever added, expire after 30
  minutes (`config/auth.php`).

## Input and output

- Every string field in every panel request has HTML tags and control
  characters removed before validation (`SanitizeInput` middleware), on the
  visitor API's JSON bodies as well as the panel's forms. Passwords are
  exempt.
- All database access is parameterised: Eloquent throughout, panel and
  visitor API alike. Request shapes are Form Requests (`app/Http/Requests/Api`).
- Output is escaped: Blade `{{ }}` everywhere in the panel (the one `{!! !!}`
  renders a hard-coded SVG icon set), `escapeHtml()` in the visitor app.
- Uploaded files are served through routes that `basename()` the filename
  and check the file exists, never by a user-supplied path.
- CSRF tokens on every form; an expired one is caught and explained
  (`bootstrap/app.php`).

**Show it:** register a visitor named `<script>alert(1)</script>` — the
record saves as `alert(1)`, nothing executes anywhere.

## Browser and API hardening

- `SecurityHeaders` middleware on every panel response: Content-Security-Policy
  (self + the two CDNs the layout uses), `X-Frame-Options: DENY`,
  `nosniff`, `Referrer-Policy`, `Permissions-Policy` scoping camera and
  geolocation to this origin only, `X-Powered-By` removed.
- The visitor API answers CORS only for origins listed in
  `API_ALLOWED_ORIGINS` (`config/cors.php`); same-origin needs none. Every
  API response carries `nosniff`, `Cross-Origin-Resource-Policy:
  same-origin` and `Cache-Control: no-store`, except the exhibit list, which
  is `private, no-cache` with an ETag so an unchanged museum is a 304
  (`ApiResponseHeaders`).
- Rate limits: 60 requests/minute/IP across the visitor API, 120 for camera
  sampling, 10 registrations a minute, and sign-in at 6 a minute per account
  and 30 per five minutes per address (`AppServiceProvider`);
  300/minute/account across the panel (`throttle:panel`), 20/minute and
  300/day on the two routes that spend money on AI (`throttle:ai`).
- Proxy trust is explicit (loopback and Cloudflare's published ranges), so
  `X-Forwarded-For` cannot be spoofed to dodge the IP limits.

## Errors

- `APP_DEBUG` is forced off in production even if `.env` says otherwise
  (`AppServiceProvider`), so no stack trace, path or query ever reaches a
  browser.
- Custom pages for 401, 403, 404, 419, 429, 500 and 503
  (`resources/views/errors/`), in plain language with a way back.

## Logs and alerts

- **Audit log** (`logs` table, `AuditLog` middleware): every POST/PUT/PATCH/DELETE
  by a signed-in account — who, what, from where, when. Exportable as CSV by
  Tourism only.
- **Security log** (`storage/logs/security-*.log`, kept 90 days): every
  failed sign-in, lockout, role denial, sign-in and sign-out.
- **Application log**: rotated daily, kept 30 days.
- **Alerts** (`app/Support/Alerts.php`): an email to `ALERT_EMAIL` on any
  unhandled error in production, a failed backup, or a backup older than
  36 hours — throttled to one mail per problem per 30 minutes. The backup
  watchdog runs off ordinary panel traffic, so it still fires when the
  scheduler is what broke.

## Data at rest and in transit

- In transit: TLS at the edge (Cloudflare) or the web server; secure cookies.
- Credentials and tokens: never stored in the clear (bcrypt / SHA-256).
- Session payloads: encrypted (`SESSION_ENCRYPT=true`).
- **Backups: encrypted** with XChaCha20-Poly1305 (libsodium secretstream)
  when `BACKUP_ENCRYPTION_KEY` is set — authenticated, so a tampered file
  refuses to decrypt. `php artisan db:backup:key` makes a key,
  `db:backup:decrypt` opens a dump. A backup that goes missing on a USB
  stick is then a lost stick, not a breach.
- Visitor PII columns (name, email, age, address) are stored in the clear
  in MySQL. They are read by both the Laravel panel and the raw-PHP visitor
  API, and they drive search, grouping and the CSV reports; column-level
  encryption would need both stacks to share a cipher and every report to
  decrypt row by row. Mitigated by: no direct database exposure, scoped DB
  user, encrypted backups, disk encryption on the host. Recorded as a known
  limitation rather than a hidden one.

## Backups

- `php artisan db:backup` nightly at 02:30 (`routes/console.php`):
  `mysqldump --single-transaction`, gzipped, encrypted, 14 kept, copied to
  `BACKUP_COPY_TO` on a second disk, with an alert on any failure. The same
  run writes `<stamp>-media.tar.gz` beside the dump: every uploaded picture,
  training photo and audio guide (`config/backup.php`, `media`), encrypted
  with the same key and kept for the same fortnight.
- Requires the OS to wake the scheduler every minute:
  `deploy/windows/install-scheduler-task.ps1` on Windows, one cron line on
  Linux (`docs/DEPLOYMENT.md`).
- Restore: `php artisan db:backup:decrypt <file>` → `gunzip -c … | mysql`,
  then `db:backup:decrypt <file>-media.tar.gz.enc` → `tar -xzf … -C public`.
  Step by step, for whoever is holding the drive: `docs/RESTORE.md`.

## Dependencies and delivery

- `composer audit` and `npm audit` run on every push (`.github/workflows/ci.yml`),
  alongside the full test suite. Dependabot opens weekly update PRs.
- Zero-downtime blue/green deploys with one-command rollback
  (`deploy/deploy.sh`, `deploy/rollback.sh`); every deploy takes a database
  backup before migrating and health-checks the new release before it goes
  live.

## Data Privacy Act (RA 10173) notes

- Collected from visitors: name, age, sex, email, city/barangay/province/
  country, visit type, and — while on the grounds — the phone's location for
  the attendance geofence. Nothing else.
- Purpose: the logbook the museum is required to keep, admission
  (local/tourist fee rules), and the ARTA Client Satisfaction Measurement.
- Access: staff roles above; no third-party services receive visitor data.
  The AI translation feature sends exhibit text only, never visitor records.
- Retention: records are kept for reporting; there is a `museum:clear-records`
  command for purging by date if a retention policy is adopted.
