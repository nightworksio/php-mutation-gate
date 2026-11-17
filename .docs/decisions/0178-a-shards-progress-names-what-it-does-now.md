# ADR-0178: A shard's progress names what it does now

**Status:** Accepted
**Date:** 2026-11-17

## Context

A Pest shard's log line says how many of its mutants are judged, counting
the ones its split streams decided (ADR-0174), and names the mutant whose
own run is going on. Once the streams' first pass settles, most shards
spend their last minutes on a few hundred to a few thousand mutants. The
line then sits still and does not say whether the time goes to a second
pass of streams, to one slow stream, or to own runs. A capped CI shard
leaves nothing else to tell them apart.

## Decision

1. **While streams run, the line names their pass and how many of its
   streams still run:** `streams pass 2: 3 of 8 running`. The pass is the
   latest whose streams wrote a results file; a stream counts as running
   while its mark is in the stream directory (ADR-0173).
2. **Every stream marks itself running**, whether it shares the cores or
   holds a fixed number of children. A fixed number is still that number,
   whatever the marks say.
3. **Once the streams are done, the line says how many mutants are left
   for their own runs** before the one it is running:
   `own runs: 412 left, running src/Money.php:12 (PlusToMinus)`.
4. **The streams' results are read only where a line could decide a
   mutant.** A line naming no mutant is not decoded, so most of a stream's
   lines cost nothing to look at.

## Consequences

- A capped probe's log shows which phase each shard's tail is in.
- The line stays one line: a pass number and three counts at most.

## Related

- [ADR-0157](0157-a-ci-shard-leaves-its-progress-as-it-runs.md)
- [ADR-0173](0173-a-stream-forks-on-its-share-of-the-cores.md)
- [ADR-0174](0174-a-mutant-is-judged-whole-in-the-one-part-that-owns-it.md)
