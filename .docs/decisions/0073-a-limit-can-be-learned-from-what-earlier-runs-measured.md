# ADR-0073: A limit can be learned from what earlier runs measured

**Status:** Accepted
**Date:** 2026-11-04
**Grows:** [0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): a mutant's limit; [0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the most shards a plan cuts

## Context

A mutant's limit is three times the start-up a shard measured, plus three
times its covering tests' own time, kept between `timeouts.seconds` and
`timeouts.most` (ADR-0008). Both the floor and the multiple are fixed numbers a
team picks once: a floor too high keeps every mutant that loops running for
seconds no test needs, and one too low cuts a slow runner's kills short. The
most shards a plan cuts, `shards.max`, is a number too, though what a
repository's CI can run at once shows in every run's shard start times.

## Decision

1. **`timeouts.seconds`, `timeouts.multiple` and `shards.max` each take a
   number or `"learned"`.** Each is learned on its own, so a team learns
   the floor alone, the multiple alone, or any of them. A learned limit is
   opt-in; every default stays a number.
2. **A learned floor is three times the start-up each shard measures**,
   the multiple the standard limit lays on the start-up, at least one second
   and at most `timeouts.most`. A shard that measures no start-up uses 10 s,
   the standard floor.
3. **A learned multiple is what 99 in 100 kills took.** The verdict keeps,
   for each mutant a test killed whose run and covering tests were both timed,
   its run's time over its covering tests' own time by the map its shard was
   handed: the newest 2,000 in the ledger's `kills` section. The plan reads
   them from its own scope's ledger, or else the default branch's, takes the
   99th percentile, kept between 2 and 10, and hands it to every shard in the
   plan file's `learnedMultiple`. A ledger with no kills uses 3, the standard.
   `timeouts.multiple` itself, 3 unless written, is a setting that affects
   results and is part of a proof's key; the learned value is not, so a key
   does not move as the ledger learns.
4. **A learned `shards.max` is how many shards recent runs ran at once.**
   The verdict of a run cut into shards keeps the most of them that ran at
   once, each from its start to its end, in the ledger's `concurrency`
   section, the newest 10. The plan reads the default branch's alone, by
   `shards.learnedFrom`, `max` by default or `median`, kept between 1 and 20.
   A ledger with no sharded run uses 20, the standard. `--shards=<n>` is cut
   as asked.
5. **The plan says what it learned.** It prints one line for each learned
   limit: the value, what it was read from, and the fixed value it replaces,
   or that it took the fixed one because the ledger holds nothing yet.
6. **The sections are optional, and the format does not count them.** A
   ledger without `kills` or `concurrency` learned nothing of them, so a
   ledger written before reads as cold for the learned limits and keeps its
   proofs; losing them costs speed, never a verdict, as the `analysers` and
   `survival` sections do.
7. **The gate learns all three for itself.** Its own config sets
   `timeouts.seconds`, `timeouts.multiple` and `shards.max` to `"learned"`.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Learn the floor from the ledger's openings** | A shard measures its own start-up before its first mutant, on the machine it runs on, which is the one its limits are for. |
| **Learn the multiple from every kill ever made** | A suite that got faster or slower keeps paying for its old kills; the newest 2,000 follow it. |
| **Key the learned multiple** | Every run would move every key, and no proof would carry. |
| **Read concurrency from a pull request's own ledger** | Main and merge groups trust nothing a pull request wrote ([ADR-0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md)). |
| **Bump the ledger's format** | Every ledger of the old format would be refused whole, its proofs with it, to learn two limits that a missing section already reads as unknown. |

## Consequences

- The gate's own floor moves from its fixed 30 s to three times each shard's
  start-up, and its `timeouts` config moves every proof key once.
- A run with no ledger behaves as it did, with the standard floor, multiple
  and most shards.
- Each verdict's ledger grows by at most 2,000 numbers and 10 counts.

## Related

- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): a mutant's limit
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the cut
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): `shards.max` and a target
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the ledger and the proof key
