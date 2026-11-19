# ADR-0197: A job takes what fits, and halts what runs past its stop

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0134](0134-shards-pull-the-work-left-instead-of-owning-a-fixed-part.md): when a job takes a further item
**Amends:** [0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): a control still running at the deadline

## Context

On the gate's own probe after ADR-0196 every shard stopped on time, but two
things still ran past the target. A job that finished its own item took
another with five minutes left, though the plan expected the item to take
about seven: the job stopped at its deadline with 2,920 of the item's
mutants unjudged, and the second wave cut them into 172 shares. And a heavy
mutant's part, started from the controls' queue before the stop, ran five
minutes past it: a control was only kept from starting once the deadline
had passed, then allowed its own limit, so one job of the second wave took
10m56s and left the mutant unjudged anyway.

## Decision

1. **A job takes a further item only where it fits.** A job always takes
   its first item. Once it ran one, it takes the next only where the wall
   time the plan expects of it fits the time left to its stop: the item's
   cost, spread over the job's streams' processes where it judges by one
   part of the test files, as the plan cut it (ADR-0195). Otherwise it
   starts nothing more and says why; the item is left untouched to its own
   job, or to the second wave.
2. **What runs past the stop is halted.** Each control, each heavy
   mutant's part and each run that vouches for a kill alone runs in a pool
   that starts none past the run's deadline; one still running
   `runner.deadlineGrace` past it, `15s` by default, is halted with every
   process it started. A kill a halted run did not vouch for is doubted,
   as one whose run ran out of the time left is. Its run tells nothing: it is never
   read as having run out of its limit, so it never makes a timeout. The
   control is among those the time left had no room for, and the mutant it
   judges is unjudged, `before-controlling`, for the second wave.

## Why no verdict moves

A taken or left item decides only which job judges its mutants. A halted
control finds nothing, and its mutant is unjudged exactly as one whose
control did not fit; the second wave judges it as the shard would have.

## Alternatives considered

- **Take an item whatever is left**: the probe's late item left 2,920
  mutants for the second wave, which paid each one's preparation again.
- **The item's price measured in the job from its own item**: items differ;
  the job's own item took 169 s and the next ran past five minutes.
- **A halted control read as running out**: a stop would make a timeout.
- **No grace**: a control a moment from its end is lost to the second wave.

## Consequences

- No control or heavy part runs more than `runner.deadlineGrace` past the
  stop; the wind-down a job learns (ADR-0196) holds it.
- The native runner's warm workforce keeps its own bound: its children
  start before the deadline and its workers stop at the deadline plus the
  longest limit and their slack.

## Related

- [ADR-0196](0196-a-job-stops-at-its-target-less-what-it-measured.md): a job stops at its target less what it measured.
