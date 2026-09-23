# Screenshots for the staff manual

`docs/STAFF_MANUAL.md` shows the nine images below. **Do not take them by
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
