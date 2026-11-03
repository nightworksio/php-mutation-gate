# ADR-0059: Every run on the default branch finishes, and writes its ledger

**Status:** Accepted
**Date:** 2026-11-02

## Context

Only a verdict writes the ledger (ADR-0007), and a shard writes no proof
of its own. A run cancelled before its verdict and its deliver job
therefore leaves the default branch's ledger as the run before it left it. The next run then
reads its change since that ledger's last passed commit (ADR-0005) and runs
the cancelled run's units again.

`ci.yml` cancelled the run of a push to `main` once the next push came, so
`main`'s ledger fell behind while merges came faster than a run took:

- On 2026-11-02, seven runs on `main` in a row were cancelled after their
  plans. Their shards spent about 1,974 minutes, and none delivered a
  ledger. Run 41522212981 had finished 19 of its 20 shards when its verdict
  was cancelled.
- ADR-0040 made the ledger format 4, so the first run on a ledger of format
  3 judges every unit again. That first run had to finish before any pull
  request could carry a result. The run of #488, which made the change, was
  cancelled, and so was the next. The first ledger of format 4 was written at
  05:51 on 2026-11-01, five hours after #488 merged. Meanwhile #503, which
  reached nothing, ran all 2,160 units: 420 shard-minutes. Two other pull
  requests that reached nothing did the same.

## Decision

1. **A run on the default branch is never cancelled by a newer push.** In
   `ci.yml`, `cancel-in-progress` is true only for `pull_request`. A pull
   request pushed again still cancels the run on its old head, which nobody
   reads.
2. **Every run on the default branch writes its ledger.** Its plan, shards,
   verdict and deliver job run to the end. A newer push waits until the
   run before it has finished. Of several pushes waiting, GitHub keeps the
   newest.
3. **A change of the ledger's format still costs one full run**, as
   ADR-0040 says, and that run finishes. The format is not read across
   versions, so pull requests in the meantime still run their whole trees.
   Item 1 limits this to the time one full run takes.
4. **A project is told the same.** The GitHub Actions guide's examples and
   the workflows `init --ci=github` writes (ADR-0015) set the concurrency
   group `ci.yml` sets: a pull request's runs cancel those on its older
   heads, a push to the default branch waits for the run before it, and
   every other run is a group of its own.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Cancel the other jobs of a push to `main`, and let `mutation` run on** | A run is cancelled whole, so this needs a concurrency group on every other job. It saves little: those jobs take minutes, and `mutation` takes hours. |
| **Let each finished shard write its proofs** | Only the verdict knows whether a result stands (ADR-0040), and the store's write credentials stay in the deliver job (ADR-0039). |
| **Read a ledger of the previous format** | A proof of format 3 does not record its run's kind. A `--security` or `--suite` run's proof would then stand for a whole unit again, which ADR-0040 closed. |

## Consequences

- A run on `main` waits for the one before it, so the runner minutes a push
  saves by cancelling are spent instead. In exchange, the next run carries
  the results of the one before it.
- A change of the ledger's format costs pull requests one full run on
  `main`, and no more.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): the change since the last passed commit
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the verdict as the only writer of the ledger
- [ADR-0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): the workflows `init` writes
- [ADR-0040](0040-a-verdict-stands-only-on-what-it-can-stand-behind.md): the ledger of format 4, and the run another format costs
