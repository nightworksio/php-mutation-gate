# ADR-0177: A child times out only against its parent's own time

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): decision 5, for a child whose parent has not yet ended its test

## Context

ADR-0117 decision 5 holds a stream's child to `timeouts.perTest` times its
parent's own time of the same test, never under `timeouts.testFloor`. The
parent's time is only known once the parent ends the test. Until then, the
stream held each child to the same multiple of the coverage map's time of
the test, counted from the fork. A child still running at that deadline
was stopped and its mutant timed out, even though the parent was still
running the test.

The map is timed on another machine, under another load. On a box that
runs a test ten times slower than the map's machine did, a child doing the
same work as its parent ran past the map-based deadline and was stopped
while the parent was still working. That made a survivor read as a
timeout, and a timeout counts as a kill.

The parent's own time, once known, also counted the time it spent waiting
for a place to fork in. Run locally on shard 9 of 19,
`ReporterTest::it_reports_a_verdict_of_each_kind` (3 ms in the map)
reached about 1,000 sites, and its parent spent 790 s in the test, mostly
waiting for places. Held to three times that, a hung child of the test
could run for 40 minutes.

## Decision

1. **No child is stopped as past its limit while its parent still runs
   its test.** Until the parent ends the test, a child is held to
   `timeouts.most` alone, the most any mutant's run may take.
2. **Once the parent ends the test, each child of it is held to
   `timeouts.perTest` times the parent's own time, less the time the
   parent spent waiting for a place in that test**, never under
   `timeouts.testFloor`, counted from the fork. The time spent waiting is
   not work the test does, so it does not stretch a child's limit.
3. **A child that ran past the limit the map's time sets leaves its place
   where its parent needs one.** It runs on beside the places, held to
   `timeouts.most`, and the parent forks its next child into the place.
   When the parent ends the test, the child is held to its limit like any
   other child of the test, and is stopped as soon as it is past it. A
   child only leaves its place once its parent needs one, so a child that
   runs long while places are free changes nothing.
4. **The map's time only decides when a child may leave its place.** Where
   the map has no time for the test, a child may leave once it ran
   `timeouts.testFloor` seconds.
5. **Where the stream counts steps (ADR-0147), nothing changes.** A child
   there is held to its parent's steps, which no load changes, and to
   `timeouts.most`, and never leaves its place.

## Why no verdict loosens

- A timeout is now only decided against the time the parent took for the
  same test on the same machine at the same time, which is what
  ADR-0117's decision 5 asks for. A timeout means the mutant made the test
  slower than its parent ran it.
- A child that leaves its place keeps running, so its time is measured
  the same way as any other child's.
- Taking the parent's waits out of its time only removes time in which
  the test did no work. The child's limit is still a multiple of what the
  test took, never under the floor.
- A timeout that was decided before the parent ended the test is now
  decided after it, against the parent's own time. That can turn a
  timeout into a survivor or a kill by another test, never a survivor
  into a timeout.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Hold every child to `timeouts.most` until its parent ends the test, and nothing more | A hung child keeps its place until then, and a parent waiting for that place cannot end the test, so it waits up to `timeouts.most` for each one. |
| Pause a late child with `SIGSTOP` until its parent ends the test, its pause not counted | A child paused while it holds a lock its parent then takes, such as the one on the stream's results file, stops the parent for good. |
| Stop a late child and leave its mutant to its own run | Every hung mutant of a busy test pays for a run of its own, with its own start-up, instead of a few seconds in its stream. |
| Count CPU time instead of wall time | The parent's own time is wall time, and a child that waits on the clock or a lock spends wall time doing nothing the CPU sees. |

## Consequences

- A stream on a slow or busy machine no longer turns its children into
  timeouts its parent's own time does not support.
- A test that reaches many sites in which mutants hang still pays
  `timeouts.testFloor` for each hung child before the child leaves its
  place, as it did before.
- While a parent still runs a test, the children that left their places
  run beside the ones in places, so a stream can run more processes than
  its share of the cores until the parent ends the test.

## Related

- [ADR-0090](0090-each-test-of-a-mutants-run-is-held-to-a-multiple-of-its-own-time.md)
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0147](0147-a-stream-records-its-own-lines-and-holds-a-child-to-its-parent-s-steps.md)
- [ADR-0173](0173-a-stream-forks-on-its-share-of-the-cores.md)
