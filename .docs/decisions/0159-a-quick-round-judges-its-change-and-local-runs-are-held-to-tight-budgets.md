# ADR-0159: A quick round judges its change, and local runs are held to tight budgets

**Status:** Accepted
**Date:** 2026-11-16
**Amends:** [0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): the budgets of `watch` and `pre-push`
**Amends:** [0078](0078-a-daemon-keeps-the-gate-loaded-between-local-runs.md): the config file the daemon `watch` starts reads
**Amends:** [0146](0146-a-watch-round-is-quick-advisory-and-keyed-apart.md): what a quick round takes after its saved units

## Context

On the pinned lcobucci/jwt, the `local loop` job's round after one changed
line took 46 to 53 s. That round judged five mutants of the changed file in
its first eight seconds. It then judged every other unit of the project,
because an empty ledger leaves every unit with no proof to carry, and a unit
with none is treated as reached (ADR-0005). It stopped only at
`local.watchBudget`.

Two more costs came with each round:

- The daemon `watch` starts ran without the client's `--config`. It read the
  default config, so the first request named a config whose digest differed
  from the one it started with. The daemon then stopped, and every
  `--config` project ran without it.
- The plan timed three runs of no test, one after another, for its cost
  model. A quick round's one shard is never cut by an estimate.

## Decision

1. **`local.changeOnly`, `true` by default, holds a quick round to its
   change.** It considers only the units its change reaches. A unit with
   no proof to carry and no line in the change waits for `pre-push`, `run`
   or CI. Off, the round then takes those units, as far as
   `local.watchBudget` lets it, as before. `pre-push`, `run` and CI are
   never quick, so each still treats a unit with no proof as reached.
2. **The daemon reads the config file its client names.** `watch` starts
   `serve --detached --config=<file>` where it was given `--config`.
3. **A quick round times no run of no test.** Its first runs are guessed,
   which decides only where a unit runs within the one shard.
   `local.quick: false` gives rounds that time them, as ADR-0146 decision 3
   gives every other setting a quick round lays.
4. **The local budgets are tight.** `local.watchBudget` is `30s` (it was
   `1m`), and `local.prePushBudget` is `2m` (it was `5m`). A budget stops a
   run before its next batch and leaves the rest unjudged, never passed.
5. **CI holds the local loop to what it measures.** `local-mode.yml` runs
   no PMG self-run.
   - `local mode` fails a whole run on jwt above `RUN_SECONDS` (100; 84 s
     measured on CI).
   - `local loop` checks first that the settings in force are the shipped
     ones (`watchBudget` 30s, `prePushBudget` 2m, `quick` and `changeOnly`
     on), then fails a quick round above `LOOP_SECONDS` (10; 4 s measured
     on CI).
   - Each job first times jwt's own suite under plain PHPUnit with pcov on
     its runner with `mutation-gate machine`, once to warm up and
     then the median of five, and scales its budget by the ratio to
     `RUNNER_REFERENCE_SECONDS` (0.42 s, the fast hosted CPUs' median):
     never below 1, and at most `RUNNER_MOST_SCALE` (2.5), past which the
     job fails as on too slow a runner. The log and the step summary print
     the processor, the measured time, the reference, the scale and every
     budget before and after.
   - The signal runs none of the gate's code, so a regression in the gate
     cannot raise its own budget: the suite runs as a child process, and
     each timed interval runs from the child's start to its exit and holds
     nothing of the gate's own work, which a test of `Machines` states. It
     is the work the gate's coverage phase does, and it separates the
     hosted CPUs as the coverage phase does: the EPYC 7763 takes 1.8 times
     as long as the EPYC 9V45, where a synthetic PHP workload saw 1.5
     times.
   - The reference is re-measured when jwt's pin, PHP or the hosted fleet
     changes, from the times and processors the step prints.
   - A whole run is also stopped as stuck where one of its processes with
     no child of its own uses no CPU for `NO_PROGRESS_SECONDS` (30 s), a
     bound no runner scales: a slower runner takes longer to do the work,
     never longer to do nothing, so a scaled budget cannot let a stuck
     process sit. A red then says which process made no progress for how
     long; a run past its scaled budget says the processor, the scale and
     the budget against its base.

## Why no verdict moves

- A quick round is advisory, and its proofs are keyed apart (ADR-0146), so
  what it leaves out no verdict ever takes.
- The daemon's config is the client's own: it was refused before, not
  wrong.
- A first-run estimate decides where a unit runs, never what it is judged.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep taking the unproven units after the change, within the budget | On a fresh clone every round spends its whole budget on code the save did not touch. Kept as `local.changeOnly: false`. |
| Cache the start-up timing across rounds | It still costs the first round on every machine, and nothing in a quick round needs it. |
| Budgets of `1m` and `5m` | A round that takes its full minute after one saved line is not the loop this ADR asks for. |

## Consequences

- On jwt, a round after one changed line takes 4 s on CI, against 48 to
  53 s before; on a laptop, 7.8 s with a cold daemon and 5.7 s with a warm
  one.
- A unit no run has judged shows no result in `watch` until `pre-push`,
  `run` or CI judges it.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md)
- [ADR-0120](0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md)
- [ADR-0146](0146-a-watch-round-is-quick-advisory-and-keyed-apart.md)
