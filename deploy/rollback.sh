#!/usr/bin/env bash
#
# Point current/ back at the release that was live before the last deploy.
#
# The code switch is one atomic rename and takes a second. The database
# is NOT rolled back: migrations in this project are additive, and the
# deploy took a dump before running them (storage/app/backups), so a
# migration that has to be undone is a restore from that dump, done on
# purpose, by a person.
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/museobaler}"
CURRENT="$APP_ROOT/current"
PREVIOUS_FILE="$APP_ROOT/PREVIOUS"

[ -f "$PREVIOUS_FILE" ] || { echo "Nothing to roll back to: $PREVIOUS_FILE is missing."; exit 1; }
PREVIOUS="$(cat "$PREVIOUS_FILE")"
[ -d "$PREVIOUS" ] || { echo "Previous release $PREVIOUS is gone."; exit 1; }

NOW="$(readlink -f "$CURRENT")"
echo "Rolling back: $NOW -> $PREVIOUS"

ln -sfn "$PREVIOUS" "$APP_ROOT/current.new"
mv -Tf "$APP_ROOT/current.new" "$CURRENT"
echo "$NOW" > "$PREVIOUS_FILE"

sudo systemctl reload php8.3-fpm 2>/dev/null || sudo systemctl reload php-fpm 2>/dev/null || true

echo "Live: $(cat "$PREVIOUS/RELEASE" 2>/dev/null || basename "$PREVIOUS")"
