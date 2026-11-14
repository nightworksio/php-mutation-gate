#!/usr/bin/env bash
# Usage: covered.sh <dir> <pest argument>...
#
# Pest with these arguments under pcov, every PHP process its tests start
# recording its lines (scripts/process-coverage.php), its report and those
# lines collected into <dir>/collected.php for ci.yml's `coverage` job (G7).
set -euo pipefail

into=$1
shift

php scripts/process-coverage.php ini "${into}/processes"
PHP_INI_SCAN_DIR=":${into}/processes/ini" php -d pcov.enabled=1 -d pcov.directory=. -d 'pcov.exclude=~/(vendor|tests)/~' \
  vendor/bin/pest "$@" "--coverage-php=${into}/report.php"
php scripts/process-coverage.php collect "${into}/collected.php" "${into}/report.php" "${into}/processes/lines"
