#!/usr/bin/env bash
# Prepare the isolated `testing` database for PHPUnit (never touch mis_dev / production).
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

DB_CONNECTION="${DB_CONNECTION:-pgsql}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_USERNAME="${DB_USERNAME:-sail}"
DB_PASSWORD="${DB_PASSWORD:-password}"
DB_DATABASE="${DB_DATABASE:-testing}"
ARTISAN="${ARTISAN:-php artisan}"

export APP_ENV=testing
export DB_CONNECTION DB_HOST DB_PORT DB_USERNAME DB_PASSWORD DB_DATABASE

echo "==> Clearing config cache (prevents mis_dev baked into bootstrap/cache/config.php)"
$ARTISAN config:clear --no-interaction

if command -v psql >/dev/null 2>&1; then
  echo "==> Ensuring database '${DB_DATABASE}' exists"
  PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d postgres \
    -tc "SELECT 1 FROM pg_database WHERE datname='${DB_DATABASE}'" | grep -q 1 \
    || PGPASSWORD="${DB_PASSWORD}" psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d postgres \
      -c "CREATE DATABASE ${DB_DATABASE} OWNER ${DB_USERNAME};"
fi

echo "==> Running install migrations on '${DB_DATABASE}'"
$ARTISAN migrate --force --no-interaction

echo "==> Applying eHealth update migrations (database/migrations/update/0_1)"
# Prefer path migrate in CI: `artisan update` also touches caches/scopes and is interactive-friendly only.
$ARTISAN migrate --path=database/migrations/update/0_1 --force --no-interaction

echo "==> Testing DB ready: ${DB_DATABASE}"
