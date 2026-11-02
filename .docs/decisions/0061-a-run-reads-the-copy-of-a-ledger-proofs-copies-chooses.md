# ADR-0061: A run reads the copy of a ledger `proofs.copies` chooses

**Status:** Accepted
**Date:** 2026-11-03
**Amends:** [0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): its `fetch`, which writes the store's ledger where the directory store reads it

## Context

Where a store is named, the reusable workflow holds two copies of the
default branch's ledger. The verdict saves one in the Actions cache, and the
deliver job writes the other to the store after it. `fetch` wrote the
store's copy over the cache's, so the store's was always the one read.

A run cancelled between its verdict and its deliver job leaves the cache's
copy the newer. On 2026-11-02, run 41498218361's verdict saved its ledger in
the cache at 19:06 and its deliver job was cancelled a minute later. The
store's copy was the one deliver wrote at 16:57. Each of the six runs on
`main` after it read the 16:57 copy, and so did every pull request.

The same two copies arise with every store kind `fetch` reads (S3, Cloud
Storage, Azure Blob Storage), and in any CI that caches the directory store
beside a store.

## Decision

1. **`fetch` writes the store's copy into `.mutation-gate/fetched`**, or the
   directory `--to` names, and no longer over the copy the run keeps.
2. **A run reads both copies, and `proofs.copies` chooses**, whatever kind
   of store the run keeps its ledgers in and whatever kind `fetch` read:
   - `newest`, the default: the copy that records the later moment, of its
     proofs' runs, its timings and the commit that last passed. Where
     neither is later, the store's.
   - `together`: the store's copy. The reusable workflow then saves no
     copy of the default branch's ledger from the verdict. The deliver job
     saves the ledger it wrote to the store in the cache, so the two copies
     move together, or neither does.
   - `merged`: both copies read as one. Every proof either holds is kept,
     and the runs are the later copy's.
   A copy that cannot be read is judged without, with a warning, and the
   other is read. Where `fetch` wrote no copy, the kept one is read.
3. **Only the store the run keeps its ledgers in is written.** The fetched
   copy is read and never written.
4. **A local run's own ledgers keep their rule** (ADR-0010): they are read
   together with the store the config names, since they hold another
   machine's runs, not a copy of the same ledger.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep the store's copy always** | A cancelled deliver job leaves the cache's newer copy unread, and the next runs judge again what it proved. |
| **Choose between the copies in the workflow** | The workflow cannot read a ledger, and the same choice is needed by any CI that caches the directory store beside a store. |
| **Record when each ledger was written** | It changes the ledger's format, which costs every project one full run (ADR-0040). What a ledger already records says which copy is newer. |

## Consequences

- With the default, a run cancelled after its verdict no longer leaves the
  next runs on an older ledger.
- `together` makes a cancelled deliver job cost the cache's copy too, so
  both stay at the run before.
- A pipeline that ran `fetch` and then the gate keeps working: the gate
  reads `.mutation-gate/fetched` beside its own store.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): `fetch`, the stores and the Actions cache
- [ADR-0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): a local run's own ledgers
- [ADR-0040](0040-a-verdict-stands-only-on-what-it-can-stand-behind.md): the ledger's format
