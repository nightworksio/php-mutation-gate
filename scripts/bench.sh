#!/usr/bin/env bash
# Usage: composer bench -- <project> [cold|scenarios|sharded]
#
# The benchmark's harness, run here as bench.yml runs it (ADR-0017): the
# project bench/<project>.json pins, fetched and prepared in a directory of
# its own under BENCH_DIR, a new temporary directory where it is unset; then
# its cold arms, interleaved, and their reconciliation (`cold`, the
# default), its scenarios (`scenarios`), or its sharded arm with each shard
# run here in turn (`sharded`), each BENCH_ROUNDS times, 3 where it is
# unset. The result is written to results/ in that directory, as the
# workflow's job writes it. A project whose suite needs a service, as
# pinkary.com's needs MySQL, needs it running here.
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
usage="Usage: composer bench -- <project> [cold|scenarios|sharded], the project one of: $(
  find "${root}/bench" -maxdepth 1 -name '*.json' -exec basename {} .json \; | sort | paste -sd ' ' -)"
project=${1:-}
stage=${2:-cold}
if [ ! -f "${root}/bench/${project}.json" ] || [[ ! "${stage}" =~ ^(cold|scenarios|sharded)$ ]]; then
  echo "${usage}" >&2
  exit 2
fi
export GITHUB_WORKSPACE=${root}
work=${BENCH_DIR:-$(mktemp -d)}
rounds=${BENCH_ROUNDS:-3}
file="${root}/bench/${project}.json"
directory="${work}/${project}"
out="${work}/out-${stage}"
scripts="${root}/.github/scripts"
"${root}/bench/prepare.sh" "${project}" "${directory}"
case "${stage}" in
  cold)
    python3 "${scripts}/bench.py" run "${file}" "${directory}" "${out}" --rounds "${rounds}"
    python3 "${scripts}/bench.py" reconcile "${file}" "${directory}" "${out}" "${work}/results/${project}.json"
    ;;
  scenarios)
    python3 "${scripts}/bench_scenarios.py" run "${file}" "${directory}" "${out}" --rounds "${rounds}"
    python3 "${scripts}/bench_scenarios.py" report "${file}" "${out}" "${work}/results/${project}-scenarios.json"
    ;;
  sharded)
    python3 "${scripts}/bench_sharded.py" plan "${file}" "${directory}" "${out}"
    for shard in $(jq -r '.[]' "${out}/shards.json"); do
      python3 "${scripts}/bench_sharded.py" shard "${file}" "${directory}" "${out}" "${shard}" \
        --planned "${out}/planned" --rounds "${rounds}"
    done
    python3 "${scripts}/bench_sharded.py" verdict "${file}" "${directory}" "${out}" --planned "${out}/planned" \
      --rounds "${rounds}"
    python3 "${scripts}/bench_sharded.py" report "${file}" "${out}" "${work}/results/${project}-sharded.json" \
      --rounds "${rounds}"
    ;;
esac
echo "The project and every arm's reports are in ${work}."
