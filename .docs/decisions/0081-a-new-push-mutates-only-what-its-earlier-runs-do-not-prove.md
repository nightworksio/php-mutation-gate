# ADR-0081: A new push mutates only what its earlier runs do not prove

**Status:** Accepted
**Date:** 2026-11-05
**Grows:** [0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the proofs a run takes; [0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md): whose proofs a run trusts

## Context

A pull request is pushed again and again, and each push reaches what the
whole pull request changed since the branch it merges into. Most of what
it reaches was judged by the push before. A run reads its own scope's
ledger beside the default branch's, and a unit whose key still matches a
proof of either stands on it (ADR-0007): the key holds everything the
verdict reads of the unit, its code and the tests that can judge it among
them, so a unit whose key has not moved since the earlier push stands on
that push's proof, and only the units whose key moved are mutated again.
The default branch and a merge group take none of a pull request's own
proofs (ADR-0022, ADR-0049). Nothing let a team turn this off, and the plan
did not say how much of a push its earlier runs carried.

## Decision

1. **A run takes its own scope's proofs by default.** A unit whose key
   matches a proof its own scope's earlier runs left stands on it, as it
   does on the default branch's, and a unit those runs carried is carried
   again where its source is the same (ADR-0060).
2. **`proofs.ownScope` turns it off.** `false` has the run read its own
   scope's ledger without its proofs: what it learned of timings, kills,
   analysers, survival, the limits and its runs stays, and every unit the
   run reaches runs, or carries the default branch's result. The setting
   affects results, so it is part of a proof's key, and turning it off moves
   every key once. The default branch reads no other scope either way.
3. **The plan says how many units the scope's earlier runs stand for.** It
   adds one line to its reasons, the units proved by key and carried from
   its own scope together, and names the setting that runs them again. A
   plan the scope's earlier runs stand for nothing of says nothing.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Read the change since the pull request's previous push, and carry everything else** | A unit whose key moved because the branch it merges into moved would carry a result of other code, which ADR-0060 refuses. The key already finds what moved, whatever moved it. |
| **Leave own-scope proofs always on** | A team that wants every push judged whole, such as one whose suite is not deterministic, had no way to ask for it. |
| **Keep the switch out of the key** | A proof made reading none of the scope's proofs, and one made trusting them, would share a key and stand in for each other. |

## Consequences

- A re-push runs only the units its change since the earlier push moved,
  and the plan names how many it did not run.
- A config that sets `proofs.ownScope` moves every proof key once.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the key and the ledgers
- [ADR-0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md): a merge queue trusts no pull request's own proofs
- [ADR-0060](0060-a-result-carries-only-for-the-code-it-is-of.md): a result carries only for the code it is of
