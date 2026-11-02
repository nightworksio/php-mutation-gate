# ADR-0060: A result carries only for the code it is of

**Status:** Accepted
**Date:** 2026-11-03
**Amends:** [0005](0005-what-a-change-reaches-is-what-is-mutated.md): its unit the change does not reach, which carries its newest result

## Context

ADR-0005 has a unit the change does not reach carry its newest result from
the ledgers the run reads. The result was checked against the code on disk
in two cases only: a unit a `last-run` change reads only from its own
scope (ADR-0005), and a kill a time budget left unjudged (ADR-0008).

The default branch's ledger is as new as its last run that finished. A pull
request's change is read from where its base branch stands now. Where the
default branch had moved past its ledger, every unit it changed in between
was reached by no change and carried the result of its code before the
change. On 2026-11-02, seven runs on `main` in a row were cancelled before
they delivered a ledger, so pull requests in that time carried results of
code `main` had since changed.

## Decision

1. **An unreached unit carries a result only of its source as it is now.**
   It carries the newest result, of the ledgers the run reads, whose
   digest of the unit's source is the digest the run takes now. Where no
   result is of that source, the unit is reached and runs. A result that
   records no digest of its source carries for no unit.
2. **Only the source is compared.** A result's digest of what decides
   every unit's mutant set (the gate, the config, the runner, what is
   installed) is not compared here. Proving compares it through the key
   (ADR-0007), and ADR-0030 has a lock file reach only what its moved
   packages reach. Comparing it here would run every unit after any such
   change.
3. **The plan and the verdict carry by the same rule.** The plan records
   the source digest of every unit it carries. The verdict reads the
   ledgers again and carries each of those units the newest result of
   that source.
4. **A unit a `last-run` change reads only from its own scope keeps its
   rule:** its own scope's result carries only where it records both the
   unit's source and its mutant set as they are now.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Compare the mutant set's digest too** | Every lock-file or config change would run every unit, which ADR-0030 narrows. |
| **Ask git what changed since the commit each result records** | A result records its commit only where the working tree held nothing more, and git may no longer hold it. The digest says what the result is of without either. |
| **Carry the newest result whatever it is of** | A pull request then judges code the default branch has changed with the result of the code before. |

## Consequences

- A pull request whose base is newer than the default branch's ledger
  runs the units the default branch changed since that ledger.
- A result from before its unit's last change no longer carries. The
  default branch's next run that finishes judges the unit again.
- The plan holds one source digest for each unit it carries.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): the carry this amends
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the digests a proof records
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): the unjudged kill, carried by the same digests
- [ADR-0030](0030-a-lock-file-decides-by-the-packages-it-moved.md): what a lock file reaches
