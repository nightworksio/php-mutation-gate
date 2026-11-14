# ADR-0131: Proofs are shared across scopes by their keys

**Status:** Accepted
**Date:** 2026-11-09
**Amends:** [0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): decision 4, what a run reads beside its own scope's ledger and the default branch's
**Grows:** [0059](0059-every-run-on-the-default-branch-finishes-and-writes-its-ledger.md): where a default-branch run's verdict keeps its proofs, by the shared cache

## Context

A proof is a unit's raw result under a key of everything that result could
depend on: the base (key items 1 to 7: the key's format, the gate's verdict
code, the config
that affects results, the runner and its PHP, what is installed, the files
no map shows run and what every key reads of the tests), the unit's judging
test files, the files it reaches, and its covered lines with their tests
(ADR-0007, ADR-0084). The same key is the same question, whoever asks it.

Where proofs are kept follows scopes instead. A ledger holds one scope's
proofs; a run reads its own scope's and the default branch's, and writes its
own (ADR-0007 decision 4). Under the Actions cache a run restores only its
own ref's entries and the default branch's, and a fork's run writes only its
own merge ref's (ADR-0013). A ledger keeps the proofs of its five most recent
bases and at most 20,000 of them (`proofs.keep.bases`, `proofs.keep.proofs`).

So two pull requests on one commit of the default branch each compute every
unit the other already proved; a branch's push computes what its pull request
computed; and a pull request opened after its base's ledger moved past five
bases computes what an older ledger held. On the gate's own repository 26
runs were planned between 2026-11-06 22:12 and 2026-11-08 15:22, 21 of them
with 10 or more shards. How often two scopes compute one key is not measured.

ADR-0059 keeps the rule that only a verdict writes proofs: a shard cannot
know whether its result stands (ADR-0040), and the store's write credentials
stay in `deliver` (ADR-0039).

## Decision

1. **`outbreak cache` keeps proofs by their keys, beside the ledgers.** An
   entry is one proof under its key, written whole as a ledger of that proof
   alone, so it names its own mutators and tests and reads as a ledger's
   proof reads. Entries are packed by base: a pack is a gzipped text whose
   first line names its class of writer and its base, and whose every other
   line is one entry, since a key only ever matches a proof of its own base
   (ADR-0007). The binary merges packs by key without reading a proof. A run
   reads at most one pack of each class it trusts.
2. **`outbreak.cache.store` says where the packs live:** `auto`, the
   default, `none`, `actions` or `oci`.
   - `auto` is `oci` where `outbreak.cache.registry` names a repository,
     `actions` under the package's action and reusable workflow, and `none`
     elsewhere.
   - `actions` keeps a pack as an Actions cache entry named
     `mutation-gate-proofs-<class>-<run id>-<attempt>`. The plan job
     restores the newest entry of each class under its prefix, and the
     binary takes a restored pack only where it holds proofs at the run's
     base. The verdict job restores the newest pack of the class it writes,
     merges its entries into it and saves the merged pack, since the cache
     service is reachable only from inside an action (ADR-0007). GitHub's
     scoping then decides reach: a run reads its own ref's packs and the
     default branch's, and a pull request its base branch's too.
   - `oci` keeps a pack as an OCI artifact in the repository
     `outbreak.cache.registry` names, none by default, tagged
     `<class>-<base>`, the newest pushed tagged `<class>` too. Packs move
     only through the registry the workflow names in its own
     `MUTATION_GATE_REGISTRY` variable, a plain `host[:port]/path`, and
     only where the config names the same one: no file a run wrote, and no
     config of a change, picks where a token goes. `fetch`, which runs none
     of the project's code, pulls each class's newest pack before the plan,
     with the job's token on any run but a pull request's and without one
     there; the plan takes the packs from what `fetch` left and reaches no
     registry. Every scope of the repository reads every pack of its base.
   - `outbreak.cache.store` and `outbreak.cache.registry` decide how the gate
     runs (ADR-0007): neither is in the key.
3. **Only what the verdict accepted is written, where its ledger is.** The
   verdict leaves the entries of the proofs it wrote into its ledger beside
   that ledger in its delivery (ADR-0007). Under `oci`, `deliver`, which runs
   none of the project's code, merges them into the pack of the run's class
   and base, in the workflow's registry alone and held to the gate's own
   limits, whatever the delivery's request says; under `actions`, the step that keeps the run's ledger in the
   cache saves the pack in the run's own scope, which GitHub keeps apart.
   No shard and no plan writes. A write that fails, or that a later write
   replaces, costs a run later, never a verdict, as two verdicts writing one
   scope do.
4. **Two classes of writer, each its own pack.**
   - `trusted`: a push, schedule or dispatch on the default branch, with the
     credential the store keeps for the default branch (the
     `mutation-gate-store` environment, ADR-0029).
   - `branch`: any other run of the repository that holds a write
     credential: a branch's push, or a pull request from the repository
     itself. Under `oci`, `deliver` holds `packages: write` on those runs.
     Under `actions`, the class is the scope: the default branch's scope
     holds `trusted` packs, every other scope `branch` packs.
   - A pull request from a fork writes nothing. Its token holds no write to
     packages, it holds no store credential (ADR-0013 decisions 14 and 15),
     and `deliver` saves nothing for it.
5. **Who reads which class.**
   - Every run reads `trusted` packs.
   - A pull request, a fork's among them, and a branch's push also read
     `branch` packs.
   - The default branch and a merge group read `trusted` packs alone, as
     `outbreak.cache.trust` says: `default-branch`, the default, or
     `repository`, under which they read `branch` packs too. **`repository`
     loosens**: the default branch then takes a result a branch's own code
     computed, the trust `ci.trustMergedPullRequests` offers for a whole
     tree, here per unit. It is an opt-in under ADR-0142's rules: set in
     bold in the reference, marked by `config:show`, and, where in force,
     noted in the verdict and the comment unless `suggest.looseValues` is
     off.
   - `outbreak.cache.trust` decides how the gate runs and is in no key, as
     the store's settings are.
6. **An entry proves as a ledger's proof does, after the ledgers.** A unit
   whose key no ledger the run reads holds a proof under takes the entry
   under that key, where it stands for a run of its kind (ADR-0040). An entry
   from a `branch` pack counts as a proof of the run's own scope does
   (ADR-0081): the plan says how many units it stands for, and the verdict
   counts it in `ownScopeProofs`, so no verdict that used one is trusted as a
   merged pull request's pass (ADR-0005 rule 5). Where a run's own result
   under a key differs from an entry it read, the mutants that differ are
   flaky (ADR-0008), as between two ledgers.
7. **The binary reads and writes every pack.** `outbreak cache pull` reads
   the packs a run trusts from a registry, or from where the action restored
   them, into `.mutation-gate/cache/`, and `outbreak cache push` merges a
   delivery's entries into its pack. A pack is held to
   `proofs.limits.ledger.packed` and `proofs.limits.ledger.unpacked`. How
   long a pack lives is the store's own policy: the Actions cache evicts an
   entry nothing restored for seven days, and a registry keeps what its
   owner's retention keeps. The gate deletes nothing.
8. **One contract suite holds the binary to the ledger.** A pack the binary
   writes and reads gives back each proof exactly as the ledger's reader
   reads it, and the pack's parser is fuzzed, as ADR-0118 requires of every
   parser.

### Settings

A loose value is in bold (ADR-0142).

| Setting | Values | Default | Effect |
|---|---|---|---|
| `outbreak.cache.store` | `auto`, `none`, `actions`, `oci` | `auto` | decides how the gate runs |
| `outbreak.cache.registry` | an OCI repository | none | decides how the gate runs |
| `outbreak.cache.trust` | `default-branch`, **`repository`** | `default-branch` | decides how the gate runs |

### Why a shared proof is as good as an own one

- **The key is the question.** A proof stands where everything its result
  could depend on is what it was (ADR-0007). Which scope asked first does
  not enter the answer.
- **The trust is the store's, as before.** A `trusted` pack is written only
  by the default branch's runs, with its credential or in its cache scope, so
  under `outbreak.cache.trust: default-branch` no pull request plants a proof
  the default branch takes, as ADR-0007 decision 4 requires. A project that
  sets `repository` gives up that line for the units the cache proves, and
  its runs note it (ADR-0142).
- **A `branch` entry is a collaborator's own computed result.** Anyone who
  can push a branch of the repository can write one, for a key another pull
  request computes later. That is the trust ADR-0081 already gives a pull
  request's earlier pushes, extended to the repository's other branches, and
  it is why such an entry counts as an own-scope proof.
- **A fork plants nothing,** and reads what any reader of a public package
  reads.

### Where today's path stays

- With `outbreak.cache.store` `none`, `outbreak.enabled` off, no binary, or a
  pack that cannot be read, a run proves from its ledgers alone, as today.
- A `deliver` without the credential for its class writes no pack and says
  so once.

### Proof before it ships

- The contract suite of decision 8.
- On one commit, a pull request's run with the cache and one without it,
  both after a `trusted` and a `branch` pack were written: the verdicts are
  identical, every mutant's status is the same, and the plan lists each unit
  the cache proved.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **One ledger every scope writes** | ADR-0007 rejected it: a pull request could plant a proof the default branch takes. Classes keep that line. |
| **One Actions cache entry per key** | A run would restore thousands of entries one by one, and a prefix restore returns only the newest entry. |
| **Packs by scope** | That is the ledger; a scope's pack reaches only the runs GitHub lets read that scope. |
| **The default branch reads `branch` packs by default** | It would let any branch of the repository plant a proof the default branch takes, against ADR-0007's line; the opt-in leaves that choice to the project. |
| **Shards write their proofs** | ADR-0059: only the verdict knows a result stands, and the write credential stays in `deliver`. |
| **A client in PHP** | Outbreak's binary already speaks the gate's formats under one contract suite; a second client would need the same suite. |

## Consequences

- Under `oci`, a pull request reuses what another pull request or branch of
  the repository proved at its base; under either store, what the default
  branch proved at any base its packs still hold, not only the newest five.
- A repository that names a registry gains a package there, written by
  `deliver` alone.
- A fork's run reads what the repository proved, and adds nothing.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): rule 5, `ownScopeProofs`
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the key, the ledger and the scope rule
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): flaky triage
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): forks and the Actions cache
- [ADR-0029](0029-a-caller-passes-the-store-secrets-by-name.md): the store's credentials
- [ADR-0039](0039-the-jobs-that-hold-a-credential-run-on-a-runner-of-their-own.md): the jobs that hold a credential
- [ADR-0040](0040-a-verdict-stands-only-on-what-it-can-stand-behind.md): proofs of the run's own kind
- [ADR-0059](0059-every-run-on-the-default-branch-finishes-and-writes-its-ledger.md): only the verdict writes
- [ADR-0081](0081-a-new-push-mutates-only-what-its-earlier-runs-do-not-prove.md): own-scope proofs
- [ADR-0084](0084-the-gate-is-keyed-by-the-code-a-verdict-depends-on.md): the gate's verdict code in the key
- [ADR-0118](0118-outbreak-s-core-vouches-for-and-slices-coverage-maps.md): Outbreak's binary and its standard
- [ADR-0142](0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md): how a loose value is offered and named
