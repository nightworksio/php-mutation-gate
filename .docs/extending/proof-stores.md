# Proof stores

A proof store keeps each scope's ledger, and the objects kept beside it such
as the coverage map, between runs: a directory, a bucket, a blob container. It
implements `NightWorksIO\MutationGate\Port\ProofStore`
([ADR-0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)),
is registered with `Extensions::withProofStore()`, and is chosen by
`proofs.store`.

## What the port asks

An unreadable or unwritten ledger or object costs a run later, never a
verdict, and the run says why.

| Method | What it answers |
|--------|-----------------|
| `read(Scope)` | The scope's ledger, keeping each well-formed entry and dropping the rest; an empty one where there is none yet; or `Unreadable`, why one that may be there could not be read |
| `write(Scope, Ledger)` | Keeps the ledger as the scope's own, replacing what was there |
| `companion(Scope, Companion)` | The bytes of an object kept beside the scope's ledger, read within that object's limits; `Missing` where there is none yet |
| `keep(Scope, Companion, Contents)` | Keeps the bytes as an object beside the scope's ledger |

A scope is a ref, `refs/heads/<branch>` or `refs/pull/<n>`. The gate reads a
run's own scope and the default branch's, and writes only its own, so a store
holds the line between them by its own access control: only a trusted run
may hold credentials that write the default branch's scope
([proofs and trust](../using/proofs.md)).

## Holding it to the contract

`NightWorksIO\MutationGate\Extension\ProofStoreContract` is handed the store
new, holding nothing; each of its checks reads and writes scopes of its own,
so one store serves all of them:

| Kit method | For a store that |
|------------|------------------|
| `failures($store)` | keeps what it is handed: reads an empty ledger for a scope nothing wrote, reads back what it wrote to one scope and nothing of it from another, and the map kept beside a ledger byte for byte |
| `readOnlyFailures($store)` | is opened read-only: answers each ledger and map it is handed with why it keeps nothing, and reads none of them back |
| `deliveringFailures($store)` | leaves what it writes for `deliver`: says it wrote each ledger and map, and reads what the store it reads holds, never what it wrote |
| `unreadableFailures($store)` | holds, for the branch `main`, bytes that are no ledger: says the ledger there is malformed, and hands on an empty ledger to judge with |

```php
expect([...ProofStoreContract::failures($store)])->toBe([]);
```
