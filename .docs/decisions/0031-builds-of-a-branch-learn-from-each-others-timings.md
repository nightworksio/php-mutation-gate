# ADR-0031: Builds of a branch learn from each other's timings

**Status:** Accepted
**Date:** 2026-10-20

## Context

ADR-0006, decision 4 says "a timing counts only for the gate that measured
it", a branch build by its name and commit. Composer names a branch build in
a detached checkout by its commit, `dev-7fe21685…`, so every commit of the
gate's own repository is a release of its own. No run of the gate's own CI
has ever estimated from a learned timing.

The estimates were the cold start's. In run 39378485071 the planner gave each
of 40 shards 22 to 40 minutes, and they took 19 to 135. The wall time follows
the slowest shard.

Read against that run's results, its own shares (decision 4: a shard's time
shared among its units by their mutants' durations) would cut 20 shards in
path order whose longest takes 100 minutes and shortest 65. A cut by each
unit's exact time would give 90 and 79.

## Decision

1. **Two builds of a branch cost alike**, whatever the branch and whatever the
   commit: a plan of one estimates from the timings any other measured, and
   smooths a new timing over one any other measured. A build of a branch is a
   version Composer spells `dev-<name>` or `<name>-dev`, as
   `Version::isRelease()` reads it.
2. **A release still costs only as itself.** A tagged release learns only from
   its own timings, and never from a branch build's, nor a branch build from
   a release's.
3. **The gate's own config cuts at most 20 shards**, the runner cap of its
   plan, so that a full run is one wave of shards.

This supersedes ADR-0006, decision 4, "a timing counts only for the gate that
measured it", for builds of a branch.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Key branch builds by a digest of the gate's own code** | Almost every pull request of the gate changes its code, so it would learn as little as now. |
| **Record each unit's exact time per invocation** | It is exact only while each unit runs in its own invocation, which one runner session per shard ends. The shares come within 10 minutes of it. |

## Consequences

- A branch build's estimate is as stale as the last run of any branch. The
  smoothing, which weighs the newest timing 0.9, corrects it in one run.
- A project that runs a branch of the gate learns as a release does.

## Related

- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): decision 4, which this narrows for builds of a branch
