#!/usr/bin/env bash
# Usage: source bench/gate.sh, in a prepared project's directory.
#
# What every project's preparation shares: the gate from this checkout as a
# path repository, the files the gate's arms run with, a commit of it all,
# and a copy for the plain arm made before the gate patches its runner.
#   bench_baselines <tree>...   floors of 0, so a score never fails a gate arm
#   bench_commit <message>      git init and one commit, as a checkout is
#   bench_plain_copy            the copy the plain arm runs in
set -euo pipefail

# The version this checkout's lock holds of a package.
bench_locked() {
  jq -r --arg name "$1" '(.packages + ."packages-dev")[] | select(.name == $name) | .version' \
    "${GITHUB_WORKSPACE}/composer.lock"
}

bench_baselines() {
  local trees="" tree
  for tree in "$@"; do
    trees="${trees:+${trees}, }\"${tree}\": {\"floor\": 0}"
  done
  printf '{"format": 1, "trees": {%s}, "security": {".": {"floor": 0}}}\n' "${trees}" > bench-baseline.json
}

bench_commit() {
  git init --quiet
  git add --all
  git -c user.name=bench -c user.email=bench@localhost commit --quiet --message "$1"
}

bench_plain_copy() {
  cp -a "${PWD}" "${PWD}-plain"
}
