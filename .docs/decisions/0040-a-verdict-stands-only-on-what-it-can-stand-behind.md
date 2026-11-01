# ADR-0040: A verdict stands only on what it can stand behind: proofs of its own kind, its own plan and shards, floors never lower than written, and could-not-judge for whatever it could not finish

**Status:** Accepted
**Date:** 2026-10-31
**Amends:** [0003](0003-a-floor-only-rises.md): its lowering and truncated floors
**Amends:** [0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): its ledger of format 3
**Amends:** [0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): its truncated `minMsi`

## Context

What holds, read at source before this decision:

- **A proof records only its kill matrix.** ADR-0021 item 20 says a
  `--security` run's results "never stand in for a whole unit's proof", and
  its proof key honours that, since the key holds the narrowing. Carrying
  does not: a unit a change does not reach carries the newest proof of its
  path whatever its key (ADR-0005), and so does the newest result that
  pruning, sampling, risk order and `baseline --write` read. A scheduled
  `--security` run on the default branch writes proofs that hold security
  mutants alone, and the next pull request's unreached trees are scored on
  them. A `--suite` run does the same with one suite's tests.
- **A command the gate could not finish exits 1.** Symfony's console exits 1
  for a command line it cannot read and for an exception, and 255 for an
  error. Exit code 1 is a failed verdict (ADR-0009), and the action reads it
  as `failed`.
- **The verdict follows any plan.** Every shard refuses a plan made on
  another commit (ADR-0006); the verdict reads the trees, floors and sources
  of whatever is checked out.
- **A shard's result is checked only against the plan's digest.** A result
  left in the place of another shard is read as that shard's, and the units
  the plan gives the shard then hold no mutant.
- **A pull request's lowered floor is checked at the path its own config
  names.** Where the default branch keeps its baseline elsewhere, the
  pull request's file has no base to be checked against (ADR-0003 item 7).
- **A floor is truncated to hundredths.** ADR-0003 writes a measured score
  truncated, so that a floor is never raised past what was achieved. A
  declared floor is truncated the same way, so `79.999` holds a score to
  79.99: a floor lower than written.

## Decision

1. **A proof records the kind of the run that made it**: its kill matrix,
   whether it made mutants with the security mutators alone, and the one
   suite whose tests alone judged them. A proof stands only for a run of its
   own kind: proving, carrying, the newest result a plan reads, sampling
   estimates, pruning, risk order and `baseline --write` read only proofs
   of the run's own kind. The ledger is format 4, which writes `security`
   and `suite` beside `matrix`. A ledger of another format is not read: its
   proofs do not say what made them, and an unread ledger costs a run and
   never a verdict (ADR-0007).
2. **A command the gate could not finish exits 2, could-not-judge.** That is
   a command line it cannot read, a command it does not know, and a failure
   it did not anticipate, each printed as before. Exit code 1 is a failed
   verdict and nothing else.
3. **The verdict follows only its own commit's plan**, as every shard does.
4. **A shard's result stands only in its own place.** A result that names
   another shard, or holds other units than the plan gives its shard, cannot
   be judged.
5. **A pull request is checked against the floors the default branch keeps,
   in the file the default branch's own config names.** The base's config
   is read as the base holds it; a base that holds no config file keeps its
   baseline in the standard file. A lowering reason that is blank space is
   no reason. ADR-0050 amends this: the floors are those of the branch the
   pull request merges into, where its CI names it.
6. **A declared floor is held in hundredths, rounded up**, wherever it is
   read: the config, a package's `composer.json`, the baseline and an
   imported Infection `minMsi`. `79.999` holds a score to 80. A floor that is
   not a finite number is refused. A measured score stays truncated, so a
   floor raised to it is never above what was achieved.

This amends ADR-0003 item 7 and its floors of two decimals, truncated;
ADR-0007 item 3's ledger of format 3; and ADR-0016 item 2's `minMsi`,
truncated.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep narrowed runs' proofs out of the ledger** | A second `--security` run would then prove nothing it had proved before, and the audit would pay for every unit again. |
| **Read old ledgers, taking each proof for a full run** | A ledger written before this records no kind, so a narrowed proof in it would still stand in for a full one. One full run is the cost of not trusting it. |
| **Refuse a pull request that moves the baseline** | A move is a legitimate change; reading the base's own config checks it as any other change. |
| **Refuse a floor with more than two decimals** | Rounding up keeps every config that reads today, and never makes a floor looser than written. |

## Consequences

- The first run on a ledger of format 3 judges every unit again.
- A CI that read exit code 1 as any problem now reads 2 for a command line it
  cannot read; both fail the job.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): floors, the baseline and lowering
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): plans and shards
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): proofs and the ledger
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): exit codes
- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): importing Infection's config
- [ADR-0021](0021-mutators-are-written-once-and-first-party-sets-can-leave.md): `--security`
- [ADR-0025](0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md): `--suite`
