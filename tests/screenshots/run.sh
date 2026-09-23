#!/usr/bin/env bash
#
# Takes the nine screenshots docs/STAFF_MANUAL.md needs, from a throwaway
# database that has never held a real visitor.
#
#   npm run screenshots
#
# The manual's images are committed to the repository, so they must not contain
# anybody's real name, email or address. Rather than relying on whoever runs
# this to remember that, the script builds its own SQLite copy of the schema,
# seeds it with demo data, serves that on a spare port, shoots the panel and
# throws the database away. The museum's real database is never opened.
#
# Needs: PHP, Node, and Chrome already installed. Playwright drives the Chrome
# that is on the machine, so no browser download is required.
#
# Run it again after any UI change; the manual is only as current as its last
# run. On Windows, run it from Git Bash.

set -euo pipefail

cd "$(dirname "$0")/../.."

PORT="${SCREENSHOT_PORT:-8123}"
EMAIL="admin@museobaler.com"
# Only ever the password of a database that is deleted a minute from now.
PASSWORD="Sh0tsDemo@2026x"
# A directory, not a file: mktemp's own file would be left behind if the
# database were named by appending to its path.
WORKDIR="$(mktemp -d -t museobaler-shots-XXXXXX)"
DB="${WORKDIR}/shots.sqlite"

export DB_CONNECTION=sqlite
export DB_DATABASE="$DB"
export APP_ENV=local
export APP_URL="http://127.0.0.1:${PORT}"
export SEED_ADMIN_PASSWORD="$PASSWORD"
export SEED_TOURISM_PASSWORD="$PASSWORD"

server=""

cleanup() {
    if [ -n "$server" ] && kill -0 "$server" 2>/dev/null; then
        kill "$server" 2>/dev/null || true
        wait "$server" 2>/dev/null || true
    fi
    rm -rf "$WORKDIR"
}
trap cleanup EXIT

echo "==> Building a throwaway database"
: > "$DB"
php artisan migrate --force --no-interaction >/dev/null
php artisan db:seed --force --no-interaction >/dev/null
php artisan db:seed --class=DemoDataSeeder --force --no-interaction >/dev/null

# --ready skips the change-password screen the seeded account would otherwise
# be held on, which is the whole point of that flag existing.
php artisan staff:password "$EMAIL" --password="$PASSWORD" --ready >/dev/null

echo "==> Serving it on port ${PORT}"
php artisan serve --host=127.0.0.1 --port="$PORT" >/dev/null 2>&1 &
server=$!

for _ in $(seq 1 30); do
    if curl -sf -o /dev/null "http://127.0.0.1:${PORT}/login"; then
        break
    fi
    sleep 1
done

if ! curl -sf -o /dev/null "http://127.0.0.1:${PORT}/login"; then
    echo "The server never came up on port ${PORT}." >&2
    exit 1
fi

echo "==> Capturing"
BASE_URL="http://127.0.0.1:${PORT}" \
STAFF_EMAIL="$EMAIL" \
STAFF_PASSWORD="$PASSWORD" \
    node tests/screenshots/capture.mjs

echo "==> Done. Review the images before committing them."
