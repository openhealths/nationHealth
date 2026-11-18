#!/usr/bin/env bash

if [[ "${APP_ENV:-}" == "production" ]]; then
  echo "Testing environment scripts cannot run in production." >&2
  exit 1
fi

TESTING_ENV_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if command -v php >/dev/null 2>&1; then
  php "$TESTING_ENV_ROOT/scripts/ci/assert-not-production.php" || exit "$?"
elif [[ "${APP_ENV:-}" != "testing" || ( "${GITHUB_ACTIONS:-}" != "true" && "${TF_BUILD:-}" != "True" ) \
    || -e "$TESTING_ENV_ROOT/.env" || -L "$TESTING_ENV_ROOT/.env" || -f "$TESTING_ENV_ROOT/bootstrap/cache/config.php" \
    || -n "${APP_CONFIG_CACHE:-}" ]]; then
  echo "PHP is required to verify APP_ENV before running testing environment scripts." >&2
  exit 1
fi
unset TESTING_ENV_ROOT
