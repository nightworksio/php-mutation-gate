# ADR-0304: A pass the stop cut short is recorded

**Status:** Accepted
**Date:** 2026-11-20
**Amends:** [0196](0196-a-job-stops-at-its-target-less-what-it-measured.md): §3, the passes a job records

## Context

Each pass of a run's split streams is written beside the run's results the
moment it ends (ADR-0196 §3), so that a run stopped in its second pass
keeps its first. A pass the stop cut short was never written. On the gate's
own run of 89b3bdbf6:

- shard 35 recorded only a first pass of 13 s, though its log shows its
  second pass running from about 37 s to the stop;
- leftover job 1.2's recorded passes ended 22 s into a mutation step of
  446 s, leaving seven minutes that nothing recorded.

A job's time could not be set against what its passes took, and a learned
second pass's factor read only the passes that ended.

## Decision

Each pass is also listed as it starts, with the mutants it forks and its
bound. A pass that started and never ended is read as running from its
start to the end of the run's mutation step. A pass that ended is read as
before.

## Why no verdict moves

The passes are timings the result records; no mutant's status reads them.

## Alternatives considered

- **Write a pass's progress every few seconds:** the progress artifact
  (ADR-0157) already says how far a run got; the result needs only where
  its time went.

## Consequences

- A job stopped in its second pass records that pass with the seconds it
  ran, so the factor a second wave is priced by (ADR-0196 §4) reads the
  passes the stop cut short as well as those that finished.

## Related

- [ADR-0303](0303-a-job-runs-its-own-tail-where-its-wave-outnumbers-the-pool.md): a job runs its own tail where its wave outnumbers the pool.
