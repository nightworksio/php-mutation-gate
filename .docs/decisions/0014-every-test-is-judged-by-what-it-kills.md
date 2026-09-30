# ADR-0014: Every test is judged by what it kills, the kill matrix is exported, and any mutant can be explained

**Status:** Accepted
**Date:** 2026-09-30

## Context

Mutation testing judges tests as much as code. A test that runs code and never
fails when the code changes asserts nothing about it. A test whose every kill
another test also makes costs time and catches nothing new. Both are visible
only in the table of which tests cover and which kill each mutant. The gate
already holds half of it: the coverage map says, per test, which lines each
test runs (ADR-0004). ADR-0013 adds the other half, the first test that kills
each mutant.

The table has a limit that decides what can be reported soundly. Pest's
mutant child always runs with `--bail`, and Infection's mutant configuration
sets `stopOnDefect`, for which PHPUnit 13 has no option to turn off. So an
ordinary run knows three things for certain:

- every covering test of a survivor ran and passed;
- the first killer of a killed mutant failed;
- the other covering tests of a killed mutant were not run, so what they would
  have done is unknown.

A complete kill matrix needs each killed mutant run again without stopping.
The package's Pest plugin can drop `--bail` in a mutant's child through
`HandlesArguments` (ADR-0013). Infection offers no way to do it short of a
patch.

Stryker's report schema, which the HTML report already writes (ADR-0009),
carries `coveredBy`, `killedBy` and `testsCompleted` per mutant and `testFiles`
for the tests. Its viewer's test view classes each test as *Killing*,
*Covering* or *NotCovering*.

A survivor in a CI log also raises a question `reproduce` answers only by
running it: why is this mutant here, and has it always been?

## Decision

1. **The first killer travels in the proof.** A killed mutant's record in a
   proof keeps `killedBy`: the first killer's test id, or every failing test's
   id under a full kill matrix (decision 7). A shard's result carries the ids
   in each mutant's full record. The ledger keeps them as the fourth element
   of the killed tuple, as indices into its list of tests (ADR-0007, decision
   3). The agreement check of ADR-0007 compares statuses only and never
   `killedBy`, because the first killer depends on the order (ADR-0013). So
   any run can report on the whole project from the newest proof of each
   unit, not only on what it ran.

2. **The `tests` report names useless tests, in two tiers.**
   - **Kills nothing it covers:** every judged mutant the test covers
     survived, was ignored or was proven equivalent. It certainly caught none
     of them.
   - **Never the first to kill:** it covered killed mutants, and killed none
     of them first. It may be useless. The report calls this a suspicion,
     never a finding.
   - A full kill matrix resolves the second tier: each such test either joins
     the first or leaves the list.

3. **A test is judged only over mutants with a known result.** Killed and
   survived mutants count. A mutant killed by a timeout counts as neither for
   nor against any test, because its killer is unknown. Unjudged, flaky and
   too-slow-to-judge mutants are left out. A test that covers only left-out
   mutants is *not assessed*. A held unit is judged only over its holding
   group (ADR-0005 decision 9).

4. **The report covers the whole project.** It reads the newest proof of every
   unit in the readable ledgers (ADR-0007), with the run's coverage map. A
   unit with no proof leaves its tests *not assessed* for it.

5. **The report is a file and a command, and never fails anything.**
   - A built-in report, `tests`, writes JSON at its `path` and a Markdown
     twin beside it (`build/tests.json` and `build/tests.md`). It holds this
     decision's useless tests and decision 8's redundant ones.
   - `mutation-gate tests` prints the same from the ledgers and the stored
     coverage map, and mutates nothing.
   - Both exit 0 whatever they find. A test can have value that mutation
     cannot see (a template, a query, a contract), as a hot path's cost does
     not make a verdict wrong (ADR-0005 decision 11).
   - The verdict value carries the test-level data this needs: each test with
     the mutants it covers and those it killed (ADR-0009 decision 1).

6. **A test is a test method or a Pest test, with its dataset rows folded
   in.** It is useless only when every row is. The JSON lists each row. A
   test is named by the file and description its JUnit entry gives, such as
   `tests/Unit/MoneyTest.php::it adds`, not by Pest's internal id.
   - **The runner names its tests.** A coverage id's shape is the runner's,
     such as `P\Tests\Unit\MoneyTest::__pest_evaluable_it_adds` or
     `Tests\MoneyTest::testAdds#one`. So the Runner port answers
     `names(TestIds): TestNames`: for each id, the whole test (file and
     description) or the data set row its JUnit entry names. A row knows the
     test it folds into. An id the runner names nothing is reported as it
     is.

7. **`run --kill-matrix=full` records every killer, under Pest.**
   - In each mutant's child the plugin drops `--bail`, so every covering test
     runs, and records every test that fails. The adapter reads a mutant with
     a recorded failure as killed, whatever the child's exit, so a child that
     runs past Pest's limit after its first failure still counts as killed.
     Statuses are therefore those of an ordinary run, and the option is not
     in the proof key.
   - It costs about a second mutation run of every killed mutant's covering
     tests, so it is for a scheduled job, never a pull request.
   - Under Infection the option is refused with exit code 2: *a full kill
     matrix needs Infection to keep running after a failure, which it cannot*.
   - `--kill-matrix=first`, the default, is an ordinary run.

8. **The redundant-test report is a removable set, and needs a full kill
   matrix.**
   - **The set.** The gate keeps tests greedily, one at a time: next is the
     test that keeps the most kills not yet kept per second of its own time,
     from the coverage map, with ties by name. Every test outside the kept
     set is removable, with the time it costs. Each names, for each of its
     kills, the kept test that also makes it. Removing the whole list loses
     no kill, and the report says the kept set is a small one, not the
     smallest.
   - **What is always kept.** A test that is the only one covering some line
     of a tree, and a test in a `holds:` group whose removal would leave the
     group short of the coverage ADR-0005 decision 10 checks. The report says
     it judges mutation kills only.
   - **Without a full matrix,** the section says *needs a full kill matrix:
     run `mutation-gate run --kill-matrix=full`*, and lists nothing. Under
     Infection it says that Infection cannot produce one.

9. **The kill matrix is exported as CSV, and joins the JSON and HTML
   reports.**
   - **CSV.** A new built-in report, `kill-matrix`, writes one row per mutant
     and covering test, streamed:
     `mutant,file,line,mutator,status,source,test,outcome,matrix`. `source`
     is `run`, `proved` or `carried`. `matrix` is `first-killer` or `full`.
   - **JSON.** The `json` report lists the tests once, in a `tests` table,
     and gives each mutant `coveredBy` and `killedBy` as indices into it, and
     the matrix kind at the top.
   - **HTML.** The `html` report fills Stryker's `coveredBy`, `killedBy`,
     `testsCompleted` and `testFiles`, so the viewer's test view shows which
     tests kill, which only cover, and which cover nothing.

10. **A cell says exactly what is known.** Its outcome is one of these:
    - `killed`: the test failed with the mutant in place;
    - `passed`: the test ran and passed. Every covering test of a survivor
      passed;
    - `not-run`: the run stopped at an earlier failure, which is every
      covering test but the first killer under an ordinary run;
    - `unknown`: a timeout's tests, a flaky mutant's tests, and a carried
      unit's tests where the coverage map has moved since its proof.

11. **Every mutant in the verdict is exported, run, proved or carried.**
    `coveredBy` comes from the run's coverage map for run and proved units,
    whose keys already hold each covered line's tests (ADR-0007), and from the
    proof for carried ones. Nothing is capped.

12. **`mutation-gate explain <mutant>` explains one mutant, offline.** It reads
    the ledgers, the last run's results and the coverage map, and runs
    nothing. It prints:
    - the diff, and the family's hint (ADR-0009 decision 7);
    - each covering test with its outcome (decision 10) and its time;
    - the judging tests, where they differ from the covering ones
      (ADR-0004 decision 8);
    - for a timeout, the limit and the triage (ADR-0008);
    - any ignore that matches it, and any proof of equivalence (ADR-0013);
    - why its unit was run, proved or carried, which is the reach reason;
    - its history (decision 13);
    - and last, the `reproduce` command.

13. **A mutant's history is every proof that holds it.** `explain` lists each
    proof in the readable ledgers that holds the id, with its status, run,
    date and scope. It is as deep as the ledger's retention, the newest
    20,000 proofs (ADR-0007), and needs no storage of its own.

14. **`explain` takes the 12-hex id or a unique prefix of at least 6.** An
    ambiguous prefix lists the candidates. An id with no record is exit 2,
    with *no record of this mutant: run `mutation-gate reproduce <id>`*, and
    names the run that would reach its unit where the id's unit is known.
    Otherwise `explain` exits 0.

15. **`explain` prints text, or JSON with `--format=json`.** The console, HTML
    and JSON reports give the `explain` command beside `reproduce`. Annotations
    and the PR comment keep `reproduce` alone, for space.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **One useless-test list: covered code, never recorded as a killer** | Under killer-first ordering (ADR-0013) the same test always wins, and its neighbours would all be listed. It is wrong in exactly the suites that use the order. |
| **Useless tests only from a full kill matrix** | Always right, and never available from an ordinary run. The first tier is a fact from any run. |
| **Judging a test over every mutant it covers** | A budget-cut run would list half the suite as useless. |
| **Reporting only on what this run judged** | A pull request's report would say nothing about tests it did not reach. |
| **Reporting only after full runs** | Correct, and available weekly at best. The ledger already holds every unit's newest result. |
| **A warning in the verdict, the summary and the PR comment** | Lists tests on every pull request, whatever it touched. |
| **An option to fail on useless tests** | Turns a heuristic into a gate. A test can have value mutation cannot see. |
| **One entry per dataset row, or per test file** | A row cannot be deleted on its own, and a file hides a useless test inside a useful one. |
| **Patching Infection to clear `stopOnDefect`** | A vendor patch on a 0.x dependency, for a scheduled insight report. |
| **Inferring redundancy from first killers over many runs** | Converges slowly and never proves anything, and ADR-0013 fixes the order the first killer comes from. |
| **Per test, "every kill also made by another"** | Two tests may each duplicate the other. Deleting everything listed can lose kills. |
| **Kills alone deciding redundancy** | Could recommend deleting the test that makes a holding group valid, so the next run fails. |
| **Separate `kill-matrix-json` and `kill-matrix-csv` reports** | Two more names, and a second JSON repeating the first. |
| **A wide CSV, mutants by tests** | 5,000 mutants by 1,500 tests is 7.5 million cells, mostly empty. |
| **Cells that say only `killed` or `covered`** | "Covered" hides whether the test ran and passed. |
| **Only the mutants run this time** | Small, and meaningless on a change-scoped run. |
| **`reproduce --explain`** | Every explanation would cost a runner invocation, and a CI log's reader often has no runner set up. |
| **A per-mutant history file** | Deeper history, and a new public format with its own retention and trust rules. |
| **Accepting `<file>:<line>` in `explain`** | Turns one explanation into a list of them. |
| **`explain` as text only** | Tools would have to parse prose. |

## Consequences

**Tests are judged, not only code.** A test that can catch nothing shows up by
name, from any run, with no extra cost.

**Redundancy is reported only when it is proved,** and in a form that is safe
to act on whole.

**The kill matrix leaves the gate in every format a tool reads:** CSV for
spreadsheets and notebooks, JSON for scripts, and Stryker's viewer for people.

**A survivor can be understood where it is read,** in a CI log or a terminal
with no runner, and `reproduce` stays the one command that runs it.

## Related

- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the coverage map, judging tests, and the plugin's jobs in a mutant's child
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): holding groups and the coverage check they must pass
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): `killedBy` in the proof, and the agreement check that ignores it
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the verdict's test-level data, and the `tests` and `kill-matrix` reports
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the first killer, and the order it depends on
