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
   every file of the repository by content, but the gate's own workspace,
   the gate's full release, the runner's image (`ImageOS`, `ImageVersion`,
   `RUNNER_OS`, `RUNNER_ARCH` where set) and the variables `reuse.environment`
   names, by value. Every plan reads it and hands it to the verdict, and a
   passing verdict records it as `inputs` in its passed record, beside the
   run that judged it and whether that run carried no unit's result
   (`whole`). The ledger keeps the newest such passes, one a digest, in
   `reusable`, as many as `reuse.passes` says, ten by default.
2. **`reuse.identical` reuses a verdict whose inputs match.** `on`, the
   default, reuses on pull requests and on the default branch; `pulls` on
   pull requests alone, so the default branch always judges; `off` never.
   The plan looks before any map, so an early plan runs nothing too. Where
   a pass the run's own scope or the default branch keeps, or the pass of
   the pull request a push lands or a merge group was built for, holds the
   same digest, under the run's own check, and no ignore that applied then
   has expired, the plan runs nothing, the verdict stands and is recorded
   for the head, and every report's reach and the JSON report's `reused`
   name the run that judged it, its commit, where the pass was read and the
   digest of its inputs. A run of
   every unit reuses only a pass whose run carried no unit's result; a run
   sampled, taking no proof, or recording more than first killers reuses
   none.
3. **A record from a fork's run is never read unless `reuse.forks` is
   true.** A fork is a pull request whose head repository is not the base
   repository, or is gone. On a fork's pull request the run's own scope's
   passes are not read; the default branch's are. `true` loosens the gate
   (ADR-0142) and is named as a loose value.
4. **The default branch borrows the merged pull request's matching proofs
   (C1).** A same-repository pull request merged since the default branch's
   last pass, whose verdict passed at its head, lends the default branch
   the results its ledger holds, as `reuse.ownScopeProofs` takes them. A
   lent result proves a unit only where its key matches, after the default
   branch's own; it is never carried to a unit the change does not reach,
   and never written. Nothing in the merged range is marked proved, so the
   range is judged as ever.
5. **The older settings move into `reuse` with no alias.**
   `ci.mergedOwnScopeProofs` becomes `reuse.ownScopeProofs` (`reach` by
   default, `refuse`, `trust`); the proving of a merged range by a pull
   request's pass, the `true` and `delta` of `ci.trustMergedPullRequests`,
   becomes `reuse.delta` (`false` by default; `true` loosens). The gate has
   no release yet, so neither old key is read.
6. **The workflows read the merged pull requests' passes by default.** The
   action's and the reusable workflow's `merged-records` input is `true` by
   default, and the reusable workflow's `fetch` job reads those passes on a
   push to the default branch and on a merge group whatever the store.
   Without `actions: read` and `pull-requests: read`, no pass is read, the
   run warns so, and it judges as ever.

## Why no verdict moves

A verdict is a function of its inputs. Equal digests mean byte-identical
inputs, so the reused verdict is the one the run would reach; a run of
every unit takes only a verdict whose run judged or proved by key every
unit, as its own would. A proof lent under rule 4 is used only where its
key, which holds everything the unit's result reads (ADR-0007), matches;
it is never carried to a unit no change reaches, where a pull request
judged on an older base could stand for code the default branch moved
since, and everything else is judged.

## Alternatives considered

- **Reuse on an equal tree alone**: a new gate release, PHP build or runner
  image would carry a verdict it did not make.
- **Fork records on by default**: a fork's run writes what its own author
  chose; the repository's workflow did not write it.
- **Keep `ci.trustMergedPullRequests` beside `reuse`**: two settings would
  decide one thing.
- **Carry a merged pull request's results onto the default branch's ledger
  whatever the tree**: an unreached unit carries the newest result of its
  path whatever its key, so a pull request judged on an older base would
  out-date the default branch's own.

## Consequences

- A pull request pushed again with an identical tree, a re-land and a
  revert cost a plan, not a run.
- The default branch's push of a squash onto a moved main runs only the
  units whose keys the other commits moved.
- A push to the default branch and a merge group run the `fetch` job under
  `merged-records`, about a minute, and every plan reads one more digest
  over files it already fingerprints.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): what a proof's key holds.
- [ADR-0142](0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md): loose values.
