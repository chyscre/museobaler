# Ownership and Access Register

Who holds what, so that the day someone leaves, is on holiday, or is
simply asked "where is the key?", the answer is on this page and not in
their head.

**This file is a template. It is committed to the code repository, which
means anyone with the code can read it — so it must never contain an
actual password, key or token.** Fill the *Where the secret is kept*
column with the *place* (a password manager entry, a sealed envelope in
the Tourism office safe, a named USB stick), never the secret itself.
Keep the filled-in copy where that column says, and review it each
quarter (the last section has a table for that).

---

## ⚠️ Read this first: the backup encryption key

Every night the system writes an encrypted copy of the whole database and
of every picture and audio guide. The encryption uses a key called
`BACKUP_ENCRYPTION_KEY`, kept in the server's `.env` file.

> **If that key is lost, every backup ever made with it is permanently
> unreadable. Not "hard to recover" — unreadable, by anyone, including the
> people who wrote this system. The museum's visitor records would then
> exist only on the one server, and a dead disk would be the end of them.**

So the key must exist in **two places that cannot fail together**:

1. In `.env` on the server (the system needs it there to write backups).
2. **Somewhere that is not the server** — printed on paper in a sealed
   envelope in the Tourism office safe, *and/or* in the office's password
   manager. A copy on the same machine, the same drive, or the same cloud
   account as the backups themselves does not count.

The key looks like a long line of random letters, numbers, `+`, `/` and
`=`. It was printed once by `php artisan db:backup:key` when the system
was set up. If you ever rotate it (make a new one), the old backups still
need the *old* key — keep both, labelled with dates.

**Check, once a quarter, that the envelope is where this register says.**
`docs/RESTORE.md` has the restore steps and a table to record the drill.

---

## The register

Fill in every row. "Owner" is the *person* answerable for it, by name and
role, not "IT". "Where the secret is kept" is a location, never a value.

### Money and outside services

| What | Used for | Account holder / login email | Owner (name, role) | Billing / renewal | Where the secret is kept | Last checked |
|---|---|---|---|---|---|---|
| **Google AI Studio (Gemini) API key** — `GEMINI_API_KEY` | AI translations and spoken narration for exhibits (`docs/STAFF_MANUAL.md` §3). The panel caps use at 20 requests/minute and 300/day per staff account (`throttle:ai`). | | | Google Cloud billing account: ____ · card on file expires ____ | | |
| **Email (SMTP) account** — `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | The system's alert emails: a backup that failed or has not run, an unhandled error in production. Sent to `ALERT_EMAIL`. | | | | | |
| **Alert inbox** — `ALERT_EMAIL` | Where those alerts land. **Someone must read this inbox.** | | | — | — | |
| **Domain registrar** — the museum's web address | Renewal; DNS. | | | Renews on ____ ; auto-renew: yes / no | | |
| **Cloudflare** (DNS, TLS, or the tunnel) | If used in front of the server, or `cloudflared` for a tunnel. See `bootstrap/app.php` for the trusted proxy ranges. | | | free / paid plan | | |

### The server

| What | Used for | Owner (name, role) | Where the secret is kept | Last checked |
|---|---|---|---|---|
| **Hosting provider account** (the VPS / server subscription) | Where the system runs. Billing, reboot, console access. | | | |
| **Server login** (SSH user / root, or RDP) | Deploys, restores, reading logs. | | | |
| **Database account** — `DB_USERNAME`, `DB_PASSWORD` | The MySQL user scoped to this database. | | | |
| **`APP_KEY`** in `.env` | Encrypts sessions and anything Laravel encrypts. Losing it signs everyone out and breaks encrypted session data; it is *not* the backup key. | | | |
| **`BACKUP_ENCRYPTION_KEY`** | See the warning at the top. | | **envelope in ____ AND password manager entry ____** | |
| **`BACKUP_COPY_TO`** — the second disk / synced folder | The off-machine copy of every backup. Who owns that drive or cloud account? | | | |
| **The `.env` file itself** | Holds all of the above. A copy belongs with the backup key, off the server. | | | |

### The code

| What | Used for | Owner (name, role) | Where the secret is kept | Last checked |
|---|---|---|---|---|
| **Git remote** (GitHub / GitLab organisation and repository URL) | The only copy of the code besides the server. CI and Dependabot run here. `REPO` in `deploy/deploy.sh`. | | (organisation owner login) | |
| **Deploy key / CI secrets** | If the server pulls from the repository with a key. | | | |
| **The maintainer** | Who to call when `docs/STAFF_MANUAL.md` §12 says "tell the maintainer". Name, phone, email, and whether there is a support agreement. | | — | |

### People with access today

One row per person. When someone leaves, every row they appear in above
needs a new owner, and their logins below need removing.

| Person | Role | Panel account (email) | Server login? | Git access? | Google Cloud / Gemini? | Cloudflare / registrar? | Removed on |
|---|---|---|---|---|---|---|---|
| | | | | | | | |

---

## When someone leaves

1. Disable their panel account: **Staff → Toggle** (Tourism Head).
2. Remove their server, Git, Google Cloud and Cloudflare logins.
3. Reassign every row above that names them.
4. If they knew the backup key or the `.env` contents: rotate the database
   password and `APP_KEY`; for the backup key, generate a new one with
   `php artisan db:backup:key`, put it in `.env`, and keep the old key in
   the envelope too, dated, for the backups made under it.
5. Note the date in the last column of the people table.

## Quarterly review

| Date | Reviewed by | Envelope present? | Alert inbox read? | Off-site backup copy current? | Notes |
|---|---|---|---|---|---|
| | | | | | |
