# ADR-0195: A streamed job is priced at its wall time

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0190](0190-a-shard-hands-what-it-left-to-a-second-wave.md): how a share of the second wave is priced and cut
**Amends:** [0114](0114-a-shard-is-cut-to-fit-its-job-and-stops-before-it.md): how many shards a run that cuts its test files into parts makes

## Context

A job that judges by split streams (ADR-0117) runs each test file once in a
parent on each of its processes, and forks each mutant at its likely killer
or at its cheapest covering test, and, in a second pass, at every covering
test the first left. The second wave priced a share by the seconds of the
test files covering its mutants, single-core seconds set against a job's
wall seconds; on the gate's own probe that put each shard's whole leftover
into one share, the very work the shard had failed to finish in eight
minutes on the same four cores. Pricing every mutant at the seconds of all
its covering tests cut 666 shares for three shards. And the number of
shards a run that cuts its test files makes came from `shards.max`, not
from how long its jobs may take.

## Decision

1. **A job is priced at its wall seconds.** Its first pass takes the
   seconds of its test files, each once, and of each mutant's fork at the
   likely killer the shard's handed ledger names among its covering tests,
   shared by its processes, but no less than its longest test file; its
   second pass takes, for each mutant the ledger names no likely killer of,
   the seconds of all its covering tests, shared by its processes and scaled
   by a second pass's factor, 1 until runs measure it: each child runs its
   whole test.
2. **A share is cut to fit its job at that price.** A shard's left mutants
   go in groups of a source file each, or, where a file's group does not fit
   a job alone, of a mutant each, costliest first, each to the share, of the
   latest 64, it adds the fewest seconds to and still fits; a group that
   fits no job alone takes a share of its own. Where the shares come to more
   than one matrix holds, every capacity grows by a quarter, or by the root
   of how many times too many shares there are where that is more.
3. **Each share records both passes' predicted seconds** in `leftovers.json`
   under `predicted`, so the time its job took can be set against them.
4. **A run that cuts its test files into parts makes as many shards as fit
   the target at their wall time**: its plan's cost spread over the plan
   job's processes and scaled by the second pass's factor, fitted to
   `shards.target`, ten minutes where it sets none, and to
   `shards.jobLimit`, up to as many as one matrix of jobs holds, those past
   what runs at once waiting their turn; `shards.seconds` and a learned
   `shards.max` do not bound it.

## Why no verdict moves

A price decides only which job judges a mutant and how many jobs there are;
every left mutant is still listed in exactly one share (ADR-0190), and every
mutant of a part is still judged by its covering tests as before.

## Alternatives considered

- **The test files' seconds over the processes alone**: three shares for
  three shards, each the work its shard could not finish.
- **Every mutant at all its covering tests' seconds**: 666 shares for three
  shards; the ledger's killers take most mutants in one fork.
- **A second wave that pulls left mutants from a queue until its deadline**:
  needs no price; kept for the case where priced shares still leave mutants
  unjudged.

## Consequences

- On the gate's own plan, four processes and a 10-minute target cut 54 to
  69 parts in place of 19.
- The second pass's factor stays 1 until runs record the seconds their
  passes took beside these predictions.

## Related

- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): split streams.
- [ADR-0188](0188-a-pass-queues-its-parts-costliest-first.md): a pass's parts priced by their forks.
- [ADR-0190](0190-a-shard-hands-what-it-left-to-a-second-wave.md): the second wave.
