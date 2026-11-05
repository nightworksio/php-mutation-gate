# ADR-0089: One replay to the end of a whole order vouches for every prefix of it

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0014](0014-every-test-is-judged-by-what-it-kills.md): a narrowed Pest kill replayed by itself, stopped after its last failing test; [0036](0036-a-kill-only-errors-made-counts-where-its-control-passes.md): the same for a kill only errors made

## Context

Under `pest.patch`, a mutant's own run loads only the test files its
covering tests need. A kill such a run made stands only where a replay of
the run, on the unmutated code served as its mutant was, in the order the
run took, passes up to its last failing test (ADR-0014, ADR-0036). One
replay runs for each file and order up to the killer, so mutants of one
file whose runs took the same order but were killed at different tests each
need a replay of their own.

On run 42036642441, shard 3's tail spent 114 s on these replays: 899
replays over 105 files. Each run's records kept only the digest of its order
up to its killer, so which replays were prefixes of one another could not be
told.

## Decision

1. **Each mutant's own run records the whole order it is to take.** As
   PHPUnit begins the run, once it has ordered the tests, Pest's plugin
   writes how many tests the run is to run and the digest of their order
   (the same digest a killer's position carries). A run that recorded none,
   or a copy two runs recorded, has no whole order.
2. **The narrowed kills of one file whose runs were to take one whole order
   share one replay to its end.** Where two or more of a file's replays
   belong to runs that were to take the same whole order, the adapter first
   replays that order to its end, allowed the longest limit of theirs. Every
   test of the order passing unmutated, in that order, vouches for each of
   them: each kill's replay is a prefix of it.
3. **Only a replay no whole replay vouched for runs on its own.** A replay
   whose whole order no other shares, one with no whole order, and each of a
   group whose whole replay did not stand run as before, all side by side.
   A replay's verdict, and what it means for its kill, are unchanged.

### Why a whole replay stands for each prefix

- **The same order.** A group's replays come from runs that were to take
  one whole order, by its digest, and each run took its tests in it up to
  its killer, so each replay's order is a prefix of the whole.
- **The same code, the same tests.** A whole replay serves the file
  unmutated as each replay would, and runs the run's command, with the same
  narrowed test files and filter.
- **What a prefix replay checks, the whole one checks too.** A prefix
  replay stands where each test up to the killer passes after the ones
  before it. In a whole replay that stands, every test passes after the ones
  before it, those up to each killer among them.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep checks in the ledger across runs** | A check reads everything its unit's key reads, so a kept one is found only where that key is unchanged, which leaves the unit to its proof wherever the gate's build is not keyed. |
| **Group by the files a run loaded alone** | Under `tests.order: killers-first` each run orders its tests by its own mutant's killers, so runs of the same files can take different orders. |
| **Replay the whole order for every replay, even alone** | A whole replay runs every test of the run, where a lone prefix replay runs only those up to its killer. |

## Consequences

- A file with several kills in runs of one order pays one replay, past the
  last killer, in place of one replay per killer position.
- A whole replay that does not stand costs that replay as well as the
  replays it did not vouch for.

## References

- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): the evidence of a kill, and its replay
- [ADR-0036](0036-a-kill-only-errors-made-counts-where-its-control-passes.md): a kill only errors made
- [ADR-0070](0070-the-verification-tail-forks-from-warm-workers.md): replays fork from warm workers
