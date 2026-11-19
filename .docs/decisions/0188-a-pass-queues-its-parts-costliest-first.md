# ADR-0188: A pass queues its parts, costliest first

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): how a pass cuts its test files into streams and starts them

## Context

A pass of split streams cut its covering test files into one part for each
process, by the coverage map's times, and started every part at once
(ADR-0117). A part's map time says how long its parent runs, not how many
children it forks: a part whose tests cover many of the pass's mutants
forks a child for each, and runs long after the rest ended.

On CI the gate's own shard 9 ran its last twelve-minute snapshot on one
stream with one child: three of the runner's four cores sat idle while the
pass waited for that stream. The pass cannot end before its slowest part,
however the others fare.

## Decision

1. **A pass cuts its covering test files into `splitStream.partsPerProcess`
   parts for each process**, 4 by default, by cost: a test's map time once
   for its parent and once more for each mutant the pass forks at it. A
   test the map did not time costs nothing, as before.
2. **The parts are queued costliest first**, parts of equal cost in their
   order, and no more of them run at once than the run has processes. As
   one ends, the next starts; a stream stopped at its deadline is started
   again in its place at once, as before (ADR-0182).
3. **The streams that keep pcov** (ADR-0183) are cut and queued alike, among
   the rest.
4. **`splitStream.partsPerProcess: 1` cuts one part for each process**, all
   started at once, as before this decision. The key is in no proof key.

## Why no verdict moves

Every test of the pass runs in exactly one stream, as before, with its
parent as each child's control, and forks the same mutants at the same
sites. A part holds whole test files, as before, so a file's setup runs
with all of its tests. Only how many streams there are and when each starts
changes.

## Alternatives considered

| Alternative | Why not |
|---|---|
| One part for each process (`partsPerProcess: 1`) | Kept as the setting: the slowest part decides the pass, while the rest of the machine idles. |
| A queue of single tests, claimed by long-running streams | Every stream would load every file of the pass and skip the tests another claimed, and a file's tests would spread across streams, each paying its setup. |
| Weigh parts by map time alone | A test that forks a hundred children weighs as much as one that forks none. |
| One pool of `cores` child places for every stream, in place of each stream's share of the cores (ADR-0173) | While the queue holds parts, as many streams run as there are processes, each parent on a core of its own, and a parent waiting for a place leaves its core to the rest; once the queue drains, the shares already grow to every core among the streams left. A pool would add children beside busy parents, past the cores. |

## Consequences

- A pass starts more streams, each a Pest boot: four times as many at the
  default, against a tail no longer waiting on one part.
- The streams' child places, shared among the streams still running
  (ADR-0173), go to the parts still running as the queue drains.

## Related

- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0173](0173-a-stream-forks-on-its-share-of-the-cores.md)
- [ADR-0182](0182-no-test-holds-a-stream.md)
- [ADR-0183](0183-pcov-is-off-wherever-no-test-reads-it.md)
