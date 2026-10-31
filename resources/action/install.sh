#!/usr/bin/env bash
# The gate's own copy in the directory given, as the jobs that hold a
# credential run it: the packages deliver and fetch run on, each at the version
# the lock under resources/action/keyed holds, with none of their scripts or
# plugins run (ADR-0007). A copy with no lock installs nothing, since Composer
# would resolve every package afresh in a job that holds a credential.
set -euo pipefail
gate="$1"
manifest="resources/action/keyed/composer.json"
lock="${manifest%.json}.lock"
if [ ! -f "${gate}/${lock}" ]; then
  echo "::error::${gate} has no ${lock}, so the gate's own copy is not installed: Composer would resolve every package afresh in a job that holds a credential."
  exit 1
fi
COMPOSER="${manifest}" composer install --working-dir="${gate}" --no-dev --no-scripts --no-plugins --no-interaction --no-progress --prefer-dist
