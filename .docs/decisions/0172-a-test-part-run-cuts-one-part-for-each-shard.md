# ADR-0172: A test-part run cuts one part for each shard

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0134](0134-shards-pull-the-work-left-instead-of-owning-a-fixed-part.md): how many items a pulling run cuts where its streams shard by tests

## Context

Shards that pull their work run items (ADR-0134). A pulling run cut each
package into items of `plan.chunkMinutes`, and under
`splitStream.sharding: tests` into that many test parts (ADR-0117). Every
part takes every unit of its package, so each item makes every unit's
mutants and reads each one's record, whatever its part's share of the tests.

The gate's own full plan in run 44004701114 cut 118 parts for 19 shards, so
each shard job ran six or seven items. Item 51, run locally on that plan,
spent about 3 minutes making the 56,331 mutants of every unit and about 6.5
minutes in the gate's own process reading them before its first trial, on a
loaded 8-core laptop. Its part of the tests is about 10 of the 1,206 seconds
the map times the suite at. A job paid that per item, six or seven times
over.

## Decision

1. **Under test parts, a pulling run cuts one part for each shard of the
   package,** at least one, whatever `plan.chunkMinutes` says. Each shard
   lists its own part first, as every pulling run lists its items.
2. **Every other pulling run is unchanged:** items of `plan.chunkMinutes`,
   at least one a shard.

## Why no verdict moves

The number of parts changes only which part streams a test file; every test
runs in one part, every part takes every unit, and the verdict merges the
parts as before (ADR-0117).

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep chunked parts and share what an item makes with the job's later items | An item's mutants are made in its own Pest process; sharing them across a job's items needs a store the parts read, and still cuts more parts than the job has use for. |
| Make each part take only the units its tests cover | The gate's own map has the median part of 19 covering 1,528 of 2,818 files, and the parts would no longer hold the same mutants of a unit, which the verdict's merge requires. |

## Consequences

- A test-part run has as many items as shards; a lost shard's part is
  still taken by another shard as ADR-0134 has it.

## Related

- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0134](0134-shards-pull-the-work-left-instead-of-owning-a-fixed-part.md)
- [ADR-0171](0171-a-test-no-loaded-file-declares-leaves-the-cut-standing-and-an-uncovered-mutant-is-judged-once.md)
