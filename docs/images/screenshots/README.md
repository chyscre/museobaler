# Screenshots for the manuals

`docs/STAFF_MANUAL.md` and the two PDF manuals in `docs/` show the 32 images
below: 22 of the staff panel and 10 of the visitor app. **Do not take them by
hand** — they are generated, and a hand-taken one will be overwritten the next
time anybody runs:

```bash
npm run screenshots
```

That builds a throwaway SQLite database, seeds it with demo data, serves it on
port 8123, drives the panel with Playwright and deletes the database again.
It needs PHP, Node and Chrome; Playwright uses the Chrome already installed
rather than downloading its own.

Run it again after any UI change. The manual is only as current as its last run.

## Why it is automated

These images are committed to the repository, so a screenshot of the real panel
would commit a real visitor's name, hometown and payment status along with it.
The capture script never opens the museum's database — every name, group and
figure in these images comes from `DemoDataSeeder` and belongs to nobody.

The second reason is that hand-taken screenshots go stale the first time a
button moves, and nothing tells you they have.

## What each one shows

| File | Page | Shows |
|---|---|---|
| `sign-in.png` | `/login` | The sign-in form. |
| `add-exhibit.png` | Exhibits → **Add Exhibit** | The modal, opened. |
| `edit-exhibit.png` | Exhibits → an exhibit → **Edit** | The whole page: details on the left, translations, recognition and gallery on the right. |
| `ai-translate-narrate.png` | The **Translations & Audio** card alone | The language cards with their narration players. |
| `qr-codes.png` | **QR Codes** | The sheet with **Print All** and **Download All (ZIP)**. |
| `museum-map.png` | **Map**, in edit mode | The floor plan with pins and the Storyline Path panel. |
| `museum-info.png` | **Museum Info**, scrolled to Geofencing | The fee, hours and the geofence settings. |
| `front-desk.png` | **Front Desk** | Both registration forms open, and *Registered today*. |
| `recognition.png` | **Recognition** | The exhibit list with photo counts and **Train the model**. |
| `dashboard.png` | `/dashboard` | Counts, the visitor chart, recent activity. |
| `exhibits.png` | **Exhibits** | The collection list and the archived filter. |
| `records.png` | **Records** | Visitors and their payment state. |
| `feedback.png` | **Feedback** | What visitors said, and the ARTA CSM figures. |
| `tours.png` | **Tours** | Starting and ending a guided tour. |
| `attendance-kiosk.png` | **Attendance → Kiosk** | The staff-room code, which rotates every 60 s. |
| `staff-attendance.png` | **Staff Attendance** | The sheet, and the way to one person's DTR. |
| `logs.png` | **Logs** | The audit trail. |
| `desk-poster.png` | **Front Desk → Poster** | The printable entrance poster. |
| `reports-logbook.png` | **Reports → Logbook** | The daily logbook and its export toolbar. |
| `staff.png` | **Staff** *(Tourism)* | Accounts, password resets, disabling. |
| `survey.png` | **Survey** *(Tourism)* | The ARTA question bank. |
| `corrections.png` | **Attendance → Corrections** *(Tourism)* | Corrections awaiting approval. |

## The visitor app

Shot on a 390×844 phone viewport, in a second browser context. These are driven
by clicking the app's own navigation, so each one is a state a visitor really
reaches.

| File | Screen | Shows |
|---|---|---|
| `visitor-register.png` | `s-register` | Registering, or signing in. |
| `visitor-pending.png` | `s-pending` | The admission gate: waiting for the desk. |
| `visitor-mode.png` | `s-mode-choice` | Storyline or free roaming. |
| `visitor-home.png` | `s-home` | Home, once cleared. |
| `visitor-exhibits.png` | `s-home-exhibits` | Browsing the collection. |
| `visitor-exhibit.png` | `s-exhibit` | One exhibit, with its narration player. |
| `visitor-map.png` | `s-map` | The map, both floors. |
| `visitor-profile.png` | `s-profile` | Scanned, saved, halls covered. |
| `visitor-settings.png` | `s-settings` | Language, mode, audio, accessibility. |
| `visitor-about.png` | `s-about` | Hours, fee and contact, from Museum Info. |

### Two things the visitor app forces on the capture

`tests/screenshots/demo-visitors.php` creates two accounts, because
`DemoDataSeeder`'s visitors are counter records with no email and no password.

- **The cleared account's `last_visit` must be today.** Admission is charged per
  visit, so signing in runs `Visitor::touchReturning()`, which resets a tourist
  to *Unpaid*. Without it the "cleared" fixture arrives at the waiting screen —
  correct behaviour, wrong screenshot.
- **`visitor-mode.png` is opened directly**, not arrived at. The storyline/free
  choice is offered once, after a visitor first registers; signing in reports
  them as returning, which is exactly what suppresses it. A pre-created account
  can never land there, so `capture.mjs` opens that screen itself. It is a real
  screen in a state first-time visitors see — just not one this fixture reaches.

## Changing what is captured

`tests/screenshots/capture.mjs` holds one entry per image. Each names the page
to open and, optionally:

- `click` — a button to press first, such as the one that opens a modal
- `scrollTo` — bring a section into view before shooting
- `element` — shoot one card instead of the page
- `expandDetails` — open every collapsed accordion first
- `fullPage` — grow the window until the whole screen fits in one image
- `anonymous` — shoot before signing in

A selector that matches nothing is reported as a warning and the image is still
written, so a stale selector shows up in the output rather than silently
producing the wrong picture. Check the output when you run it.
