# ADR-0302: A plan expects a streamed shard at its wall time

**Status:** Accepted
**Date:** 2026-11-20
**Amends:** [0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): what the plan expects a shard's job to take
**Amends:** [0195](0195-a-streamed-job-is-priced-at-its-wall-time.md): where a streamed shard's wall time is read

## Context

A run that cuts its test files into parts cuts its shards at their wall
time: a shard's cost, spread over its job's processes (ADR-0195). What the
plan then expects of each job, the wall time of the longest and the runner
time of all together, still took a shard's cost as its wall seconds. On the
gate's own run of 89b3bdbf6 the plan cut 75 shards of about 1,580 cost
seconds each to fit 460 s on four processes, and said it expected 34h 12m
of runner time, four times what it had cut for; the cost a run reports
against its plan, and the warnings that a shard is expected past its stop,
read the same figures.

## Decision

`PlanEstimates` takes the wall a job's streams judge at, as the cut does: a
shard that judges one part of the test files is expected at its cost over
that wall, a whole shard at its cost, each with its opening run and setup.
The plan, the full-run estimate and the verdict's cost account pass the
wall of the processes the gate runs with, the same one the plan cut by.

## Why no verdict moves

Only what the plan prints and the run's cost account set against what it
spent change; no shard, share or mutant does.

## Alternatives considered

- **Store the wall in the plan**: the plan and the verdict already read the
  processes the same way; a field would be a second home for them.

## Consequences

- The run of 89b3bdbf6 would have printed about 9.5 hours of runner time,
  setup included, in place of 34h 12m.
- "The longest is expected past its stop" warns only where the cut's own
  price says so.

## Related

- [ADR-0196](0196-a-job-stops-at-its-target-less-what-it-measured.md): a job stops at its target less what it measured.
