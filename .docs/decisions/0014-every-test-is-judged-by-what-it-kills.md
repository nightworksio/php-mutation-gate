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
   survived mutants count. A mutant killed by a timeout or by the memory cap
   counts as neither for nor against any test, because its killer is unknown.
   Unjudged, flaky, too-slow-to-judge and too-heavy-to-judge mutants are left
   out. A test that covers only left-out
   mutants is *not assessed*. A held unit is judged only over its holding
   group (ADR-0005 decision 9). A covering test outside the group never ran with
   the unit's mutants, so its cells are `not-run` and it is not judged over
   them.

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
   - **The JSON, `"format": 1`,** lists only the useless and removable tests.
     Its schema is generated as the report's is, and committed at
     `resources/tests.schema.json`:
     `{"format": 1, "matrix", "useless": [{"test", "standing", "covers",
     "rows"}], "notAssessed", "redundant"}`. `standing` is `kills-nothing`
     or `never-first`, `covers` counts the mutants the test judged with a
     known result, and `rows` lists each data set row with its own
     standing. `redundant` is `{"kept": [tests], "removable": [{"test",
     "seconds", "kills": [{"mutant", "keptBy"}]}]}`, or `{"needs":
     <sentence>}` without a full kill matrix.
   - **The Markdown twin** sits at the same path with `.md` in place of
     `.json`, or after it where the path does not end in `.json`. Under
     `# Tests mutation cannot see` it has a section per list, each a table:
     *Kills nothing it covers*, *Never the first to kill*, headed as a
     suspicion, and *Removable*, with each test's time and, per kill, the
     kept test that makes it too. Every name in it is escaped as the PR
     comment escapes it (ADR-0009 decision 3).
   - The verdict value carries the test-level data this needs: each test with
     the mutants it covers and those it killed (ADR-0009 decision 1).
   - **As built.**
     - `TestsReport` is the report's one home, in three forms over a
       verdict: `json()`, `markdown()` and `text()`, which `mutation-gate
       tests` prints on the console with each test on one plain line.
     - Where the verdict holds no coverage, every form says so in place of
       the useless tests: the JSON's optional `noCoverage` holds the
       sentence, and the useless list is empty. Without a full kill matrix
       every form says why in place of the removable tests: a run of first
       killers, or Infection, which stops each mutant at its first failing
       test.
     - `mutation-gate tests` prints `text()` over the last run, judged again
       as `explain` judges it (decision 12): the plan and results the run
       left, the ledgers, and the coverage map the plan handed the verdict.
       Where no run has left a plan, it exits 2 and says to run the gate
       first.

6. **A test is a test method or a Pest test, with its dataset rows folded
   in.** It is useless only when every row is. The JSON lists each row. A
   test is named by its file and the description the runner gives it:
   Pest's TestDox description, PHPUnit's method name. Such a name is
   `tests/Unit/MoneyTest.php::it adds`, not Pest's internal id.
   - **The runner names its tests.** A coverage id's shape is the runner's,
     such as `P\Tests\Unit\MoneyTest::__pest_evaluable_it_adds` or
     `Tests\MoneyTest::testAdds#one`. So the Runner port answers
     `names(TestIds, Withheld): TestNames`. For each id it gives the whole
     test, by file and description, or the data set row, which PHPUnit
     spells `#0` or `"one"`. A row knows the test it folds into. An id the
     runner names nothing is reported as it is.
   - **Where the names come from.**
     - Infection runs nothing. The file is the one whose tokens declare the
       id's class, and the description is the id's method.
     - Pest lists the suite's tests with `--list-tests`, running none, and
       the gate's plugin writes each test class's tests. A Pest test's file is
       the one Pest built its class from, and its description is its TestDox.
       A PHPUnit test's file is the one that declares its class, and its
       description is its method.
     - Listing loads the project's code, so it withholds what every child
       process withholds.
   - **Naming runs at most once a run, never in a shard.** The plan asks
     for the names of the coverage map's tests, and they travel with it:
     `plan.json` holds them as `names`, or why the runner gave none as
     `unnamed`, both outside its digest, since naming judges nothing. A plan
     that holds neither was made without asking.
   - **Naming never blocks a verdict.** A runner that cannot name its tests
     answers *cannot judge* for the names alone. The reports then name each
     test by its id, with a warning.
   - **Where the names show.** The flows hand the names to the kill matrix
     with `KillMatrix::named(TestNames)`. Every *Judged by* line takes them
     from there: the console's, a JUnit failure's, and the HTML and Stryker
     report's description, as `tests/Unit/MoneyTest.php::it fits, …`. The
     JSON report's `tests` takes them from there too. A test named nothing
     is listed by its id.

7. **`run --kill-matrix=full` records every killer, under Pest and the PHPUnit
   runner.**
   - In each mutant's child the plugin drops `--bail`, so every covering test
     runs, and records every test that fails. The adapter reads a mutant with
     a recorded failure as killed, whatever the child's exit, so a child that
     runs past Pest's limit after its first failure still counts as killed.
     Statuses are therefore those of an ordinary run, and the option is not
     in the proof key.
   - Under the PHPUnit runner the gate drops `--stop-on-error` and
     `--stop-on-failure` from each mutant's run, and credits every covering
     test that fails. A run stopped at its limit after a covering test failed
     is killed by the tests that failed.
   - `plan` takes the option too, and the plan holds it as `"matrix": "full"`
     within its digest, so every shard and the verdict run as the plan asks.
   - Each proof records whether its run recorded every killer (ADR-0007,
     decision 3). A full run proves and carries a unit only from such a
     proof, so its matrix holds every killer of each mutant it judges. An
     ordinary run takes either.
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
   - **A test no coverage run timed** is taken to run as long as the slowest
     one that was, so it is kept last among equals. Removability rests on
     kills alone, so this never loses one.
   - **A runner that cannot record every killer** is named by the matrix,
     as a typed reason the runner gives in `Runner::behaviour()`: under
     Infection the section says that Infection cannot produce a full kill
     matrix.
   - **Without a full matrix,** the section says *needs a full kill matrix:
     run `mutation-gate run --kill-matrix=full`*, and lists nothing. Under
     Infection it says that Infection cannot produce one.

9. **The kill matrix is exported as CSV, and joins the JSON and HTML
   reports.**
   - **CSV.** A new built-in report, `kill-matrix`, writes one row per mutant
     and covering test, streamed:
     `mutant,file,line,mutator,status,source,test,outcome,matrix`. `source`
     is `run`, `proved` or `carried`. `matrix` is `first-killer` or `full`.
     `status` is the gate's judgement, as the JSON report's `judgement`
     spells it, and `test` is the test's name, or its coverage id where the
     runner names it nothing. Records follow RFC 4180, ending in CRLF. A
     cell a spreadsheet would read as a formula, one starting with `=`, `+`,
     `-`, `@`, a tab or a carriage return, is written after a `'`.
   - **JSON.** The `json` report lists the tests once, in a `tests` table,
     and gives each mutant `coveredBy` and `killedBy` as indices into it, and
     the matrix kind at the top, as `matrix`. Each entry of `tests` has the
     coverage `id` and the `name`, and, where known, the test's `file`, its
     data set `row` and the `seconds` the coverage run measured. The table
     lists the tests in the order the mutants first name them.
   - **HTML.** The `html` report fills Stryker's `coveredBy`, `killedBy`,
     `testsCompleted` and `testFiles`, so the viewer's test view shows which
     tests kill, which only cover, and which cover nothing. A test's viewer
     id is its coverage id. `testFiles` groups the tests by file, or by the
     class the id names where the runner names the test nothing.
     `testsCompleted` counts the covering tests whose outcome is `killed` or
     `passed`.

10. **A cell says exactly what is known.** Its outcome is one of these:
    - `killed`: the test failed with the mutant in place;
    - `passed`: the test ran and passed. Every covering test of a survivor
      passed;
    - `not-run`: the run stopped at an earlier failure, which is every
      covering test but the first killer under an ordinary run;
    - `unknown`: a timeout's tests, a flaky mutant's tests, and a carried
      unit's tests where the coverage map has moved since its proof.

    A killed mutant whose killer is unknown, such as one an error killed
    before any test failed, has every cell `unknown`. A mutant the run never
    ran, one unjudged, skipped or ignored by a native marker, has every cell
    `not-run`. Every killer a record names is also a covering test.

11. **Every mutant in the verdict is exported, run, proved or carried.**
    `coveredBy` comes from the run's coverage map. The plan hands the verdict
    the lines of every unit it considered, run, proved or carried, as the
    gate's own map in `.mutation-gate/coverage/verdict`. A carried unit's
    file is one the change does not reach, so its lines are those its proof
    was made on. A verdict handed no map holds each mutant's killers alone,
    and warns of it. Nothing is capped.

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
    - **As built.**
      - The last run is the plan and the results in the workspace, which
        `plan` and a run in one process both leave. `Judging::again()`
        judges them as the verdict did, reporting nothing and writing no
        ledger. A mutant the last run holds is explained as that verdict
        judged it; any other by its newest record, with only the killers
        it names as its tests, and a `Unit:` line that says why.
      - `ExplanationText` and `ExplanationJson` are its two forms. The text
        reuses `MutantText`'s heading, diff, reason, hint and `Removable:`
        line. Each JSON entry holds the `json` report's own mutant entry,
        with its covering tests as `tests`, each with its `outcome`.
        `resources/explain.schema.json` describes it.
      - An id that begins with `k` is a cluster's (ADR-0022, decision 17),
        and any other a mutant's or a prefix of one. A cluster is explained
        as the last run found it: its heading, hint and stub command, then
        each member. A cluster id the last run names no cluster by is exit
        2, as is one where there is no last run to read.
      - An id with no record is exit 2, with the sentence `reproduce` gives:
        run the gate on the code that has the mutant to record it.

16. **A shard result keeps the evidence of each kill.**
    Each killed mutant's record in `results/<id>.json` may hold two fields
    beside the record's own. Neither is read by a verdict, a proof or a report;
    the shard judges a kill no test is named for by them (decision 17).
    - `prefix: {at, key?}`: how far the mutant's own run went. `at` is the
      position, from 1, of its first failing test in the order the run took
      its tests in, which is how many tests a run that stops at its first
      failure ran. `key` is twelve lowercase hex digits shared by every run
      that loaded the same test files and took the same order up to its last
      failing test: the start of the SHA-256 digest of the test files it loaded
      under the project's root, sorted, a line each, then an empty line, then
      the SHA-256 digest of each test id followed by a line end, in order, up
      to and including that test. A run that loaded every test file names no
      file.
    - `ended: {code?, signalled?, fatal?, tail?}`, only for a kill that names
      no killer: the code its process exited with, whether a signal ended it,
      whether PHP recorded a fatal error in it, and the last 2 KiB of what it
      printed, every control and format character but a tab and a line end
      dropped, cut on a character's edge. PHP's record of a fatal error is its
      `Fatal error:` or `Parse error:` at the start of a line, after `PHP`
      and the time where it logs it; PHPUnit's `Fatal error: Premature end of
      PHP process`, which it prints after an `exit` too, is none.
    - The tail is what project code printed, so it is screened whole, never
      redacted in part, and kept only where it can be shown to hold no secret.
      The screen runs in the gate's own process, before the cut, over the last
      8 KiB the runner kept:
      - Where that is less than the process printed, as many bytes as the
        longest form of a secret holds are dropped from its start first, so no
        end of a secret the cut split is left. This happens before control
        characters go, which would otherwise draw that end into the tail.
      - A secret is the value of a variable the gate withholds, of eight
        characters or more. Its pieces are the value trimmed, each of its
        lines, each string in it where it is JSON, each of its words and each
        word's part after an `=`, and the user and password of a URL in it.
        Its forms are each piece as it is, URL-encoded, form-encoded,
        hex-encoded, JSON-escaped four ways, backslash-escaped,
        shell-escaped, HTML-escaped two ways, SQL-quoted and as `var_export`
        writes it, and base64-encoded in both alphabets at each of three
        alignments. A form of eight characters or more counts.
      - They are found in any case, in the text as it is and with each
        terminal escape sequence taken out, and with no whitespace in either
        where a form keeps eight characters without its own, so a value
        coloured or wrapped across lines is found too.
      - Anything shaped like a credential, withheld or not, counts too: a
        private key, a GitHub, AWS, Slack or GitLab token, the token
        actions/checkout keeps, an authorization header, and a service
        account's key file.
      - Where any of them appears, the record keeps `code` and `signalled`
        and no `tail`.
    - A runner gives what it saw and leaves out what it cannot tell, and
      nothing is run for it:
      - The PHPUnit runner gives the prefix from the tests its extension saw
        start, and how the process ended where no test is named, a fatal
        error PHP printed among it.
      - Pest's plugin counts and digests the tests each mutant's own process
        starts, and writes the position and digest with each failing test.
        With `pest.patch` on, Pest's parent writes how a failed own process
        ended: its code and signal, never what it printed. That process does
        not hold the withheld values to screen with, and its results file sits
        in the workspace a CI uploads. So a Pest kill has no `tail`. Pest's
        parent reads the error log of each mutant's own process, and writes
        that PHP recorded a fatal error where the log holds one, beside that
        ending or alone. A mutant that shares its mutated copy with another,
        or whose killers came from more than one process, has no evidence,
        since which run went how far cannot be told. A kill run again with
        every test file has the evidence of that run.
      - A trial (ADR-0004, decision 8) names the tests its JUnit log says
        failed or errored as its kill's killers, and, where it names none,
        gives how its run ended: its code, whether a signal ended it, what it
        printed and a fatal error PHP printed among it.
      - Infection logs only what a mutant's process printed, so a kill that
        names no killer has its `tail` and whether PHP's record of a fatal
        error is in it, and no kill has a prefix.
      - A registered runner gives evidence through
        `MutationResult::withEvidence()`.
    - A shard result without these fields reads as before; one whose field is
      malformed is refused.

17. **A kill needs evidence.** A shard judges each kill its runner reports,
    once the runner has run again what it doubts (ADR-0004, decision 3), by
    its evidence (decision 16):
    - A kill stands where a test is named as its killer, where a signal ended
      its process, or where PHP recorded a fatal error in it.
    - Any other kill is unjudged. Its reason says how its process ended: the
      code it exited with, where the runner read one, and the tail of what it
      printed as the screen for secrets kept it, or that none is kept. The
      record keeps the evidence beside the unjudged mutant.
    - The reason is project text, and every report keeps it as text, as it
      keeps any other reason.
    - A registered runner that names no killer and gives no ending leaves
      each of its kills unjudged.

18. **A named killer is checked on the original.** A kill a test is named
    for stands only where its killers pass on the unmutated code, served as
    its mutant was, so that a test that fails only while the runner serves a
    file, or fails on its own, is never read as a kill:
    - Each such kill gets one unmutated control (ADR-0008, decision 2): its
      killers, run with its file served unmutated by the means the runner
      serves a mutant's file, under the request's memory cap, allowed the
      standard mutant limit of their time as the coverage map timed them. Two
      kills of the same file and killers share it, as does a timeout's
      control of the same tests and limit.
    - Every runner is held to it, Pest included: a Pest kill its own run
      already checked on narrowed test files is checked here too.
    - The kill stands where its control passes. Where the control fails, runs
      out of its limit or never runs, the kill is unjudged, and its reason
      says which. Under a time budget a kill whose control does not fit in
      the time left is unjudged, and more time judges it.
    - A kill no test is named for has no control; its evidence judges it
      (decision 17). Infection names a killer only under `infection:patch`,
      so an unpatched Infection's kills are judged by their evidence alone.

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
