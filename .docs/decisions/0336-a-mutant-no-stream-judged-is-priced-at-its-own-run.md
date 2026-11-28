# ADR-0336: A mutant no stream judged is priced at its own run

**Status:** Accepted
**Date:** 2026-11-25
**Amends:** [0196](0196-a-job-stops-at-its-target-less-what-it-measured.md): how a left mutant is priced, and what a result's mutation step holds
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): a run lists the own runs its streams leave

## Context

A stream forks in no test that holds a file or a pipe open for writing, a
socket of its own, or a process of its own (ADR-0117). A mutant such a
test covers, and that no stream killed, runs a run of its own, narrowed to
its covering tests. A project whose tests start processes has many.

A job that stops hands what it left to a second wave, whose shares are cut
by price (ADR-0190, ADR-0196). Every left mutant was priced as a second
pass forks it: its covering tests' seconds, scaled by the factor recent
jobs measured their second passes at. Streams take a small part of what
forking every mutant at every covering test would, so that factor is far
below one. An own run is not a fork in a stream: it starts a process and
runs the mutant's covering tests whole. Priced at the streams' factor, the
mutants a shard left for their own runs came to one share, which stopped
with nearly all of them unjudged.

## Decision

1. **A run lists its own runs as they start.** Once its second pass has
   ended, a run of split streams lists, beside its passes, how many mutants
   its streams leave for a run of their own, where there is any. The gate
   reads it as a step within the mutation step, `own runs`, from the second
   pass's end to the mutation step's end, with that count.
2. **A left mutant's own run is priced at what the job's own runs took.**
   Where a shard's result holds `own runs`, each mutant it left before its
   own run finished is priced, for the second wave, at the wall seconds of
   that step over the own runs that finished, which are those it started
   less those it left unrun, as its job's processes hold them; no pass's
   factor scales it. Where none finished, it is priced at its covering
   tests' seconds. A mutant left any other way, and every mutant of a shard
   whose streams did not come to their end, is priced as before.
3. **A share's time counts from when its shard's own runs began.** A share
   streams its mutants before their own runs, as its shard did, so the
   seconds it has to judge in are its job's limit less the time its shard
   took to come to its own runs, where it came to any.

## Why no verdict moves

A price decides only how many shares there are and which job judges a
mutant. Every mutant is judged by the same tests, in the same way.

## Alternatives considered

- **Pricing an own run at its covering tests' seconds alone.** An own run
  takes more: a process starts, and tests that start processes of their
  own run slower beside others. It is the price where no own run finished.
- **A fixed factor for own runs.** It would be right for one project on
  one runner. A job measures its own, as it measures its second pass's.
- **Leaving the mutants for a later run.** A run that cannot judge what it
  planned has no verdict.

## Consequences

- A run whose tests start processes cuts more shares for its second wave,
  as many as its own runs need, within the most jobs a matrix holds.
- A result's steps hold `own runs` where a run's streams left any.
- A share streams the mutants it is handed again before their own runs.
