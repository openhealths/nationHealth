#!/usr/bin/env bash
# Install PostgreSQL on the CI agent itself.
# Azure cloud agents in this project have no Docker, so a postgres service image is not available.
set -euo pipefail

DB_USERNAME="${DB_USERNAME:?DB_USERNAME must be provided}"
DB_PASSWORD="${DB_PASSWORD:?DB_PASSWORD must be provided}"
DB_DATABASE="${DB_DATABASE:-testing}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"

if [[ "${DB_DATABASE}" != "testing" ]]; then
  echo "Refusing to create database '${DB_DATABASE}'. This script only prepares 'testing'." >&2
  exit 1
fi

if ! pg_isready -h "${DB_HOST}" -p "${DB_PORT}" >/dev/null 2>&1; then
  # Hosted Ubuntu agents already provide a stopped cluster. Start it without
  # depending on apt mirrors when the PostgreSQL server is installed.
  if ! command -v pg_lsclusters >/dev/null 2>&1 || ! pg_lsclusters --no-header | grep -q '^[0-9]'; then
    echo "==> Installing PostgreSQL from apt"
    sudo apt-get update -y
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y postgresql postgresql-contrib
  fi
  sudo service postgresql start
fi

if ! pg_isready -h "${DB_HOST}" -p "${DB_PORT}" >/dev/null 2>&1; then
  echo "PostgreSQL did not become ready on ${DB_HOST}:${DB_PORT}." >&2
  exit 1
fi

echo "==> Ensuring role '${DB_USERNAME}' and database '${DB_DATABASE}'"
sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '${DB_USERNAME}') THEN
    CREATE ROLE ${DB_USERNAME} LOGIN PASSWORD '${DB_PASSWORD}' SUPERUSER;
  ELSE
    ALTER ROLE ${DB_USERNAME} WITH LOGIN PASSWORD '${DB_PASSWORD}' SUPERUSER;
  END IF;
END
\$\$;
SQL

if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_DATABASE}'" | grep -q 1; then
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c "CREATE DATABASE ${DB_DATABASE} OWNER ${DB_USERNAME};"
fi

echo "==> PostgreSQL ready on ${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
