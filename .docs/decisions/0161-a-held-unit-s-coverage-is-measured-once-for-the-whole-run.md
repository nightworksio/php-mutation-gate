# ADR-0161: A held unit's coverage is measured once for the whole run

**Status:** Accepted
**Date:** 2026-11-16
**Amends:** [0005](0005-what-a-change-reaches-is-what-is-mutated.md): where a group's coverage of what it holds is measured; [0142](0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md): the values of `holds.coverage`

## Context

A group must cover what it holds before it may judge it (ADR-0005). Before
a shard mutates a held unit, the unit's holding tests run alone under
coverage, and every line of it the suite's map covers must be among theirs.

Under `splitStream.sharding` `tests` (ADR-0117), every item of a run takes
every unit of its package and one part of the test files. Every item so
ran the same holding tests of the same held units against the same map. On
the gate's own full run 43880107821, each of the 102 items held the same
13 units; their holding tests, run alone one unit after another, took
about 12 minutes of every item before its first mutant, about 20 of the
run's 25 planned runner-hours. A local item of the same plan spent 88% of
its first five and a half minutes there.

What the run measures does not depend on the item: the holding tests, the
held file and the map are the plan's.

## Decision

1. **`holds.coverage` `measure`, the default, measures once, as the plan is
   made.** The plan runs the holding tests of every held unit its shards
   hold, each unit's alone under coverage as a shard ran them, checks them
   against the map it hands the shards, and writes into the plan, by the
   held path, the tests of the group that run the unit, the sentence naming
   the lines they miss, or why they cannot run on their own.
2. **A shard takes what the plan measured, and runs nothing,** where the
   plan measured every held unit the shard holds. A unit the plan said its
   tests cannot run alone leaves the shard unable to judge, as its own run
   did. A plan without a measure for a held unit, such as one written
   before this decision, leaves the shard to measure it.
3. **`holds.coverage` `measure-in-each-shard` runs them in every shard that
   holds the unit,** as before this decision. `map` is unchanged.
4. **A run planned without a fresh map measures in its shards,** where the
   streams' recorded map checks the group afterwards (ADR-0135).
5. **A group's run loads only the test files that declare its tests,** as
   the runner lists the group and names each test's file, in the runner's
   own process. The same tests run, and no other: the group still selects
   them. Where the runner cannot place one of them in a file, or that run
   fails, the run loads its suites as before. On the gate's own suite one
   group's run took 2.5 s so, and 20 s across 8 processes over its four
   suites.

## Why no verdict moves

The same tests run alone under coverage in the same checkout, against the
same map, once instead of once for each item. The check and what it keeps,
the tests that judge the unit's mutants, are what each shard computed.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep each item's measure, cached within its shard | A shard still runs every held unit once, and a full run has as many shards as it can start: the measure stays in every shard's time. |
| Read the group's lines from the map (`map`) | It does not run the group alone, which the check is for (ADR-0142 lists it as loose). |
| Run every group together in one process | A group's tests would no longer run alone: another group's test could leave the state a line runs under. |
| A separate artifact beside the plan | The plan already reaches every shard, digested; a second file is a second thing to hand on and check. |

## Consequences

- The plan job runs the holding tests once, and its time grows by that run;
  every item's shrinks by it.
- The plan file grows by the holding tests of each held unit, once.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md)
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0135](0135-a-full-split-stream-run-starts-without-waiting-for-a-fresh-map.md)
- [ADR-0142](0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md)
