#!/usr/bin/env bash
#
# Blue/green deployment for a Linux host.
#
# Two full copies of the application live side by side under RELEASES, and
# a symlink called CURRENT points at whichever one is live. A deploy builds
# the new copy entirely - dependencies, assets, config cache, migrations -
# while the old one keeps serving. Only once /up answers 200 from the new
# copy does the symlink move, which is a single atomic rename: no request
# ever sees a half-deployed tree. The previous release stays on disk, so
# rollback.sh is the same rename in the other direction and takes a second.
#
# Usage:  deploy/deploy.sh <git-ref>        (a tag, branch or commit)
# Needs:  git, php, composer, node, and the web server's docroot pointing at
#         $CURRENT/public. See docs/DEPLOYMENT.md.
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/museobaler}"
REPO="${REPO:-git@github.com:YOUR-ORG/museobaler.git}"
REF="${1:?usage: deploy.sh <git-ref>}"

RELEASES="$APP_ROOT/releases"
SHARED="$APP_ROOT/shared"
CURRENT="$APP_ROOT/current"
KEEP=3

STAMP="$(date +%Y%m%d%H%M%S)"
NEW="$RELEASES/$STAMP"

log() { printf '\n[deploy] %s\n' "$*"; }

# ── 1. Fetch the exact code being deployed ─────────────────────────────────
log "Cloning $REF into $NEW"
mkdir -p "$RELEASES" "$SHARED/storage" "$SHARED/backups"
git clone --quiet --depth 1 --branch "$REF" "$REPO" "$NEW" 2>/dev/null \
  || { git clone --quiet "$REPO" "$NEW" && git -C "$NEW" checkout --quiet "$REF"; }
git -C "$NEW" rev-parse --short HEAD > "$NEW/RELEASE"

# Development-only pages that live in the repo because they are useful on the
# build machine, and nowhere else. Anything under public/ is world-readable
# once deployed, so they do not travel.
rm -f "$NEW/public/visitor/preview.html" "$NEW/public/visitor/_uidebug"*.html
rm -rf "$NEW/.git"

# ── 2. Shared state: .env and storage are never inside a release ───────────
# The database, uploaded exhibit images, the logs and the backups must all
# survive a deploy and a rollback, so they live in shared/ and every release
# points at them.
[ -f "$SHARED/.env" ] || { echo "No $SHARED/.env - copy .env.example there and fill it in first."; exit 1; }
ln -sfn "$SHARED/.env" "$NEW/.env"
rm -rf "$NEW/storage"
ln -sfn "$SHARED/storage" "$NEW/storage"
mkdir -p "$SHARED/storage"/{app/public,app/backups,framework/{cache,sessions,views},logs}

# Uploaded media lives under public/ in this app. Keep it shared too.
for dir in images/exhibits images/training audio; do
  mkdir -p "$SHARED/public/$dir"
  rm -rf "$NEW/public/$dir"
  ln -sfn "$SHARED/public/$dir" "$NEW/public/$dir"
done

# ── 3. Build, entirely in the new tree ─────────────────────────────────────
cd "$NEW"
log "Installing dependencies"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
npm ci --silent
npm run build --silent

log "Caching config and routes"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link --force

# ── 4. Database ─────────────────────────────────────────────────────────────
# A backup first: a bad migration is the one thing a symlink cannot undo.
log "Backing up the database before migrating"
php artisan db:backup

log "Running migrations"
php artisan migrate --force

# ── 5. Smoke test the new release before anyone sees it ────────────────────
# Boot it on a throwaway port and ask the health endpoint. If this fails
# the old release is still live and nothing has changed for anyone.
log "Health check"
php artisan serve --host=127.0.0.1 --port=8199 >/dev/null 2>&1 &
SERVE_PID=$!
sleep 3
STATUS="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8199/up || true)"
kill "$SERVE_PID" 2>/dev/null || true
if [ "$STATUS" != "200" ]; then
  echo "Health check returned $STATUS - the new release is NOT live. Old release untouched."
  exit 1
fi

# ── 6. Switch ───────────────────────────────────────────────────────────────
# ln -sfn onto a temporary name, then mv: mv of a symlink is atomic, so
# there is no instant where current/ does not exist.
log "Switching current -> $STAMP"
[ -L "$CURRENT" ] && readlink -f "$CURRENT" > "$APP_ROOT/PREVIOUS"
ln -sfn "$NEW" "$APP_ROOT/current.new"
mv -Tf "$APP_ROOT/current.new" "$CURRENT"

# PHP-FPM caches realpaths; a reload makes it see the new tree.
sudo systemctl reload php8.3-fpm 2>/dev/null || sudo systemctl reload php-fpm 2>/dev/null || true

# ── 7. Tidy old releases, keeping enough to roll back ──────────────────────
cd "$RELEASES"
ls -1dt */ | tail -n +$((KEEP + 1)) | xargs -r rm -rf

log "Live: $(cat "$NEW/RELEASE") at $CURRENT"
