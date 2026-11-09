# ADR-0137: A mutant that is the same program as another is judged once

**Status:** Accepted
**Date:** 2026-11-09
**Grows:** [0025](0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md): decision 13, mutants that leave their file alike, by mutants a rule or their opcodes prove alike, and a setting for all of them

## Context

ADR-0025 decision 13 judges mutants of one file whose mutated files hold the
same bytes once: under Pest with `pest.patch`, the patched generation loop
keeps a twin out of Pest's run (`src/Adapter/Pest/Patch.php:424-425`), and
the PHPUnit runner runs only the first (`src/Adapter/Native/Twins.php`).
Infection runs every mutant. A twin keeps its own id, mutator and place in
every report and counts in the score as its first does. Nothing turns it off.

Two mutants can be one program without being one text. On the 45,345 mutants
of run 42494457581:
- 49 groups of mutants share their mutated text at one site, 49 mutants
  beyond the first of each (0.11%), which decision 13 judges once already;
- 418 sites hold `if (!E)` mutated both by `RemoveNot`, to `if (E)`, and by
  `IfNegated`, to `if (!!E)`, and one ternary's condition `!E` is mutated
  alike by `RemoveNot` and `TernaryNegated` (0.92% in all).
  Both branch on the truthiness of `E`, evaluated once, for every value of
  `E`.

Together that is 468 mutants, about 1% of a run's mutants and of its mutation
step. A mutant that compiles to the same op arrays as another mutant of its
function is the same program again; `outbreak.so` compares op arrays before a
run (ADR-0130).

## Decision

1. **`mutants.duplicates` says whether mutants that are one program are
   judged once:** `judge-once`, the default, or `judge-each`. It affects
   results and is in key item 3 (ADR-0007).
2. **Under `judge-once`, three proofs make two mutants one program**, each
   only between mutants of one file:
   - **the same file**, as ADR-0025 decision 13 decides;
   - **a rule**, from a table the gate ships, each rule naming two mutators,
     the shape of the original they apply to, and why every value gives both
     the same result. The first rule is a negated condition: `RemoveNot` and
     `IfNegated` on the condition `!E` of an `if`, and `RemoveNot` and
     `TernaryNegated` on the condition `!E` of a ternary, where both mutants
     test `E`'s truthiness once;
   - **the same op arrays**, where `outbreak.so` is loaded: two mutants of one
     function whose op arrays and declarations are alike by ADR-0130's
     comparison.
3. **The first of a group is judged, and every other takes its judgement**:
   its status, killers, limit, reason and evidence, judged in no time, as a
   twin is. The first is the one made first. A rule's twin is recorded served
   as the copy of the mutant that judges it. Under split streams only the
   first mutant's switch forks.
4. **Every mutant stays.** Each keeps its id, mutator and place in every
   report, and counts in the score as its first does (ADR-0025).
5. **Under `judge-each`, every mutant is judged by its own run**, twins of
   one file among them.
6. **Infection keeps running every mutant**, as ADR-0025 says, whatever the
   setting.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `mutants.duplicates` | `judge-once`, `judge-each` | `judge-once` | affects results (key item 3) |

### Why a shared judgement is the mutant's own

- **One program gives one answer.** Two mutants that are the same file, the
  same op arrays, or the same value at the same point for every input fail
  and pass the same tests.
- **A rule is held to its claim.** Each rule ships with a property test that
  evaluates both mutated expressions over generated values (integers, floats,
  strings among them `"0"` and `""`, arrays, `null`, objects) and requires
  the same truthiness and the same calls in the same order.
- **What no proof covers is judged alone.** A pair a rule does not name, or
  whose original does not have the rule's shape, runs as two mutants.

### Proof before it ships

- The property tests of each rule.
- One run of the gate's own suite with `judge-each`, in which every group
  `judge-once` would form agrees on its status.

### Where today's path stays

`judge-each`, Infection, and every mutant no proof pairs are judged by their
own runs. Without `outbreak.so` the op-array proof makes no group.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Drop one mutator of each pair** | Two mutators alike on one shape differ on others (ADR-0025). |
| **Leave the duplicate out of the score** | The score's denominator would move with which mutators happen to agree (ADR-0025). |
| **Find pairs by running both once and comparing** | It spends the run it means to save. |
| **Group mutants by their subsumption** | Subsumption is a prediction of a kill (ADR-0079), not the same program. |

## Consequences

- About 1% of a run's mutants are judged in no time, beside the twins
  already judged so.
- One setting turns twins off, with every other duplicate.
- A report names, for each duplicate, the mutant and the proof that judged
  it.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): the score
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): key item 3
- [ADR-0025](0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md): twins
- [ADR-0079](0079-a-local-run-infers-what-a-killed-mutant-subsumes.md): subsumption
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): split streams
- [ADR-0130](0130-a-mutant-that-compiles-to-its-original-is-equivalent-and-is-never-run.md): op arrays compared before a run
