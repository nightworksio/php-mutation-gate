#!/usr/bin/env bash
# Usage: mutants.sh shards | mutants.sh run <shard> <shards> | mutants.sh ext
#
# Outbreak's mutants (ADR-0118), as .cargo/mutants.toml configures them.
# `shards` says how many shards of at most PER_SHARD mutants hold those of
# every crate but outbreak-ext, at least one, and fails unless those shards
# deal out each of them exactly once; `run` tests one shard's, dealt
# round-robin. `ext` tests outbreak-ext's, the one crate whose build needs
# PHP's headers and libclang. Each runs in place, since tests read fixtures
# outside the workspace. A pull request's run takes the mutants of its diff
# against its base (EVENT, BASE); any other run takes every one.
set -euo pipefail

PER_SHARD=170
EXT='crates/outbreak-ext/**'

scope=()
if [[ "${EVENT:-}" == pull_request ]]; then
  git diff "$(git merge-base "${BASE:?}" HEAD)" HEAD -- . > "${RUNNER_TEMP:?}/change.diff"
  scope=(--in-diff "${RUNNER_TEMP}/change.diff")
fi

case "${1:-}" in
  shards)
    every="$(cargo mutants --workspace --list --exclude "${EXT}" ${scope[@]+"${scope[@]}"} | sort)"
    count="$(grep -c . <<<"${every}" || true)"
    shards=$(((count + PER_SHARD - 1) / PER_SHARD))
    shards=$((shards > 0 ? shards : 1))
    dealt="$(for ((shard = 0; shard < shards; shard++)); do
      cargo mutants --workspace --list --exclude "${EXT}" --sharding round-robin \
        --shard "${shard}/${shards}" ${scope[@]+"${scope[@]}"}
    done | sort)"
    if [[ "${dealt}" != "${every}" ]]; then
      echo "the ${shards} shards do not deal out each of the ${count} mutants once" >&2
      exit 1
    fi
    echo "${shards}"
    ;;
  run)
    cargo mutants --workspace --in-place --no-shuffle --exclude "${EXT}" --sharding round-robin \
      --shard "${2:?}/${3:?}" ${scope[@]+"${scope[@]}"}
    ;;
  ext)
    cargo mutants --workspace --in-place --no-shuffle --file "${EXT}" ${scope[@]+"${scope[@]}"}
    ;;
  *)
    echo "usage: mutants.sh shards | mutants.sh run <shard> <shards> | mutants.sh ext" >&2
    exit 2
    ;;
esac
