#!/usr/bin/env bash
# Usage: covered.sh <dir> <pest argument>...
#
# Pest with these arguments under pcov, every PHP process its tests start
# recording its lines (scripts/process-coverage.php) as the test's own in its
# report <dir>/report.php, which ci.yml's `coverage` job joins into the gate's
# map, and the report and those lines collected into <dir>/collected.php for
# that job's floor (G7).
set -euo pipefail

into=$1
shift

php scripts/process-coverage.php ini "${into}/processes"
PHP_INI_SCAN_DIR=":${into}/processes/ini" php -d pcov.enabled=1 -d pcov.directory=. -d 'pcov.exclude=~/(vendor|tests)/~' \
  vendor/bin/pest "$@" "--coverage-php=${into}/report.php"
php scripts/process-coverage.php tested "${into}/report.php" "${into}/processes/lines"
php scripts/process-coverage.php collect "${into}/collected.php" "${into}/report.php" "${into}/processes/lines"
