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
4. **`shards.confirm` still changes when a kill is vouched for, and never
   its status.**

## Consequences

- A job judges slightly fewer mutants in its own run, and leaves far fewer
  kills unjudged.
- A mutant its own run did not reach is left to the wave after, as before.
