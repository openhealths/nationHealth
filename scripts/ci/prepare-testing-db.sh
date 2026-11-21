#!/usr/bin/env bash
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/assert-not-production.sh"
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

if [[ "${DB_CONNECTION:-pgsql}" != "pgsql" || "${DB_DATABASE:-testing}" != "testing" ]]; then
  echo "This script only prepares the PostgreSQL database testing." >&2
  exit 1
fi
exec php scripts/dev/prepare-testing-db.php
