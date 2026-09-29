# ADR-0003: A tree's floor only rises, is committed beside the code, and new code has a floor of its own

**Status:** Accepted
**Date:** 2026-09-29

## Context

The in-house gate holds every tree to a mutation floor of 100, and it enforces
that by invocation rather than by reading. Pest reports no score in a form a
program can read. It offers `--min`, which fails a run below a percentage. So
the in-house gate groups trees by floor, runs Pest once per group with
`--min=<floor>`, and reads the exit code. At 100 that is exact: one surviving
mutant anywhere fails, so trees and shards can be grouped freely.

Below 100 it stops working. A shard's score is not its tree's score. Two shards
at 90% and 70% say nothing about whether their tree is above 80. A project
adopting mutation testing starts well below 100, and it has three needs:

- keep what it has;
- raise the bar as tests improve;
- stop new code arriving at the level of the old.

That is a ratchet. The floor sits at what was achieved, never goes down by
itself, and goes up with the tests.

## Decision

1. **The gate computes the score itself, from every mutant's result.** Each
   runner reports each mutant with a normalised status (ADR-0004). The gate
   never uses a runner's own threshold (Pest's `--min`, Infection's
   `--min-msi`).

   | Status | In the score |
   |--------|--------------|
   | killed, errored (the mutant crashed the tests) | killed |
   | timed out, and judged a kill by timeout triage (ADR-0008) | killed |
   | survived, uncovered, unjudged, flaky; timed out or skipped and too slow to judge (ADR-0008) | not killed |
   | ignored, with a reason or by a native marker (ADR-0008) | left out |

   **Score = killed ÷ (all mutants − ignored − excluded uncovered) × 100,
   truncated to two decimals**, where "ignored" covers both kinds of ignore,
   and uncovered mutants are excluded only under `uncovered: exclude`. A set with nothing left to count has no score: no mutants, or
   every mutant ignored. It passes, and the report says *nothing to mutate*
   rather than showing 100%. "No mutants" and "every mutant killed" must never
   print the same way.

   `uncovered` decides how uncovered mutants count:
   - `count`, the default: an uncovered mutant counts as not killed.
   - `exclude`: uncovered mutants are left out of the score, as ignored ones
     are, and are still listed in every report. This suits a project that
     already holds line coverage to a floor of its own.

   Both runners always report uncovered mutants (ADR-0004), and `uncovered` is
   applied when the verdict is judged, so changing it re-runs nothing
   (ADR-0007).

   `uncovered` is about executable lines that no test covers. A mutant on a
   line that is not executable at all (a constant's value, a property's
   default, an enum case's value, a parameter's default or an attribute's
   argument) is not uncovered. The gate judges it against the tests that
   reference its symbol (ADR-0004, decision 8), and it scores as the result:
   killed, survived, or unjudged when no test reaches it.

2. **A floor belongs to a tree, and a tree is a path.** Trees come from a tree
   source (ADR-0002, ADR-0005), or from `trees` in the config: a list of
   entries, each with a `path`, an optional `floor` (a number from 0 to 100)
   and, when the floor is 0, a `reason`. In the PHP builder that is
   `Tree::at('<path>', floor: <n>, because: '<reason>')`. A tree's effective
   floor is the higher of:
   - the floor its config entry or manifest **declares**, which is a policy
     minimum;
   - the floor its **baseline** records, which is what was achieved.

   A declared floor of 0 has to carry a reason, or the run stops with exit
   code 2. That tree is not mutated, and every run prints it with its reason.

3. **The baseline is a committed file.** Its path is `baseline.path`,
   `mutation-gate.baseline.json` by default.

   ```json
   {
       "format": 1,
       "trees": {
           "app/Domain": { "floor": 100 },
           "app/Http": { "floor": 83.41 },
           "app/Legacy": {
               "floor": 61.2,
               "lowered": { "from": 64.5, "reason": "The export feature and its tests were removed together" }
           }
       }
   }
   ```

   It holds floors and nothing else: no counts, no dates, no commit. So it
   changes only when a floor moves, and two pull requests conflict on it only
   when both move the same tree. Keys are sorted and each tree is on its own
   line, so a moved floor is a one-line diff. The file is committed rather than
   stored with the proofs (ADR-0007) because a floor moving is a decision, and a
   reviewer should see it. In a monorepo, one baseline at the root holds every
   package's trees, keyed by their path from the root (ADR-0005).

4. **A tree is judged whole.** Its score is taken over every unit in it (a unit
   is a file or a held path, ADR-0005). Each unit's result comes from one of
   three places:
   - this run;
   - a proof in the ledger whose key still matches (ADR-0007);
   - in a change-scoped run, for a unit the change does not reach (ADR-0005),
     the newest result for that path in the ledgers the run may read: its own
     scope's and the default branch's (ADR-0007). This is a *carried* result.

   A unit with none of these is treated as reached and is mutated. So every
   tree the gate judges is judged over all its code, and sharding (ADR-0006)
   decides only where a mutant runs, never how a tree's score is added up. The
   one exception is a run cut short by a time budget (ADR-0008). Its unjudged
   mutants count as not killed, and it never raises a floor.

5. **A score below the effective floor fails the run** (exit code 1). The
   message names:
   - the tree, its floor and its score;
   - the surviving mutants, ordered with those on changed lines first.

6. **A score above the baseline floor raises it.**
   - `mutation-gate baseline` shows every tree's floor beside its last measured
     score. `mutation-gate baseline --write` sets every improved floor to its
     measured score. A local full run (`mutation-gate` with no arguments, with
     the `CI` environment variable unset) does the same and says which lines to
     commit.
   - In CI, `baseline.improvement` decides what an improvement does:
     - `require` is the default: a run whose measured score is above the
       committed floor fails until the raised floor is committed in the same
       pull request. The ratchet then holds without anybody remembering it, and
       the raise lands with the change that earned it.
     - `report` passes, and the summary and PR comment (ADR-0009) show the
       command that raises it.
     - `require` applies to pull-request runs, as the CI plan reports them
       (ADR-0006). On the default branch an
       improvement is always reported, never failed, because nothing can
       commit to it from the run.
   - After a rebase, two raises of the same tree resolve by running
     `mutation-gate baseline --write` again. It is deterministic, because the
     score is.

7. **A floor goes down only on purpose, with a reason in the file.**
   `baseline --write` never lowers anything. A pull request whose baseline has a
   lower floor than the base branch's has to carry
   `"lowered": {"from": …, "reason": "…"}` on that tree. The gate reads the base
   branch's file through `ChangeSource` (ADR-0001) and fails otherwise. The
   `lowered` entry is dropped the next time the floor is raised. A tree may
   leave the file only when its path is gone.

8. **New code has a floor of its own, 100 by default.** In a pull-request run
   (ADR-0006), and in pre-push and watch mode (ADR-0010), one more set is
   judged: the mutants on the lines the change added or modified. What counts as
   a changed line is set out in ADR-0005.
   - That set is held to `newCode.floor`, a number from 0 to 100, 100 by
     default.
   - In a monorepo, a module or package can declare a floor of its own for the
     new lines in its trees (ADR-0005). The new-code set is then judged per
     module or package, each against its own floor.
   - The tree floor and the new-code floor must both hold. A tree at 62% then
     cannot take in new code at 62%.
   - A change with no mutable lines has an empty set, which passes and says so.

9. **A tree with no floor anywhere is not quietly held to none.** With no
   declared floor and no baseline entry, a CI run (the `CI` environment
   variable is set, as every supported CI sets it) stops with exit code 2 and
   says: run `mutation-gate baseline --write` and commit it. A local full run
   writes the missing entries at the measured score and says to commit them.
   There is deliberately no default floor, like the in-house gate, which has
   none: a tree that inherits a default is exempt from the decision rather than
   held to it.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Enforce by invocation, with the runner's `--min`/`--min-msi`** (the in-house gate's way) | Exact only at 100. Below that, a shard's score is not its tree's score, and trees at different floors cannot share an invocation. |
| **Whole-number floors** (the in-house gate's manifests) | A ratchet that rounds to whole numbers lets a tree lose almost a full point unnoticed. Two decimals, truncated, never records a floor higher than was achieved. |
| **A tolerance margin under the floor** for noise | Lets every real regression smaller than the margin through. Noise has causes (flaky tests and slow timeouts), and triage handles each at its source (ADR-0008). |
| **Per-file counts in the baseline** | Every change to a file would rewrite its line, every pull request would touch the baseline, and parallel pull requests would conflict on it. Per-file results live in the ledger (ADR-0007), which is not committed. |
| **The baseline in the proof store instead of git** | A floor would move without review, and its history would be as long as the cache's retention. |
| **One floor for the whole project** | Lets a strong tree carry a weak one, which is exactly what keeping trees apart prevents. |
| **`report` as the default for improvements** | Floors then rise only when somebody remembers to raise them. The ratchet is only real if the raise lands with the change that earned it. Kept as an option. |
| **Lowering by editing the number alone** | Indistinguishable in review from an accident. A required reason puts the decision in the diff. |

## Consequences

**Floors below 100 work with sharding.** The verdict is computed from
per-mutant results merged across shards and proofs, so any floor can be spread
over any number of runners.

**The score is the gate's own, and it differs slightly from both runners'.**
Pest's score counts every timeout as killed. Infection's MSI counts errors and,
by default, timeouts. The report shows the gate's score, and the JSON report
(ADR-0009) carries every status count, so any other formula can be recomputed.

**A pull request that improves a tree edits one line of the baseline.** The
failure message gives the command that writes it.

## Related

- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): where the normalised statuses come from
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): units, reach, changed lines and monorepo floors
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): proved and carried results
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): unjudged, flaky, timeouts and ignores
