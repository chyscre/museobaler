# Hosting: buying it, setting it up, handing it over

`docs/DEPLOYMENT.md` starts at a server that already exists. This page is
everything before that: which accounts to open, in whose name, how to get
Ubuntu from a blank VPS to the point where `deploy/deploy.sh` will run, and
what has to happen on the day the museum takes the bill over.

Read the two questions below before spending any money. Either answer
changes what you buy.

---

## What the addresses will be

Two, not three. There is no separate Tourism site.

| Who | Where |
|---|---|
| Visitors | `https://museodebaler.com` — the bare domain redirects to the visitor app at `/visitor/` |
| Visitors, from a display case | `https://museodebaler.com/visitor/?scan=EXH-2026-001` — what the QR codes encode |
| All staff, both roles | `https://museodebaler.com/login` |

The Administrator panel and the Tourism panel are the same application at
the same URL. `routes/web.php` gates each route on `role:Administrator`,
`role:TourismHead` or both, so signing in is what decides the menu — the
head of tourism never sees the exhibit editor, and museum staff never see
account creation or the audit trail. Signing in also lands the two roles on
different screens, which `LoginController::homeFor` decides.

`DesktopOnly` keeps the panel on office computers. Staff phones can reach
clock-in and the recognition photo pages, which need a camera, and nothing
else.

---

## Before you spend anything

**1. Is the museum a government unit?** If it sits under the NHCP or the
LGU, it can have hosting and a `.gov.ph` address from DICT's Government Web
Hosting Service for free, and AO 39 s. 2013 tells agencies to use it. Ask
the museum's admin officer; `helpdesk@i.gov.ph` handles the applications
(two letters on official letterhead, signed by the agency head, CIO or MIS
head). Provisioning is slow and the service may not let you run your own
cron, so the plan below is still the right way to launch — but if the answer
is yes, skip buying a domain in step 2 and apply for the `.gov.ph` instead.

**2. Does their finance office need a BIR official receipt?** Government and
LGU-attached bodies usually cannot liquidate a foreign provider's PDF
invoice. If they need an OR, buy from a Philippine provider billing in pesos
(ALC Hosting, GoManilaHost) instead of Hostinger. The setup steps are
identical — only the signup in step 4 changes.

---

## What you are buying

| Piece | Choice | Cost |
|---|---|---|
| Server | Hostinger VPS **KVM 2** — 2 vCPU, 8 GB, 100 GB NVMe, Kuala Lumpur | ~P819/mo on 24 months, 3-6x that monthly |
| Server, alternative | DigitalOcean 2 vCPU / 4 GB, Singapore | ~P1,400/mo, no commitment |
| DNS, TLS, CDN | Cloudflare, free plan | free |
| Domain | Cloudflare Registrar, or dot.ph for a `.ph` | ~P800/yr, or P1,500-2,500 |
| Outgoing mail | Brevo or Resend, free tier | free |
| Off-site backups | Cloudflare R2, 10 GB free | free |
| AI translations | Google AI Studio, free tier | free |

About **P900-1,000 a month**, roughly P11-12k a year.

KVM 2 is not a guess. The load here is a municipal museum's footfall — even
a heavy day of 500 visitors with a tour group all scanning the same placard
at once is perhaps 50 concurrent requests, most of them cached reads. What
actually scales with visitors is bandwidth for the audio guides, and
Cloudflare serves those for free in step 8. Do not let anyone sell you a
cluster.

**You do not need a queue worker.** Nothing in this application is queued —
no `ShouldQueue`, no `dispatch()`, and the alert mail in
`app/Support/Alerts.php` sends synchronously. `QUEUE_CONNECTION=database` is
set but unused. One less daemon for the museum to keep alive.

---

## Step 1 — The mailbox, before any other account

Every account below is opened with **one institutional mailbox**, not your
personal address. The email on a hosting account is not a contact detail: it
is the login, it is where password resets arrive, and it is where the
two-factor recovery codes go. Whoever holds that inbox holds the server.

1. Make a free Gmail — `museobaler.system@gmail.com` or similar.
2. Settings, then Forwarding — forward to your own address, so you do not
   have to check it while you are the one running things.
3. Turn on 2FA with an **authenticator app, not SMS**, so recovery is not
   tied to your SIM. Save the backup codes; they go in the register in
   step 12.

This matters for the three accounts that cannot be cheaply re-created — the
domain, the host and Cloudflare. Everything else (the Gemini key, the mail
sender) is a string in `.env` that can be swapped in two minutes, so those
are not worth losing sleep over.

---

## Step 2 — The domain

Skip this if you are going `.gov.ph`.

1. Cloudflare Registrar sells at cost with no renewal markup; dot.ph is the
   registry for `.ph`. Sign up with the step 1 mailbox.
2. Search and buy the name.
3. **Registrant name and address: the museum's.** Not yours. Your card is
   only the payment method.
4. Auto-renew **on**, and note the renewal date for the register.

Get this right the first time. Changing a registrant locks the domain
against transfer for 60 days, which is exactly the kind of surprise you do
not want on turnover day.

---

## Step 3 — Cloudflare

1. Sign up at `dash.cloudflare.com` with the step 1 mailbox. Free plan.
2. **Add a site**, enter the domain, choose Free.
3. Cloudflare gives you two nameservers. Put them in at your registrar.
   (If the domain is Cloudflare's own, this is already done.)
4. Wait for the dashboard to say **Active** — usually minutes, up to a day.

Leave the DNS records alone for now. The A record comes in step 5, and it
stays grey-clouded until TLS is working in step 7.

---

## Step 4 — The VPS

1. `hostinger.com/ph/vps-hosting`, sign up with the step 1 mailbox.
2. Plan **KVM 2**. Term: 24 months — the renewal price is what you will
   actually pay long-term, so the longer term is the cheaper honest number.
3. Billing profile: **the museum's name and address.** Your card as the
   payment method.
4. In the setup wizard: location **Kuala Lumpur** — Hostinger has no
   Singapore region for VPS (only for Cloud), and Malaysia is the nearest
   one it does have, about 350km away and indistinguishable from Manila.
   Jakarta is an equally good second; India is twice as far. OS **Ubuntu 24.04 LTS**
   plain — not a Plesk or OpenLiteSpeed image, which only add a panel you
   would then have to explain to whoever inherits this.
5. Set a root password when asked, and add your SSH key. On Windows, in
   PowerShell:
   ```powershell
   ssh-keygen -t ed25519 -C "museobaler"
   cat $env:USERPROFILE\.ssh\id_ed25519.pub
   ```
   Paste that public key into Hostinger's SSH Keys panel.
6. Note the server's IPv4 address.

---

## Step 5 — First login, and locking the box down

```bash
ssh root@YOUR.SERVER.IP

adduser museo && usermod -aG sudo museo
rsync --archive --chown=museo:museo ~/.ssh /home/museo

apt update && apt upgrade -y
apt install -y unattended-upgrades && dpkg-reconfigure -plow unattended-upgrades

ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw enable
```

Then turn off password logins. With your key already working, this closes
the door on every password-guessing bot on the internet. In
`/etc/ssh/sshd_config` set `PermitRootLogin no` and
`PasswordAuthentication no`, then `systemctl restart ssh`.

**Open a second terminal and confirm `ssh museo@YOUR.SERVER.IP` works before
you close this one.** If the key is wrong you have just locked yourself out,
and only Hostinger's browser console will get you back in.

`unattended-upgrades` matters more here than on a server with staff behind
it. Nobody at the museum is going to apply security patches by hand.

Now add the DNS record: in Cloudflare, DNS, Add record — `A`, name `@`, the
server IP, **proxy OFF (grey cloud)** for now. Add a second `A` for `www`
the same way. Certbot in step 7 needs to reach the real server.

---

## Step 6 — The software

```bash
sudo apt install -y apache2 mysql-server git unzip curl \
  php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-gd \
  php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath \
  composer

curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

php -m | grep -E 'sodium|gd|mbstring|curl|zip'
```

That last line is a check, not decoration. `deploy.sh` and the backup
encryption need those extensions, and it is better to find one missing now
than halfway through a deploy.

Apache talks to PHP-FPM, and the application ships a `public/.htaccess`
carrying its security headers, so overrides must be allowed:

```bash
sudo a2enmod proxy_fcgi setenvif rewrite headers
sudo a2enconf php8.3-fpm
```

Create `/etc/apache2/sites-available/museobaler.conf`:

```apache
<VirtualHost *:80>
    ServerName museodebaler.com
    ServerAlias www.museodebaler.com
    DocumentRoot /var/www/museobaler/current/public

    <Directory /var/www/museobaler/current/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    ErrorLog  ${APACHE_LOG_DIR}/museobaler-error.log
    CustomLog ${APACHE_LOG_DIR}/museobaler-access.log combined
</VirtualHost>
```

`AllowOverride All` and `+FollowSymLinks` are both load-bearing: the first
lets `.htaccess` apply, the second lets Apache follow the `current` symlink
that the whole blue/green scheme in `docs/DEPLOYMENT.md` depends on.

```bash
sudo a2ensite museobaler && sudo a2dissite 000-default
sudo systemctl reload apache2
```

Then the database:

```bash
sudo mysql_secure_installation

sudo mysql -e "CREATE DATABASE museobaler CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'museobaler'@'localhost' IDENTIFIED BY 'A-LONG-RANDOM-PASSWORD';
GRANT ALL PRIVILEGES ON museobaler.* TO 'museobaler'@'localhost';
FLUSH PRIVILEGES;"
```

A user scoped to this one database, not root. If the application is ever
compromised, that is the difference between losing this database and losing
everything MySQL holds.

---

## Step 7 — TLS

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d museodebaler.com -d www.museodebaler.com
```

Choose redirect when it offers. Certbot installs a renewal timer, so this is
genuinely set-and-forget — confirm with `systemctl list-timers | grep certbot`.

This is why the A record was grey-clouded in step 5: certbot proves it
controls the domain by answering on port 80 at the real server, which is
simplest when Cloudflare is not in the way.

**Then turn on HSTS.** `public/.htaccess` ships it commented out, with a note
saying to enable it only once there is a real certificate — that is now true,
so uncomment the block:

```apache
<IfModule mod_headers.c>
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
</IfModule>
```

Leave it commented until certbot has actually succeeded. HSTS tells every
browser that has seen the site to refuse plain HTTP for a year, and a
browser that learns that before the certificate works will refuse to load
the site at all.

---

## Step 8 — Put Cloudflare in front

Now go back to Cloudflare, DNS, and switch both A records to **proxied
(orange cloud)**.

1. **SSL/TLS, Overview, Full (strict).** Not Flexible. Flexible makes
   Cloudflare talk to your server over plain HTTP, which together with the
   app's own HTTPS redirect produces an infinite redirect loop — the single
   most common way this setup breaks.
2. **SSL/TLS, Edge Certificates, Always Use HTTPS: on.**
3. The application already trusts Cloudflare's proxy ranges — see
   `bootstrap/app.php` — so visitor IPs and the geofence still see the real
   client address. Nothing to configure.

**One cache rule you must add.** Cloudflare caches `.js` by extension, and
the visitor app's service worker is `public/visitor/sw.js`, which
`docs/DEPLOYMENT.md` explains must stay uncached or phones get stuck on an
old release. Rules, Caching rules, Create:

- Name: `sw.js never cached`
- When: `URI Path equals /visitor/sw.js`
- Then: **Bypass cache**

The audio and images need no rule — Cloudflare caches `.mp3`, `.jpg` and
`.png` by default, and that is the entire bandwidth win.

---

## Step 9 — First deploy

The server layout, `shared/.env`, the cron line and `deploy/deploy.sh` are
all covered in **`docs/DEPLOYMENT.md`, "First-time server setup"**. Follow it
from its step 2 onward — its step 1 is the packages you just installed.

Two things from that page are worth repeating, because they are the ones
people skip:

- `BACKUP_ENCRYPTION_KEY` from `php artisan db:backup:key`, **with a copy
  kept off the machine**. See step 11.
- The cron line, or the nightly backup simply never runs:
  ```
  * * * * * cd /var/www/museobaler/current && php artisan schedule:run >> /dev/null 2>&1
  ```

You will also need `REPO` in `deploy/deploy.sh` pointing at your Git remote,
and a deploy key on the server if the repository is private.

---

## Step 10 — Outgoing mail and the alert inbox

Without this, nobody hears when a backup fails.

1. Sign up at Brevo (300 mails/day free) or Resend (3,000/month) with the
   step 1 mailbox.
2. Verify the domain by adding the SPF and DKIM records they give you to
   Cloudflare DNS. They are TXT records — **DNS-only, grey cloud**.
3. Fill `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` and
   `MAIL_FROM_ADDRESS` in `shared/.env`.
4. Set `ALERT_EMAIL` to an address **a person actually reads** — the
   museum's admin officer, not the system mailbox.
5. Test it. `php artisan db:backup` should reach you if it fails.

---

## Step 11 — Get the backups off the machine

`BACKUP_COPY_TO` in `config/backup.php` takes a filesystem path, and a copy
on the same disk as the original is not a backup. Cloudflare R2 gives 10 GB
free, comfortable for a database dump plus the media tarball.

```bash
sudo -v ; curl https://rclone.org/install.sh | sudo bash
rclone config      # new remote, type: s3, provider: Cloudflare R2
```

Then a nightly push at 03:30 — after the 02:30 backup and the 02:45 export
in `routes/console.php` have both finished:

```
30 3 * * * rclone sync /var/www/museobaler/shared/storage/app/backups r2:museobaler-backups >> /var/log/rclone.log 2>&1
```

**And the key.** Print `BACKUP_ENCRYPTION_KEY`, seal it in an envelope, put
it in the Tourism office safe. The warning at the top of
`docs/OWNERSHIP.md` is not overstated: without that key, every backup ever
made is unreadable by anyone, permanently. Do this on the day you set the
server up, not "later".

Then run the restore drill in `docs/RESTORE.md` once, now, while you still
have nothing to lose. A backup nobody has ever restored is a hypothesis.

---

## Step 12 — Fill in the register

`docs/OWNERSHIP.md` is already the handover document; it just needs filling
in. Every account you opened above has a row waiting for it.

**Keep the filled-in copy out of the repository** — the file says so itself,
and it is right, because anyone with the code can read anything committed.
Put it in the office password manager, or print it for the safe.

---

## Turnover day

Because every account was opened in the museum's name with the step 1
mailbox, this is not a migration. Nothing moves.

1. They add their card to Hostinger, Cloudflare Registrar and R2; you remove
   yours.
2. Change the step 1 mailbox password and re-enrol 2FA on their device. Hand
   over the new password and the backup codes.
3. Regenerate the Gemini key in whichever Google account they will own, put
   it in `shared/.env`, then `sudo systemctl reload php8.3-fpm`. Same for the
   mail credentials if those move.
4. Rotate what you knew: the MySQL password, `APP_KEY`, and the server login.
   `docs/OWNERSHIP.md`, "When someone leaves", has the full list — follow it,
   treating yourself as the leaver.
5. Update the register, and hand over `docs/STAFF_MANUAL.md`.
6. Agree in writing who they call when something breaks, and whether that is
   you. The register has a row for it.

Step 6 is the one that actually decides whether this system is alive in two
years. Certbot renews itself and `unattended-upgrades` patches itself, but a
full disk or a failed deploy needs a person. If nobody at the museum can
SSH, budget either your phone number for the first year or about $8-12/mo
for a managed panel like Ploi or Laravel Forge, so that updates are a button
rather than a terminal.
