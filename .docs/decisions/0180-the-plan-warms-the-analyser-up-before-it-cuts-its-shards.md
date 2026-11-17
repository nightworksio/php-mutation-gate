# ADR-0180: The plan warms the analyser up before it cuts its shards

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0164](0164-the-analyser-warms-up-once-as-the-plan-is-made.md): when the warm-up begins

## Context

The plan warms the static analyser up over the originals once, in a child
forked beside the work that readies the shards' maps (ADR-0164), and
measures a mutant's start-up once it has ended (ADR-0165). The readying is
short next to the warm-up. In CI run 44152440509 the plan step read its
handed map and cut its shards for about 3 minutes 40 seconds, then waited
about 2 minutes for the warm-up the handoff began, so the warm-up ran
almost entirely after the rest of the plan's work.

The warm-up needs nothing of the plan but whether it holds a shard.

## Decision

1. **Where the plan reads its coverage map and runs no test before it cuts
   its shards, it begins the warm-up as soon as it starts measuring
   them**, in the same forked child, so the analyser runs while the plan
   reads the map, keys its units and cuts its shards.
2. **A plan that turns out to hold no shard, or makes no plan, drops the
   warm-up:** it stops the child where it still runs, without waiting for
   the analyser the child started, and removes what the child left, so no
   shard reads a warm-up of a plan that runs nothing.
3. **Where the plan runs tests first,** a coverage run or a quick round's
   measuring, it begins the warm-up beside the handoff as before, so no
   test's time is measured beside the analyser.
4. **The start-up is still measured once the warm-up has ended** (ADR-0165).

## Why no verdict moves

The warm-up runs the same analyser over the same originals and leaves the
same file; only when it starts changes. No test's time is measured while it
runs, since a plan that runs tests begins it after them, and the start-up
every mutant's limit is laid on is still measured once it has ended. The
cost model's runs of no test, which time a unit no ledger times yet, may
run beside it; they size the shards, and no limit is laid on them.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Skip the warm-up at plan where CI does not need it | Every shard that checks mutants then warms up itself, about two minutes each. |
| Begin it before the plan reads anything | A plan that runs its own coverage run would measure its tests beside the analyser. |

## Consequences

- A plan on a handed map takes about as long as its own work, or the
  warm-up, whichever is longer, instead of both.
- A plan that holds no shard still starts the analyser, which runs on to
  its end beside the rest of the job; the plan does not wait for it.

## Related

- [ADR-0164](0164-the-analyser-warms-up-once-as-the-plan-is-made.md)
- [ADR-0165](0165-a-mutant-s-start-up-is-measured-once-as-the-plan-is-made.md)
