# ADR-0198: A run whose every input is identical reuses the verdict

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0139](0139-a-merged-pull-request-s-pass-reaches-its-push-as-an-artifact-of-its-run.md): `ci.trustMergedPullRequests` and `ci.mergedOwnScopeProofs` fold into `reuse`
**Amends:** [0005](0005-what-a-change-reaches-is-what-is-mutated.md): rule 5, what a push takes from a merged pull request
**Amends:** [0135](0135-a-full-split-stream-run-starts-without-waiting-for-a-fresh-map.md): the early plan decides reuse before any map

## Context

A run judges again what an earlier run of the same repository already
judged with byte-identical inputs: a pull request pushed twice with the
same tree, a re-land, a revert, and the default branch's push of a pull
request merged with the tree it was judged at. ADR-0139 lets the default
branch take a merged pull request's pass, but only where a project sets
`ci.trustMergedPullRequests`, which is off by default, and only for an equal
tree. It does not compare the gate's release, the runner's image or the
environment, and it reads a record from a fork's run as readily as one
from the repository's own.

When the landed tree differs from the pull request's head, which ADR-0139
measured for about 60% of the gate's own pushes, the default branch judges
the merged range again, though most of its units' proofs were written by
the pull request's run under keys that still match.

## Decision

1. **The run's inputs have one digest.** It covers what every proof's key
   reads first (the gate's verdict code, the effective config, the runner's
   identity with its PHP build, the analyser and the installed packages),
   every file of the repository by content, the gate's full release, the
   runner's image (`ImageOS`, `ImageVersion`, `RUNNER_OS`, `RUNNER_ARCH`
   where set) and the variables `reuse.environment` names, by value. The
   passed record and the `mutation-gate-passed` artifact carry it as
   `inputs`, and the ledger keeps the newest passed records by it.
2. **`reuse.identical` reuses a verdict whose inputs match.** `on`, the
   default, reuses on pull requests and on the default branch; `pulls` on
   pull requests alone, so the default branch always judges; `off` never.
   The early plan computes the digest before any map. Where a passed record
   the repository's own workflow wrote holds the same digest, the plan runs
   nothing, the verdict stands and is recorded for the head, and the step
   summary, the JSON report (`reused`) and `doctor` name the run, its
   commit and its source: a pull request or the default branch.
3. **A record from a fork's run is never read unless `reuse.forks` is
   true.** A fork is a pull request whose head repository is not the base
   repository. `true` loosens the gate (ADR-0142) and is named as a loose
   value.
4. **The default branch takes the merged pull request's matching proofs
   (C1).** Where the landed tree differs from the head a same-repository
   pull request passed at, the default branch takes the per-unit proofs
   that run wrote and judges only what their keys no longer match. Nothing
   in the merged range is marked proved for it.
5. **The older settings move into `reuse` with no alias.**
   `ci.mergedOwnScopeProofs` becomes `reuse.ownScopeProofs` (`reach` by
   default, `refuse`, `trust`); the `delta` of `ci.trustMergedPullRequests`
   becomes `reuse.delta` (`false` by default; `true` loosens). The gate has
   no release yet, so neither old key is read.

## Why no verdict moves

A verdict is a function of its inputs. Equal digests mean byte-identical
inputs, so the reused verdict is the one the run would reach. A proof taken
under rule 4 is used only where its key, which holds everything the unit's
result reads (ADR-0007), matches; everything else is judged.

## Alternatives considered

- **Reuse on an equal tree alone**: a new gate release, PHP build or runner
  image would carry a verdict it did not make.
- **Fork records on by default**: a fork's run writes what its own author
  chose; the repository's workflow did not write it.
- **Keep `ci.trustMergedPullRequests` beside `reuse`**: two settings would
  decide one thing.

## Consequences

- A pull request pushed again with an identical tree, a re-land and a
  revert cost a plan, not a run.
- The default branch's push of a squash onto a moved main judges only the
  units the other commits moved.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): what a proof's key holds.
- [ADR-0142](0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md): loose values.
