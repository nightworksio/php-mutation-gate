# ADR-0301: No second wave once the shards decide the verdict

**Status:** Accepted
**Date:** 2026-11-20
**Amends:** [0190](0190-a-shard-hands-what-it-left-to-a-second-wave.md): when a second wave is cut
**Amends:** [0196](0196-a-job-stops-at-its-target-less-what-it-measured.md): the shards that left no result

## Context

`mutation-gate leftovers` cut the mutants every shard left into shares,
naming the shards that left no result, whose mutants no share held. On the
gate's own run of 89b3bdbf6 one shard's runner was lost and left no result;
the cut said so and still printed 231 shares. The verdict cannot judge a
run with a shard that left no result, whatever the shares find, so each of
those jobs, about 1,736 runner-minutes, changed nothing.

## Decision

1. **The verdict's own reading decides.** `Results::decided` reads the
   shards' results as the verdict does and says the verdict is decided where
   either holds:
   - a shard's result cannot be judged, or a shard left none and no shard
     stopped once the run could not pass: the verdict cannot judge the run;
   - a shard stopped once the run could not pass (`run.failFast` `stop` or
     `cancel`, ADR-0103): the verdict fails, the units it did not run and
     those of every shard that left no result read as stopped.
   It is the reading `Results::read` makes before it takes the shares in, so
   the two cannot disagree.
2. **A decided verdict gets no second wave.** The cut writes no share,
   prints the matrix `[]`, and says on its error output why: "No second wave
   is cut: its jobs would change no verdict", then the verdict's reason.
3. **Nothing else stops one.** A survivor that already fails a floor while
   `run.failFast` is `mark` or `off` leaves the second wave in place: `mark`
   asks for every survivor named, and a failing floor is no stop.

## Why no verdict moves

- Where a result cannot be judged or a shard left none with no shard
  stopped, `Results::read` cannot judge before it reads a share, and a
  share's result can only add a reason it cannot.
- Where a shard stopped once the run could not pass, the verdict fails on
  that shard's survivor whatever the shares judge; the run had already
  chosen to stop judging once it could not pass.
- An undecided run is cut exactly as before.

## Alternatives considered

- **A setting to cut the second wave anyway**: its results reach no
  verdict, report or ledger where the verdict cannot judge, and where a
  shard stopped the run already chose to stop; a setting would only buy
  runner time back for nothing.
- **Decide in the workflow from the shards' job conclusions**: a job that
  succeeded can still leave a result the verdict cannot read; the gate
  reads the results themselves.
- **Stop on a floor already failed under `mark`**: `mark` names every
  survivor; stopping is what `stop` and `cancel` are for.

## Consequences

- On the gate's own run of 89b3bdbf6 the cut would have printed `[]`, and the
  231 jobs of about 7.5 minutes each would not have started.
- Running the lost shard again and then the cut again cuts the second wave.

## Related

- [ADR-0103](0103-a-doomed-shard-fails-fast.md): a doomed shard fails fast.
- [ADR-0300](0300-a-kill-left-unvouched-by-the-time-is-handed-on.md): a kill the time left unvouched is handed on.
