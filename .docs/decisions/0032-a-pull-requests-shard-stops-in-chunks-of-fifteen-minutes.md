# ADR-0032: A pull request's shard stops in chunks of about fifteen minutes

**Status:** Accepted
**Date:** 2026-10-20
**Grows:** [0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): its chunks of two minutes

## Context

ADR-0008 runs a shard that can stop once its run cannot pass in
chunks of about two minutes of expected work. Each chunk is one runner
invocation, and each invocation pays its openings: Pest's start for the
mutation step, the survivors' second runs, the static check and the
confirmation.

Two full runs of nearly the same code on 2026-10-20 measure that price. Each
judged about 34,000 mutants, and they killed 31,700 and 31,703 of them.

- Run 39378485071, a pull request, cut 1,912 chunks across its 40 shards, and
  took 30.3 shard-hours.
- Run 39373341555, a push to `main`, which never stops early, ran each shard
  as one invocation, and took 17.9 shard-hours.

That is 12.4 hours over 1,872 more invocations, about 24 seconds each. By
step:

| Step | Pull request | `main` |
|---|---|---|
| mutation | 7.6 h | 3.0 h |
| confirmation | 9.6 h | 6.0 h |
| survivors | 2.8 h | 1.1 h |
| trials | 3.0 h | 1.6 h |
| controls | 3.5 h | 2.8 h |
| static check | 3.2 h | 3.0 h |

A pull request whose run reads the default branch's ledger (ADR-0029) mutates
only what it reaches, so a full run on a pull request is rare. When one
happens, its chunks cost about 40% of it.

## Decision

1. **A chunk is about fifteen minutes of the work the cost model expects**,
   opening run included. Everything else in ADR-0008 stands: the
   order, a held unit alone, a chunk that starts with its first unit however
   large, the check after each chunk and the stop at the first doomed
   survivor.
2. At about 24 seconds of openings per chunk, fifteen minutes keeps the
   openings under 3% of a shard. A pull request that cannot pass stops within
   about fifteen minutes of a shard's work.

This supersedes the two minutes of ADR-0008.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep two minutes** | About 40% of a full run on a pull request goes to openings. |
| **One invocation per shard on a pull request, as on `main`** | A pull request that cannot pass would then run its whole shard before it says so. |
| **A persistent Pest worker per shard (L10)** | It removes the openings without growing the chunk, but it is the largest piece of work, and the chunk's openings are now a few percent. |

## Consequences

- A full run on a pull request costs about what one on `main` does.
- A pull request that cannot pass finds out later: within fifteen minutes of a
  shard's work rather than two. The other shards stop with it, as before.

## Related

- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the cost model a chunk is measured in
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): whose chunk this grows
