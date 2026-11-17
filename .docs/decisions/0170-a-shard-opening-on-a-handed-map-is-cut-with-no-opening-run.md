# ADR-0170: A shard opening on a handed map is cut with no opening run

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): what a shard's overhead counts

## Context

The cut counts each shard's overhead as its opening run plus
`shards.setup`, and makes no shard whose share of the cost is below
`shards.cutAt` times that overhead (ADR-0006, ADR-0013). The plan counted
the opening run as the coverage run's tests, the whole suite, for every
runner. A runner that opens each shard on a map the plan hands it
(ADR-0163) runs no such suite in a shard. On the gate's own code the plan
counted 725 s of opening run for each of its 114 shards, so no shard was cut
below 1,570 s of work, and the median shard was planned at 26 minutes where
`shards.seconds` asks for 10.

## Decision

1. **A shard's opening run is the coverage run's tests where the runner
   opens each shard on the whole suite** (`RunnerBehaviour::opensEachShard()`),
   as Infection and an unpatched Pest do.
2. **Where the runner opens each shard on the map the plan hands it, the
   shard's opening run is none,** and its overhead is `shards.setup`.

## Why no verdict moves

Only where the cut falls changes; every unit is planned and judged as
before.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Lower `shards.cutAt` | It hides a wrong overhead under a setting, and every project would have to find the value. |
| Learn the opening from the shards' measured steps | The steps a shard runs before its first mutant are its units' baselines and trials, which grow with its units, not a fixed cost of opening. |

## Consequences

- A patched Pest project is cut near `shards.seconds`, into more and
  shorter shards.

## Related

- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md)
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)
- [ADR-0163](0163-the-map-a-shard-opens-on-is-readied-once-as-the-plan-is-made.md)
