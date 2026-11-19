# ADR-0169: The whole map is written beside the readying

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0163](0163-the-map-a-shard-opens-on-is-readied-once-as-the-plan-is-made.md): the order the plan writes the shards' maps in

## Context

The plan readies the map every shard opens on (ADR-0163), then encodes and
writes the whole map, each shard's and the verdict's (ADR-0013). The whole
map is the largest of them, and nothing in the readying reads it: the
plan's process encoded it only once the readying was over, while its other
cores waited.

## Decision

1. **The plan forks a child that encodes and writes the whole map as it
   readies the map every shard opens on.** The child ends by SIGUSR2 once
   the map is written and by SIGKILL otherwise, so nothing the plan set to
   happen at its end runs in it.
2. **The plan writes the whole map itself where the child did not,** or
   where this PHP cannot fork, as before.
3. **Where the runner cannot ready its map, the plan hands nothing,** the
   whole map the child wrote removed, as before.

## Why no verdict moves

The child encodes the same map with the same level as the plan did; the
file is byte-identical.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Encode at a lower level | The map moves to every shard as an artifact; a larger file costs each shard its download. |
| Write every slice in children too | Where the engine writes the slices it reads them from the whole map on disk, after the child; the whole map is the one that waits on nothing. |

## Consequences

- The plan's whole-map encode overlaps the readying instead of following it.

## Related

- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)
- [ADR-0163](0163-the-map-a-shard-opens-on-is-readied-once-as-the-plan-is-made.md)
- [ADR-0164](0164-the-analyser-warms-up-once-as-the-plan-is-made.md)
