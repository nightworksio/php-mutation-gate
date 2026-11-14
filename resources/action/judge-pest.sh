#!/usr/bin/env bash
# Usage: judge-pest.sh <gate> <patch>
#
# Pest, handed to the judge that runs (ADR-0140). The project's install ran
# its own Composer scripts, which may have patched pest-plugin-mutate for the
# project's own copy of the gate, whose classes the judge never loads, so the
# release Composer installs is put back, with no script run, and patched by
# the judge where <patch> is true, as pest.patch says.
set -euo pipefail
gate=$1
patch=$2
composer reinstall pestphp/pest-plugin-mutate --no-interaction --no-progress --no-scripts
if [ "${patch}" = "true" ]; then
  "${gate}" pest:patch
fi
