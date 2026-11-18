# ADR-0184: A restarted stream starts slower

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0182](0182-no-test-holds-a-stream.md): the slowdown a stream started in a stopped one's place starts at, and the most a deadline gives a test

## Context

A stream's parent keeps a deadline for the test it runs (ADR-0182): the
limit a child of the test is held to, of the map's time times the stream's
slowdown. A stream stopped at its deadline is started again on the tests
it had not ended, but that one, and that stream's slowdown started at 1,
as fast as the map, whatever the stopped one had reached.

Run locally on the plan of the gate's own shard 9 on a loaded machine, the
first pass stopped and started its streams 4 times. The second pass, whose
tests fork many children each, grew from 8 streams to 121 by its 21st
minute: about 113 restarts, each a Pest boot, each sending the stopped
test's mutants to their own runs. Each restarted stream forgot what the
stopped one had learnt, so the next heavy test stopped it again.

With restarted streams backing off alone, the slowdown applied to the
map's time only, the same run at the same load grew its second pass from 7
streams to 44: every stream
of a new pass still started as fast as the map, and each needed several
restarts to back off to the load the first pass had already measured.
With a pass also starting where the one before ended, it still grew from 6
streams to 68: the tests stopped were short ones, whose deadline was
`timeouts.testFloor`, which a slowdown applied to the map's time does not
move.

## Decision

1. **A stream's parent keeps its slowdown in its stream's mark** beside
   its test and deadline (`{"t", "d", "s"}`).
2. **Pest's own process reads it as it stops the stream**, and starts the
   stream in its place with a slowdown of `splitStream.deadlineBackoff`
   times it, never under 1, written into that stream's plan as `slowdown`.
   A stop says the estimate was low, so the next one is higher.
3. **A stream's slowdown never falls under the one it started at**: each
   test the map timed still moves it toward the stream's own times, but
   only upward of that start.
4. **The slowdown multiplies the whole limit, its floor too**: a test's
   deadline is the limit a child of it is held to, of the map's time,
   `timeouts.testFloor` at least, times the slowdown. Most tests the map
   times run under a second, so their limit is the floor, which a
   slowdown applied to the map's time alone never moves until it is
   tens of times the map's. No deadline gives a test more than
   `timeouts.most`, which a test the map timed none of already has.
5. **`splitStream.deadlineBackoff`** (a number of at least 1): `2`, by
   default, doubles the slowdown at each restart; `1` carries the stopped
   stream's slowdown on unchanged; a higher number backs off faster. It
   decides how the gate runs and moves no verdict, so it is in no key.
6. **A pass's streams start at the slowdown the pass before measured.**
   Beside the slowdown it holds its tests to, a stream's parent keeps how
   slow it measured itself (`m` in its mark, also once a test ended): the
   same moving average, started where the stream started, but free to fall
   under that start where its tests run faster. As each stream of a pass
   ends, Pest's own process takes the highest such slowdown, and the next
   pass's streams start there. Where it carries through the shard, it is
   kept beside the run's results (`<results>.slowdown`), so the shard's
   next run, the main run after a held unit's, starts its first pass
   there too.
7. **`splitStream.slowdownCarry`** (`shard`, `run`, `none`): `shard`, by
   default, carries it to the next pass and the shard's next run; `run`
   to the next pass of the same run alone; `none` starts every pass as
   fast as the map. It decides how the gate runs and moves no verdict, so
   it is in no key.

## Why restarts converge

Along one chain of restarts, each restart multiplies the slowdown the next
stream starts at by the backoff, and no later test brings it under that.
With a backoff of 2, after `k` restarts a test's deadline is at least
`2^k` times what the first stream gave it, and it reaches `timeouts.most`
within `log2(timeouts.most / limit)` restarts. Past that, a stream is
stopped only by a test that runs longer than `timeouts.most`, which a
child would be held to as well. Each restart also leaves one test out, so
a chain ends when its tests do.

## Why no verdict moves

A deadline decides only when a stream is stopped and started again
(ADR-0182). A later deadline leaves a test longer in its stream, where its
parent's line and its children's kills are read as before; a stopped
test's mutants still go to their own runs. No mutant is killed or kept by
the slowdown.

## Consequences

- Restarts on a loaded machine fall from the dozens per pass to a few: a
  chain backs off in a handful of steps, and a pass starts where the one
  before ended.
- The highest slowdown a pass measured sets the next pass's start for
  every stream, which holds a stream with lighter tests to a later
  deadline than it needs; that costs only a test that never ends, which
  is stopped within `timeouts.most` still.
- A stream that a test really holds still stops within `timeouts.most`.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Start the restarted stream at the stopped one's slowdown** (`deadlineBackoff: 1`) | The slowdown was what proved too low; the same heavy tests stop the stream again. Kept as a setting. |
| **One slowdown shared by every stream as it runs** | Streams run different tests; one with heavy forks would slow every other one's deadline while it runs. A pass only starts where the last ended, and each stream then moves on its own. |
| **The mean of a pass's slowdowns as the next start** | Half the streams would start under the load they meet and be stopped once each. |
| **Drop the deadline in the second pass** | A test that never ends would hold the pass again (ADR-0182). |
| **Raise the deadline's multiple for every stream** | It lengthens the wait for a stuck test in every stream, where a restart shows which stream needs it. |
