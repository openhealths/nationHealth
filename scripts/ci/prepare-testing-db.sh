#!/usr/bin/env bash
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/assert-not-production.sh"
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

exec php scripts/dev/prepare-testing-db.php
