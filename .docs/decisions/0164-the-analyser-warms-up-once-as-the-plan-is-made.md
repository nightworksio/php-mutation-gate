# ADR-0164: The analyser warms up once, as the plan is made

**Status:** Accepted
**Date:** 2026-11-16
**Amends:** [0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): where the analyser's warm-up runs, and the time a check is taken to need

## Context

Before a shard checks its first mutant with the static analyser, the
analyser runs once over every original file (ADR-0020), and each check then
starts from what that run left: PHPStan's result cache. The run's time also
stands for a check's time until a check is measured, which fits the checks
before the shard's deadline.

Every shard ran the same warm-up over the same files. On the gate's own
probe plan, one local item spent 118 s on it. A warm-up whose result cache
is still valid takes 2 s: PHPStan reads the cache and hashes the files.

## Decision

1. **`staticCheck.warmAtPlan` `true`, the default, warms the analyser up
   once, as the plan is made,** where its shards check mutants with it
   (`staticCheck.before` or `staticCheck.after`) and the plan holds a shard.
   The plan leaves how long it took, and by which analyser and config, in
   `.mutation-gate/warm-up.json`; the reusable workflow hands that and
   PHPStan's result cache to every shard with the plan.
2. **A shard warms up as before,** from the cache it was handed, so PHPStan
   analyses only what changed, which is nothing on an unchanged checkout.
   A cache that does not fit, such as one from another PHP, is PHPStan's to
   refuse, and the shard then analyses every file.
3. **A shard takes the plan's warm-up time for a check's, where it is the
   longer,** and the plan's analyser and config are the shard's. A warm-up
   from a cache says nothing of how long a check takes.
4. **A plan that warms nothing up removes the time an earlier plan left,**
   as does one whose warm-up fails; each shard then warms up itself.
5. **`staticCheck.warmAtPlan: false` warms up in each shard alone,** as
   before this decision. It changes no output, and keys nothing.

## Why no verdict moves

The analyser checks the same mutants against the same findings in the
originals: PHPStan's result cache holds what the full run found, and PHPStan
itself decides which of it still holds. The time a check is taken to need is
never less than a full warm-up's, as before.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep warming up in every shard (`staticCheck.warmAtPlan: false`) | Every shard pays the same full analysis; 118 s of a local item. Kept as the setting. |
| Write the warm-up's findings into the plan, and skip the analyser in shards | The checks start from PHPStan's result cache, which only the analyser writes; findings alone leave every check to analyse from nothing. |
| Take the shard's own warm-up time | From a valid cache it is a couple of seconds, far less than a check, and the deadline would fit more checks than there is time for. |

## Consequences

- The plan job runs the analyser once over every file, and its time grows
  by that run; every shard's shrinks by it.
- The plan artifact holds PHPStan's result cache, tens of megabytes before
  compression.

## Related

- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md)
- [ADR-0112](0112-every-number-a-run-goes-by-is-a-setting.md)
- [ADR-0163](0163-the-map-a-shard-opens-on-is-readied-once-as-the-plan-is-made.md)
