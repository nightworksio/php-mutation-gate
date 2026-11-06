# ADR-0086: A pull request can sample by itself above a threshold

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): items 22 and 23, for a run that samples by itself

## Context

A sampled run is asked for by name, with `--sample` or `mode: sampled`. A
pull request that reaches a lot beyond its own lines, such as a change to a
file in `reach.everything`, runs every unit it reaches, whatever the cost. A
project that would take an estimate for such a run must ask for one on every
run, and then even a small change is judged by sample.

Asking `--sample` on a change-scoped run also gains little: the reached
units are known exactly (item 23), so only the units no run reaches are
sampled, and those mostly carry already.

## Decision

1. **`sampling.auto` sets when a run samples by itself.** It is `off`, a
   count of mutants of at least 1, or a duration of runner time, such as
   `2h`. A run samples by itself where the units it runs that hold no line of
   its new code, outside a tree held to 100, are expected to hold more mutants
   than the count, or to take more runner time than the duration. A tree held
   to 100 runs whole however the run samples (item 26 of ADR-0020), so its
   units are not counted: a run that could sample none of what it counts
   would say it samples and then sample nothing. The mutants are each unit's estimate
   (item 23 of ADR-0020); the runner time is each unit's estimated cost
   (ADR-0006). It is `off` by default.
2. **`sampling.autoRuns` lists the kinds of run that may.** It holds
   `pullRequests`, `mergeGroups` and `branches`, a push to a branch that is
   not the default, and it is `["pullRequests"]` by default. A run on the
   default branch, on a tag or on a detached head never samples by itself, nor
   does a run with `--unproven`, nor one that asks for a sample already: the
   default branch writes the ledger every other scope trusts, and those runs
   are the full results a sampled pass leans on.
3. **A run that samples by itself samples what it reaches too.** It samples
   every unit it runs but those holding a line of its new code and those of a
   tree held to 100, which stay exact (item 26 of ADR-0020). New code is the
   lines the run knows changed: a pull request's or merge group's since its
   base, and a change-scoped run's since the commit it reads from. A full run
   on a branch knows no change, so every unit it runs may be sampled.
   `--sample` alone keeps item 23: a change-scoped run's reached units stay
   exact.
4. **The plan says why.** The plan's reasons gain *Beyond its new code the
   change reaches about 1840 mutants, taking about 2h 10m of runner time, over
   sampling.auto's 1h of runner time, so the plan samples it*, and the
   comment's heading says *sampled*, as for any sampled run (item 30).
5. **Both keys judge or report only.** They decide which units run, as the
   other sampling keys do, and never what a unit's result is; each sampled
   unit's proof is its full result.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **On by default** | A sampled pass can merge a survivor the sample left out. Sampling trades a proof's soundness for time, so a project opts in. |
| **Keep reached units exact when sampling by itself** | Under ADR-0005 a change-scoped run's units are mostly the ones it reaches, so the run would sample next to nothing and the threshold would save no time. |
| **A threshold of units, not mutants or time** | Units differ in size by two orders of magnitude; mutants and runner time are what a run spends. |
| **Merge groups and branches on by default** | A merge group's pass merges at once, and a branch push has no pull request to show its estimate; each is opted into by name. |

## Consequences

- A pull request reaching more than the threshold runs a sample sized by
  `sampling.margin` and `sampling.minUnits`, and its plan says so.
- A merge can land on a sampled pass, so a survivor in an unsampled unit is
  caught only by the next run that runs it in full: the default branch's run
  after the merge, or a scheduled or `--unproven` run.
- A sampled pass is never `last-passed` and raises no floor (item 29 of
  ADR-0020), so the default branch's next run judges the change whole.
- A change kept under the threshold runs as before.

## Related

- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): sampled runs
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): what a change reaches
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): estimated cost
- [ADR-0084](0084-the-gate-is-keyed-by-the-code-a-verdict-depends-on.md): `--unproven`
