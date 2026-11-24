# ADR-0335: A job that stops keeps a share of its time to vouch for its kills

**Status:** Accepted
**Date:** 2026-11-23
**Amends:** [0332](0332-a-first-wave-job-confirms-doubtful-kills-within-a-share-of-its-time.md)

## Context

A narrowed kill counts once its test files pass alone on the unmutated
code, or once it is run again with every test file and its control passes
(ADR-0142). A job that stops at a deadline gave its mutants' own run all
the time it had left, so that run ended at the stop. The runs that vouch
for its kills then had no time: none of them ran, every kill was doubtful,
and each was left unjudged, saying the time ran out before its kill could
be vouched for. The wave after did the same. ADR-0332 held the run again to
a share of the time left, which by then was none.

## Decision

1. **A job that stops at a deadline ends its mutants' own run sooner by
   `shards.confirm` of the time the run was given.** The runs that vouch
   for its kills have that share.
2. **This holds for a shard's job of the first wave and for a share's job
   of the wave after alike.** A run with no deadline keeps nothing back.
3. **The run again of doubtful kills and its controls take, together, at
   most the share kept back, and no more than is left.** The run again
   itself keeps nothing back.
4. **A run vouches for its kills before its trials.** The trials of the
   mutants on lines that are not executable (ADR-0004) take what time is
   left after that, and those that do not start are left to the wave
   after, whose own run is shorter.
5. **`shards.confirm` still changes when a kill is vouched for, and never
   its status.**

6. **A stream runs its test files fastest first.** A part's files ran in
   the order of their paths, which put the slowest suites first. They run
   by the time the map gives their tests, a file it timed no test of after
   every one it did, then by path: a mutant a fast test kills is killed
   before the slow ones run, and the run a replay repeats to vouch for it
   is short. The tests a mutant is judged by are the same.
7. **A run again keeps the mutated copies beside its results.** The trials
   after it serve them.

8. **A control of some tests alone loads the files those tests are in.**
   Under Pest a control that selects its tests by their ids named no test
   file, so its run loaded every test file of its suites to filter a few,
   and spent its limit starting. Its request tells the file each test is
   in, and the control names those files where each of its tests has one;
   where one has none it loads every test file, as it did. A control that
   fails or runs out leaves its kill unjudged, so a test that needs a file
   it no longer loads counts no kill. A runner whose controls load only
   what they run is asked for no places.

## Consequences

- A job judges slightly fewer mutants in its own run, and leaves far fewer
  kills unjudged.
- A mutant its own run did not reach is left to the wave after, as before.
