#!/usr/bin/env bash
# Usage: leg.sh <runner> <resolution>
#
# One leg of `runner contract` (ci.yml): the runner's fixture, as legs.json
# holds it, emptied of what an earlier leg left, installed at the resolution,
# and the Contract suite run on it. The leg's log and its suite's outcome, by
# the leg's name, go to evidence/raw, which `runner contracts` reads as its
# evidence. A leg whose install fails is a leg that failed.
set -euo pipefail

runner=$1
resolution=$2
leg="${runner}-${resolution}"
root=$PWD

mkdir -p evidence/raw

leave() {
  local code=$? outcome=failure
  if [ "${code}" -eq 0 ]; then
    outcome=success
  fi
  jq -n --arg leg "${leg}" --arg outcome "${outcome}" '{($leg): {outcome: $outcome}}' > "${root}/evidence/raw/${leg}.outcome.json"
}
trap leave EXIT

declare -A fixture
while IFS='=' read -r name value; do
  fixture["${name}"]=${value}
done < <(python3 .github/scripts/leg_support.py fixture "${runner}")

git clean -fdxq tests/Contract
git checkout -q -- tests/Contract

cd "${fixture[dir]}"
case "${resolution}/${fixture[lowest]}" in
  highest/*)
    composer install --no-interaction --no-progress
    ;;
  lowest/coverage)
    coverage=$(composer --working-dir="${root}" show --locked --format=json phpunit/php-code-coverage | jq -r '.versions[0]')
    composer update --prefer-lowest --prefer-stable --no-interaction --no-progress --with "phpunit/php-code-coverage:${coverage}"
    ;;
  lowest/own)
    composer update --prefer-lowest --prefer-stable --no-interaction --no-progress
    ;;
  lowest/analysers)
    composer update --prefer-lowest --prefer-stable --no-interaction --no-progress
    locked=$(composer show --locked --name-only)
    mapfile -t others < <(grep -v -x -E "${fixture[packages]}" <<< "${locked}")
    test "${#others[@]}" -gt 0
    composer update --no-interaction --no-progress "${others[@]}"
    ;;
  *)
    echo "::error::No way to install ${leg}: legs.json says lowest is ${fixture[lowest]}."
    exit 1
    ;;
esac
composer show | grep -E "^(${fixture[packages]}) "
# Mago's package downloads its binary the first time it runs, which the gate
# itself never does (ADR-0020).
if [ "${runner}" = analysers ]; then
  vendor/bin/mago --version
fi
cd "${root}"

suite=(--testsuite=Contract "--log-junit=evidence/raw/${leg}.xml")
if [ -n "${fixture[filter]}" ]; then
  suite+=("--filter=${fixture[filter]}")
fi
# Where COVERAGE_INTO names a directory, the leg runs under pcov with every
# process its tests start recording its lines (scripts/process-coverage.php),
# and leaves its report and those lines there for the coverage job (G7).
php=(php)
if [ -n "${COVERAGE_INTO:-}" ]; then
  php scripts/process-coverage.php ini "${COVERAGE_INTO}/processes"
  export PHP_INI_SCAN_DIR=":${COVERAGE_INTO}/processes/ini"
  php+=(-d pcov.enabled=1 -d pcov.directory=. -d 'pcov.exclude=~/(vendor|tests)/~')
  suite+=("--coverage-php=${COVERAGE_INTO}/report.php")
fi
RUNNER_CONTRACTS="${fixture[contracts]}" ANALYSER_CONTRACTS="${fixture[analysers]}" "${php[@]}" vendor/bin/pest "${suite[@]}"
