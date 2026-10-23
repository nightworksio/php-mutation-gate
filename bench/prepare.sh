#!/usr/bin/env bash
# Usage: bench/prepare.sh <project> <directory>
#
# The project bench/<project>.json pins, fetched into the directory and
# prepared there by bench/<project>.sh for its arms, with the gate from the
# checkout GITHUB_WORKSPACE names.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
"${here}/fetch.sh" "${here}/$1.json" "$2"
cd "$2"
"${here}/$1.sh"
