#!/usr/bin/env bash
# Create credentials for this job's disposable local PostgreSQL instance only.
set -euo pipefail
set +x

source "$(dirname "${BASH_SOURCE[0]}")/assert-not-production.sh"

if [[ "${GITHUB_ACTIONS:-}" != "true" && "${TF_BUILD:-}" != "True" ]]; then
  echo "This initializer requires GitHub Actions or Azure Pipelines." >&2
  exit 1
fi

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=testing
DB_USERNAME=ci_test
DB_PASSWORD="$(openssl rand -hex 32)"
APP_KEY="base64:$(openssl rand -base64 32)"

if [[ "${GITHUB_ACTIONS:-}" == "true" ]]; then
  : "${GITHUB_ENV:?GitHub environment file is required}"
  printf '::add-mask::%s\n' "$DB_PASSWORD" "$APP_KEY"
  for variable in DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD APP_KEY; do
    printf '%s=%s\n' "$variable" "${!variable}" >> "$GITHUB_ENV"
  done
else
  for variable in DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME; do
    printf '##vso[task.setvariable variable=%s]%s\n' "$variable" "${!variable}"
  done
  for variable in DB_PASSWORD APP_KEY; do
    printf '##vso[task.setvariable variable=%s;issecret=true]%s\n' "$variable" "${!variable}"
  done
fi
