#!/usr/bin/env bash
# Install PostgreSQL on the CI agent itself.
# Azure cloud agents in this project have no Docker, so a postgres service image is not available.
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/assert-not-production.sh"

if [[ "${GITHUB_ACTIONS:-}" != "true" && "${TF_BUILD:-}" != "True" ]]; then
  echo "PostgreSQL installation requires a CI job." >&2
  exit 1
fi

DB_USERNAME="${DB_USERNAME:?DB_USERNAME must be provided}"
DB_PASSWORD="${DB_PASSWORD:?DB_PASSWORD must be provided}"
DB_DATABASE="${DB_DATABASE:-testing}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"

if [[ "$DB_USERNAME" != "ci_test" || "$DB_DATABASE" != "testing" || "$DB_HOST" != "127.0.0.1" || "$DB_PORT" != "5432" ]]; then
  echo "Expected the CI role ci_test and database testing on the local CI PostgreSQL server." >&2
  exit 1
fi
# The job initializer generates 32 random bytes as hex. Only that format enters the creation statement.
if [[ ! "$DB_PASSWORD" =~ ^[[:xdigit:]]{64}$ ]]; then
  echo "Expected the password from the CI environment initializer." >&2
  exit 1
fi

if ! pg_isready -h "${DB_HOST}" -p "${DB_PORT}" >/dev/null 2>&1; then
  echo "==> Installing PostgreSQL from apt"
  sudo apt-get update -y
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y postgresql postgresql-contrib
  sudo service postgresql start
fi

admin=(sudo -u postgres psql -X -p "$DB_PORT" -d postgres -v ON_ERROR_STOP=1)
owner="$("${admin[@]}" -Atc "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname='testing'")"
if [[ -n "$owner" && "$owner" != "ci_test" ]]; then
  echo "The existing testing database belongs to a different role." >&2
  exit 1
fi
role="$("${admin[@]}" -Atc "SELECT rolname FROM pg_roles WHERE rolname='ci_test'")"
if [[ -z "$role" ]]; then
  "${admin[@]}" <<SQL
CREATE ROLE ci_test LOGIN PASSWORD '${DB_PASSWORD}';
SQL
fi
# On retry, authenticate with the existing role without changing its password or permissions.
PGPASSWORD="$DB_PASSWORD" psql -X -w -h "$DB_HOST" -p "$DB_PORT" -U ci_test -d postgres -c 'SELECT 1' >/dev/null
if [[ -z "$owner" ]]; then
  "${admin[@]}" -c "CREATE DATABASE testing OWNER ci_test"
fi

echo "==> PostgreSQL ready on ${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
