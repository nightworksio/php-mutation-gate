# ADR-0199: A job handed the plan's result cache restores none over it

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0052](0052-phpstans-warm-up-uses-every-core-and-its-result-cache-is-kept-between-runs.md): when the action restores and saves PHPStan's result cache

## Context

The plan warms the analyser up once and hands its result cache to every
job with the plan (ADR-0164). Each job then runs the action, whose step
for ADR-0052 restores the newest result cache any earlier run saved, by
the key's prefix, onto the same path. The restore wrote over the cache the
plan had just handed the job.

On probe 44424669540 the jobs of one run restored three different caches
that other runs had saved, and on probe 44401370251 two. Each warm-up then
analysed again what its cache no longer held: on 44401370251 a
second-wave job took 72 seconds to check its one survivor, and a shard 90
seconds to check five.

## Decision

1. **A job that holds a result cache when the action starts restores
   none.** The cache the plan handed it stands.
2. **A job that restored none saves none.** The plan's own job, which
   restored one, saves the cache its warm-up left, as before.

## Why no verdict moves

PHPStan checks a result cache against the code and its config, and
analyses again what the cache does not hold. A check's findings are those
of the analysis whichever cache it starts from; only its time changes.

## Alternatives considered

- **Restore into another path and pick the newer**: the plan's cache is
  always the newer, since it was made from this run's code.
- **Key the cache by the commit**: a run of a new commit would find none
  and warm up from nothing.

## Consequences

- Every shard and second-wave job starts its checks from the cache the
  plan made of this run's code.
- A run with no plan, or a plan that warmed nothing, restores and saves
  as before.

## Related

- [ADR-0052](0052-phpstans-warm-up-uses-every-core-and-its-result-cache-is-kept-between-runs.md)
- [ADR-0164](0164-the-analyser-warms-up-once-as-the-plan-is-made.md)
