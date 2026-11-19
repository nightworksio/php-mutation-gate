# ADR-0220: The plan times every step

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): the plan's time is recorded as a shard's is

## Context

A shard records where its time went, step by step (ADR-0016), but the plan
recorded nothing of its own. On the gate's own probe the plan's command
took 5m16s: the analyser's warm-up said it took 90 s and the start-up said
5 s, and nothing said where the other three and a half minutes went. The
candidates were reading and keying the 121 MB map, the held units'
coverage, and writing every shard's map, and each asks for a different
change to the pipeline: a job of its own beside the map, a part of the plan
started at the push, or faster writing.

## Decision

1. **The plan times every step it takes, one after another**, on the
   flow's clock: each step runs from where the one before it ended to where
   it ends, so every second of the plan, from the command's start to the
   comment in its planned state, is one step's. A step is named by what it
   does: `composing`, `inventory`, `ledgers`, `unchanged`, `early start`,
   `suites`, `name graph`, `entries`, `coverage`, `map`, `cap`, `keying`,
   `reach`, `unit digests`, `considering`, `unit keys`, `packs`, `proving`,
   `new code lines`, `sampling`, `pruning`, `first run`, `cutting`, `briefing`,
   `held coverage`, `naming`, `warm-up begun`, `whole map begun`,
   `opening`, `handoff`, `warm-up`, `start-up timing` and `publishing`. Work the
   plan begins in a child of its own, the analyser's warm-up and the whole
   map's writing, is timed where it begins and where the plan waits for
   it.
2. **The plan says how long it took and each step that took a tenth of a
   second or more** on standard error, after everything else it says, and
   **records every step** in `.mutation-gate/plan-steps.json`: `seconds`,
   the whole plan, and `steps`, each its `step`, the seconds after the plan
   began that it started, `since`, and the `seconds` it took. The reusable
   workflow hands the record on in the plan's artifact.
3. **`timing.planSteps`, `true` by default,** turns both off where it is
   `false`. Timing a step reads the clock twice; it changes no output of the
   run's, and keys nothing.

## Why no verdict moves

Each step runs as it ran, in the same order; the plan reads the clock
between them and writes one more file beside the plan, which no shard and
no verdict reads.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Time only the steps thought to be slow | The probe's unexplained three and a half minutes were in steps no one timed; a step left out is where the next surprise hides. |
| Time each step on its own, from its start to its end | The time between two timed steps belongs to neither, and the sum stops being the plan's time. |
| Record the steps in the plan itself | The plan is what every shard reads and a CI prints; its time is not part of what it plans. |
| Add the plan's cases to the shard's steps (`Step`) | A shard never takes them, and a shard's result would admit steps it cannot hold. |

## Consequences

- Every plan's log ends with its steps, and the plan's artifact holds one
  more small file.
- The pipeline's next changes are chosen by the steps' times: which part of
  the plan moves beside the map, starts at the push, or is made faster.

## Related

- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md)
- [ADR-0164](0164-the-analyser-warms-up-once-as-the-plan-is-made.md)
- [ADR-0169](0169-the-whole-map-is-written-beside-the-readying.md)
- [ADR-0180](0180-the-plan-warms-the-analyser-up-before-it-cuts-its-shards.md)
