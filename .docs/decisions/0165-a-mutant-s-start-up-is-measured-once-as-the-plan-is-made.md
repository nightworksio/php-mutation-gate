# ADR-0165: A mutant's start-up is measured once, as the plan is made

**Status:** Accepted
**Date:** 2026-11-16
**Amends:** [0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): where a mutant's start-up is measured; [0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): the start-up each limit is laid on

## Context

Every mutant's limit is laid on how long a mutant's own run takes to start:
the fastest of a few runs of no test, each serving a file unchanged. Each
shard measured it before its first mutant, on the machine its mutants run
on; a local item spent 30 s on it, and every item of a run measured the same
runs of the same runner.

A run's shards run on runners of one kind, as the plan does in the reusable
workflow. Where they do not, the runner's platform tells them apart: the
PHP build, its extensions and the machine's architecture.

## Decision

1. **`timeouts.startUpAtPlan` `true`, the default, measures the start-up
   once, as the plan is made,** as a shard measured it, on the first file
   of the plan's first shard. The plan leaves it, with the runner and its
   platform, in `.mutation-gate/start-up.json`, which the reusable workflow
   hands every shard with the plan.
2. **A shard whose runner and platform are the plan's lays every limit on
   the plan's start-up,** and runs no run of no test. A shard on another
   platform, or handed none, measures it as before.
3. **A plan that measures none removes the start-up an earlier plan left,**
   as does one whose run of no test cannot run.
4. **`timeouts.startUpAtPlan: false` has each shard measure it,** as before
   this decision. It changes no output, and keys nothing.

## Why no verdict moves

A limit is laid on the same measure of the same runner on the same
platform, measured once. Where a shard's machine is slower than the plan's,
a mutant's run may outlast a limit laid on the plan's start-up. A timeout's
control runs the same tests unmutated under the same limit (ADR-0008): a
limit the start-up alone outlasts is outlasted by the control too, and the
mutant is not counted as killed.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep measuring in every shard (`timeouts.startUpAtPlan: false`) | Every item pays the same runs of no test before its first mutant; 30 s of a local item. Kept as the setting. |
| Take the start-up the plan's cost model measures | It is measured only for units no ledger times yet, so a plan of timed units measures none. |
| Take it on any platform | A start-up measured on another architecture or PHP build says little of this one's. |

## Consequences

- The plan job times a few runs of no test, and its time grows by them;
  every shard's shrinks by them.

## Related

- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md)
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md)
- [ADR-0164](0164-the-analyser-warms-up-once-as-the-plan-is-made.md)
