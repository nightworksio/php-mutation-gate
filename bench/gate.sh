#!/usr/bin/env bash
# Usage: source bench/gate.sh, in a prepared project's directory.
#
# What every project's preparation shares: the gate from this checkout as a
# path repository, copied into vendor/ as an install from Packagist is, so no
# tool that tells a project's code from its packages by where a file lies,
# such as Pest's arch presets, reads the gate as the project's; the files
# the gate's arms run with; a commit of it all, with git's automatic
# housekeeping off, so no background gc repacks .git while a copy or a run
# reads it; and a copy for the plain arm made before the gate patches its
# runner.
#   bench_versions <package>... each package's installed version, read from
#                               Composer's JSON, which keeps every column at
#                               any width, where its table drops all but the
#                               name once a line is wider than the terminal
#   bench_baselines <tree>...   floors of 0, so a score never fails a gate arm
#   bench_resolve <command>...  the Composer command that resolves the
#                               project's packages; where BENCH_LOCK names a
#                               lock, composer.json changed as the command
#                               would change it and the packages installed
#                               from that lock, so a job prepares the packages
#                               another job resolved, as a sharded plan's
#                               shards and verdict need
#   bench_commit <message>      git init and one commit, as a checkout is, the
#                               same commit on every machine that prepares the
#                               same files, as a sharded plan's shards need
#   bench_plain_copy            the copy the plain arm runs in
set -euo pipefail

# The version this checkout's lock holds of a package.
bench_locked() {
  jq -r --arg name "$1" '(.packages + ."packages-dev")[] | select(.name == $name) | .version' \
    "${GITHUB_WORKSPACE}/composer.lock"
}

bench_versions() {
  composer show --format=json | jq -r --args \
    '.installed[] | select(.name as $name | $ARGS.positional | index($name)) | "\(.name) \(.version)"' "$@"
}

bench_baselines() {
  local trees="" tree
  for tree in "$@"; do
    trees="${trees:+${trees}, }\"${tree}\": {\"floor\": 0}"
  done
  printf '{"format": 1, "trees": {%s}, "security": {".": {"floor": 0}}}\n' "${trees}" > bench-baseline.json
}

bench_resolve() {
  if [[ -z "${BENCH_LOCK:-}" ]]; then
    composer "$@"
    return
  fi
  if [[ "$1" == require ]]; then
    composer "$@" --no-update
  fi
  local install=(install --no-interaction --no-progress)
  if [[ " $* " == *" --no-scripts "* ]]; then
    install+=(--no-scripts)
  fi
  cp "${BENCH_LOCK}" composer.lock
  composer "${install[@]}"
}

bench_commit() {
  git init --quiet
  git config gc.auto 0
  git add --all
  GIT_AUTHOR_DATE=2000-01-01T00:00:00Z GIT_COMMITTER_DATE=2000-01-01T00:00:00Z \
    git -c user.name=bench -c user.email=bench@localhost commit --quiet --message "$1"
}

bench_plain_copy() {
  cp -a "${PWD}" "${PWD}-plain"
}
