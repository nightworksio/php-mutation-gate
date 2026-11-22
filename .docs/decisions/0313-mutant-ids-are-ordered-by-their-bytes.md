# ADR-0313: Mutant ids are ordered by their bytes

**Status:** Accepted
**Date:** 2026-11-21
**Amends:** [0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md): how a cluster's id and representative are chosen; [0193](0193-a-part-makes-the-mutants-its-tests-can-reach.md): how the verdict compares the mutants each part holds

## Context

A mutant's id is twelve lowercase hex characters. Some ids read to PHP as
numbers: `105436102654` is all digits, and `06270e550940` is
6270e550940, which is INF. PHP's default comparison, which `sort()` and
`<=>` use, compares two such ids by value and an id that reads as a
number with any other id by bytes. That order is not total, so the same
ids sorted from two input orders can come out in two orders. Of the
60,620 ids of run 44911741946, 434 read as numbers.

Three places ordered ids that way:

- `MergedParts` compared the sorted ids each part of a cut run holds with
  the sorted ids of the lines it made. Equal sets came out in different
  orders, and the merge refused six of that run's 2,390 units: "hold other
  mutants of it than each other".
- `ClusterId::of` hashes its members' sorted ids. A cluster whose members
  include an id that reads as a number could get another id when its
  members arrive in another order.
- `Cluster::order` and `Clusters::order` break a tie on the same line by
  the ids with `<=>`. That tie picks a cluster's representative and orders
  the clusters.

## Decision

Mutant ids are compared by their bytes wherever the gate orders them: the
merge's sorted ids (`SORT_STRING`), a cluster id's sorted members
(`SORT_STRING`) and the tie-breaks between a cluster's members and between
clusters (`strcmp`).

## Why no verdict moves

- No mutant's status changes, and no mutant's id changes.
- The merge: two sorted lists that were equal hold the same ids, so every
  unit that merged still merges with the same statuses. The units that
  were refused, whose parts held the same ids in another order, now merge.
- Clusters: membership is unchanged. A cluster id and a representative
  change once, and only for a cluster where an id that reads as a number
  meets another id in a comparison whose default order differs from the
  byte order. From then on both are the same for every input order.

The user approved the change of cluster ids and representatives on
2026-11-21.

## Alternatives considered

- **Keep the default comparison for clusters**: a cluster could change its
  id between two runs of the same code, and a `stub` or `explain` named by
  that id would find nothing.
- **Compare ids as sets rather than sorted lists in the merge**: it cures
  the merge but leaves the cluster ids, and the sorted list is what the
  merge already documents.

## Consequences

- Cluster ids and representatives in reports, ledgers and commands change
  once for clusters with members whose ids read as numbers.

## Related

- [ADR-0307](0307-a-part-makes-a-mutant-by-the-lines-it-records.md): a part makes a mutant by the lines it records.
