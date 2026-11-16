#!/usr/bin/env bash
# Usage: judge.sh <commit> <phar>
#
# The judge's PHAR, built at a commit of the gate (ADR-0140): a worktree of
# the commit, its dependencies installed from its own lock with no script of
# its own run, and `composer phar:judge`, which scopes every class of the
# gate's under a namespace of its own and compiles the archive, moved to
# <phar>. The commit is in the checkout's history: the gate's own, or one
# gate_action.py judge fetched into another project's.
set -euo pipefail
commit=$1
phar=$2
source="$(dirname "${phar}")/source"
rm -rf "${source}"
git worktree add --quiet --detach "${source}" "${commit}"
(
  cd "${source}"
  composer install --no-interaction --no-progress --no-scripts
  composer phar:judge
)
mv "${source}/build/mutation-gate-judge.phar" "${phar}"
git worktree remove --force "${source}"
