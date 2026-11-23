# ADR-0332: A first-wave job confirms doubtful kills within a share of its time

**Status:** Accepted
**Date:** 2026-11-23

## Context

A narrowed kill whose test files fail alone on the unmutated code counts
only once it is run again with every test file (ADR-0142). That run again
costs a whole suite for each kill, and it is bounded by the time its job
has left (ADR-0300).

A shard mutates its units one group after another. A group judged by a
slow suite can bring a handful of doubtful kills whose run again takes
all the time the job has left. The job then stops at its deadline with
every later unit not started, and a unit cut across the shards stays
unjudged as long as one of its parts has not run.

A kill the run again confirms stands only where the unmutated control of
its run passes (ADR-0143). That control replays what the run selected,
which for a unit a slow suite holds is that suite, in one process.

A kill the run again does not reach is left unjudged, and the wave after
runs what a shard leaves (ADR-0190).

## Decision

1. **`shards.confirm` is the share of the time a first-wave job has left,
   as the run again starts, that the run again and the unmutated controls
   of the kills it confirms may take together.** It is a number above 0
   and at most 1, and 0.1 where the config sets none.
2. **A kill the share does not reach is left to the wave after**, unjudged
   in this job as one the deadline stops is: one the run again does not
   reach, and one whose control the share stops. Its status is the one the
   wave after gives it.
3. **The share holds only where a wave follows the job**: a first-wave
   shard's job that stops at its deadline. A share's job of the wave
   after, a run with no deadline and a run no wave follows confirm with
   all the time they have left.

## Consequences

- A first-wave job reaches the units behind a group with doubtful kills.
- The wave after carries the confirmations the first wave left, as work
  cut across its jobs.
- `shards.confirm` at 1 gives a first-wave job all its time left for the
  run again.
