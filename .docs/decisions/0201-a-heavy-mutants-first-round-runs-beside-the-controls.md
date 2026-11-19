# ADR-0201: A heavy mutant's first round runs beside the controls

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0114](0114-a-shard-is-cut-to-fit-its-job-and-stops-before-it.md): when the first round of a heavy mutant's parts runs

## Context

An invocation runs its controls side by side in its pool (ADR-0008),
and only once they have all ended runs the first round of its heavy
mutants' parts, then each round after the one before. The controls'
pool idles as its last controls end, and the first round then waits on
it.

On the gate's own run at a9505f98 a shard spent about an hour, after its
108 controls, on rounds of three parts and then one, with a single
process running on its four cores for most of that hour.

## Decision

1. **The first round of the heavy mutants' parts is queued in the
   controls' pool, behind the controls**: what each heavy mutant waits on
   and, under `timeouts.heavyFill`, the parts ahead that fill the pool.
   They start as places free up, where every control fits in the time left
   and each part does too, as the parts ahead always did.
2. **A part that ran there is found, not run again.** The rounds after
   start from it once the controls have ended; a part that did not fit,
   or was halted (ADR-0197), runs in its round as before.
3. **Its time is the controls step's**, named as a heavy part's
   (ADR-0167).
4. **`timeouts.heavyBeside`**, on by default, turns it off: the first
   round then starts once every control has ended, as before. It is a
   speed-up that changes no output (ADR-0112).

## Why no verdict moves

A heavy mutant's parts do not depend on any control: a heavy timeout has
no control of its own, and no control reads a part. Each part runs the
same command, under the same limit, in the same pool, and is read by its
place among the parts as before (ADR-0114). Only when it starts changes.

## Alternatives considered

- **One pool that runs every round as its parts' runs end**: each round
  depends on the last, and a runner runs its controls as one set; the
  first round alone needs no change to any runner.
- **Every round beside the controls**: a later round depends on what the
  earlier found, which the controls' set cannot wait for.

## Consequences

- The places the controls leave idle at their end run the first round.
- A shard's controls step holds the first round's time, each part named
  as a heavy part's.
- A test runs the same invocation with the setting on and off and
  requires the same status for every mutant.

## Related

- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md)
- [ADR-0112](0112-every-number-a-run-goes-by-is-a-setting.md)
- [ADR-0114](0114-a-shard-is-cut-to-fit-its-job-and-stops-before-it.md)
- [ADR-0167](0167-a-shard-says-where-each-controls-time-went.md)
- [ADR-0197](0197-a-job-takes-what-fits-and-halts-what-runs-past.md)
