# Known Limitations and Design Boundaries

What the system deliberately does not do, where it bends, and what a
future developer should know before "fixing" something that is the way it
is on purpose. Each entry says what, why, and what it would take to change.

---

## The floor plan is a drawing in the code

The museum map — walls, rooms, stairs — is two inline SVG drawings:
ground floor (`viewBox 0 0 1660 1000`) and second floor (`0 0 1770 1010`),
duplicated in `resources/views/museum/map.blade.php` (the panel) and
`public/visitor/index.html` (the app). Exhibit pins are data: their
`map_x`/`map_y` are percentages of the plan, dragged and saved by staff
on the Map page and stored on the exhibit.

**Why:** the building does not change; a drawing edited by hand in a
vector tool once is simpler and looks better than a floor-plan editor
nobody would use twice.

**If a wall moves:** edit both SVGs, keeping the `viewBox` the same so
existing pins stay in place, or re-drag every pin afterwards. Keep the
`data-floor` attributes and the pin layer `<g>` the app draws into.

## The AI has limits, and the museum works without it

Translations and narration come from Google's Gemini (text model and TTS
model set in `config/services.php`, key in `GEMINI_API_KEY`).

- **Budget:** `throttle:ai` caps each staff account at 20 requests a minute
  and 300 a day. Those are the only two routes that cost money.
- **No key:** the buttons say so and everything else works. Translations
  can be typed and recordings uploaded by hand.
- **Refused or declined:** Google's content filter or a quota error is
  shown as-is; the draft is not saved.
- **Synchronous:** each request blocks the browser for a few seconds.
  Queueing them was considered and deferred - it would need a queue
  worker running on the server, which is one more thing to keep alive for
  a task that happens a few times a month.
- **Model names go stale.** The defaults name preview models; when Google
  retires one, set `GEMINI_TEXT_MODEL` / `GEMINI_TTS_MODEL` in `.env`.

## Offline works within what a phone has already seen

The visitor app's service worker (`public/visitor/sw.js`, `docs/DEPLOYMENT.md`)
keeps the app shell, pictures and audio, and the last good copy of the
gated content - exhibit list, single exhibits, the museum record, the
visitor's own clearance.

- **It is a fallback, not a mode.** The last good copy is served only
  when the network fails outright. A visitor who never loaded an exhibit
  online will not get it offline.
- **Nothing that changes the museum works offline** by design:
  registration, sign-in, scans, feedback, attendance and the survey need
  the server and say so. There is no queue of pending writes to replay
  later - a scan logged an hour late would put visitors in the wrong
  hall on the reports.
- **Gated content on a phone is gated by the phone.** Once cached, the
  exhibit text is on the device; a 401/403 from the server or signing out
  deletes it, but a visitor who paid today and turns the phone to
  aeroplane mode keeps reading. The fee is a per-visit fee; this is
  accepted.
- **HTTPS is required.** Service workers, the camera (`getUserMedia`) and
  geolocation all need a secure context: `https://`, or `localhost`. Over
  a plain LAN address (`http://192.168.x.x`) the worker is absent, the QR
  scanner cannot open the camera, and the geofence has no position - the
  app still runs, with typed codes and no attendance. The tunnel or a TLS
  certificate on the server fixes all three at once.
- **Storage is the browser's.** iOS evicts caches of sites not used for
  a week or so; Android less aggressively. A phone that comes back after
  a month re-downloads ~5 MB of shell and model.

## The admission gate lives on the server, the waiting screen on the phone

A visitor's phone cannot unlock itself: exhibit content is only served to
a token whose visitor the desk has cleared (`EnsureVisitorCleared`). The
app's "waiting for the desk" screen is a courtesy rendering of that
answer, polled from `GET /api/v1/visitors/me`. Editing localStorage
changes the screen, not the answer.

## Visitor accounts are simple on purpose

- **No password reset.** Visitors register with an email and a password of
  their choosing. If they forget it, they register again with another
  address; there is no email sending to visitors and no identity to
  verify against. Staff cannot see or set a visitor's password.
- **Email is checked for shape and a live domain, not ownership.** An MX
  lookup rejects `asdf@asdf.com`; it does not prove the visitor owns the
  address. A verification email at the entrance queue was judged a worse
  trade than the occasional typo.
- **Visitor passwords are length + blocklist**, deliberately without
  character-class rules (NIST SP 800-63B), and are a different policy
  from staff passwords. See `VisitorPasswordPolicy`.

## One museum, one currency, one time zone

`APP_TIMEZONE=Asia/Manila` and both database connections pin MySQL's
session zone to it. The fee is pesos. The barangay list is Baler's
thirteen. Running this for a second museum means a second install, not
a setting.

## Visitor PII is stored in the clear in the database

Names, emails, ages and addresses are ordinary columns: the panel, the
API and every report read them. Column-level encryption would make search,
grouping and the CSV exports decrypt row by row. Mitigated by a scoped DB
user, no direct database exposure, **encrypted backups**, and disk
encryption on the host. Recorded in `docs/SECURITY.md` as a known
limitation; the Data Privacy Act notes there say what is collected and
why.

## Things a developer will trip over

- **`ext-zip` must be enabled.** *QR Codes → Download All (ZIP)* uses
  `ZipArchive`. Laragon's `php.ini` ships it commented out
  (`;extension=zip`); uncomment and restart Apache. The Ubuntu install in
  `docs/DEPLOYMENT.md` lists it. Without it, single SVG downloads and
  *Print All* still work.
- **No `db:restore` command.** Restore is two documented commands
  (`db:backup:decrypt`, then `mysql` / `tar`); `docs/RESTORE.md` is written
  for the person holding the drive. An automated restore that could wipe
  the live database by accident was judged worse than a checklist.
- **The scheduler must be woken by the OS.** Nothing runs at 02:30 unless
  Task Scheduler (Windows, `deploy/windows/install-scheduler-task.ps1`)
  or cron (Linux) calls `php artisan schedule:run` every minute. A fresh
  machine has no backups until this is done; the stale-backup alert only
  fires in production.
- **The test suite runs on SQLite; production is MySQL.** Anything
  MySQL-specific (a raw `NOW()`, an enum change) needs a manual check
  against MySQL. `php artisan migrate:fresh --seed` against a scratch
  database name is the quickest way (Laravel creates it).
- **Tests write QR SVGs into `public/images/qr/`.** That folder is
  git-ignored, so nothing leaks into commits, but the files appear.
- **Pint is not a CI gate.** The codebase uses aligned `=>` throughout;
  Pint would rewrite ~150 files. CI runs tests and audits only.
- **The `notifications` table has no editor.** The app reads it
  (`GET /api/v1/notifications`); nothing in the panel writes it. It is
  seed data until someone builds the notice-board screen.
- **Five raw-API actions were not ported** to Laravel because nothing
  called them: visitor bookmarks, `set_mode`, `my_guide`, the visitor
  profile-with-stats, and attendance's daily count. Each is a small
  controller if a screen ever wants one.

## Not built, and why

| Not built | Because |
|---|---|
| Multi-museum / multi-tenant | One museum. See above. |
| Visitor password reset by email | No mail to visitors; see above. |
| A native app | The PWA installs from the browser and uses the camera and GPS; a store listing would add a review process for no capability. |
| Queued AI generation | A worker process to keep alive, for a few requests a month. |
| Real-time dashboards | The bell polls every ten seconds; the desk's list refreshes on action. WebSockets would need a socket server. |
