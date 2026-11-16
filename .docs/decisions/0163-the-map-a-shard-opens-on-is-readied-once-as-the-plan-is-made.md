# ADR-0163: The map a shard opens on is readied once, as the plan is made

**Status:** Accepted
**Date:** 2026-11-16
**Amends:** [0037](0037-a-value-is-covered-by-the-tests-that-read-it.md): where a value's lines are covered by the tests that read it

## Context

A patched Pest shard opens on the map the plan hands it. Before its first
mutant, it covers the lines of each value its files declare with the tests
that read it (ADR-0037), and each line of a match's arms with the tests of
the arms after it. Finding a value's readers scans every test file and
every file the whole map covers.

Under `splitStream.sharding` `tests` (ADR-0117), every item of a run takes
every unit of its package. Every item so readied the same map from the same
files and the same whole map. On the gate's own probe plan of 2,919 files,
one item spent 1,135 s (963 s of CPU) readying it on a laptop, before its
first mutant, and every one of the plan's items spent it again.

What the shard readies does not depend on the item: the files, the whole
map, the test files and the suite's test cases are the plan's.

## Decision

1. **`pest.readyAtPlan` `true`, the default, readies the map once, as the
   plan is made,** for the files of every shard's units at once, from the
   plan's whole map, through the runner's own opening
   (`RunnerBehaviour::opening()`). The plan leaves it at
   `.mutation-gate/coverage/opened`, beside the whole map and inside the
   coverage artifact every shard downloads.
2. **A patched shard opens on the readied map of its own files,** and
   readies nothing, where the plan left one and `pest.valueReaders` is on.
   A shard handed no readied map, as one of a plan made before this
   decision, readies its own as before.
3. **A plan that readies none removes any an earlier plan left,** so a
   shard never opens on a map another plan readied.
4. **`pest.readyAtPlan: false` has each shard ready its own,** as before
   this decision. It changes no output, and keys nothing.
5. **The scan runs as fast as the files allow.** A value's readers are
   looked for only in the files whose names spell a word a read of it must
   (an index built once for the codebase), a name a file never spells is
   never resolved, each span's covering test files are worked out once, and
   the lines are laid on the map at once. On the probe plan the readying
   took 185 s once, in place of 1,135 s in every item.

## Why no verdict moves

The same scan covers the same lines with the same tests, once instead of
once for each item. Under `tests` sharding every item scanned exactly the
files the plan scans. Under file sharding the plan's scan reads every
shard's files, where a shard read its own: a read of a value in another
shard's file is found too, so a value is covered by more of the tests that
read it, never fewer.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep readying in every shard (`pest.readyAtPlan: false`) | Every item pays the same scan; on the probe plan, 19 minutes of each item before its first mutant. Kept as the setting. |
| Write the readied lines into each shard's own map | Every shard's map grows by them, and the map engine that splits the shards' maps from the whole one would split a map the gate changed. One map beside the whole one is written once. |
| Record that a map is readied inside the map file | The map's format is shared with the map engine; the presence of the readied map, removed by a plan that readies none, says the same. |

## Consequences

- The plan job readies the map once, and its time grows by that; every
  item's shrinks by it.
- The coverage artifact holds one more map, the size of the shards' files'
  share of the whole map.
- The shard job that keeps only its own map and the whole one keeps the
  readied one too.

## Related

- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md)
- [ADR-0037](0037-a-value-is-covered-by-the-tests-that-read-it.md)
- [ADR-0112](0112-every-number-a-run-goes-by-is-a-setting.md)
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0161](0161-a-held-unit-s-coverage-is-measured-once-for-the-whole-run.md)
