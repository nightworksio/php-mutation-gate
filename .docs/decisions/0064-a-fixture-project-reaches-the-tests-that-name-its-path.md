# ADR-0064: A fixture project reaches only the tests that name its path

**Status:** Accepted
**Date:** 2026-11-03
**Narrows:** [0005](0005-what-a-change-reaches-is-what-is-mutated.md): its test support that runs code when it is loaded, for the fixture projects `reach.fixtures` names
**Narrows:** [0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): the tests `affected` lists for a file of a fixture project

## Context

A fixture project is a small project of its own kept under the tests. A
test copies it, or points a runner at it, by its path. The suite never loads
its files as code of its own: this repository's `phpunit.xml` leaves each one
out of its suites.

ADR-0005's rules do not know that. A PHP file in a fixture is test support,
since it is under a directory of tests and is no file of test cases. A Pest
spec there calls `it()` when it is loaded, so rule 4 makes every change to
it reach every unit of the package. A file there that is not PHP, such as the
fixture's `composer.json`, reaches nothing by rule 5, though the tests that
copy the fixture read it.

The verdicts of #485, #481, #478 and #477 name 13 fixture specs. Each of those
runs reached every unit, about 1,480 shard-minutes.

## Decision

1. **`reach.fixtures` names the fixture projects.** It is a list of globs,
   spelt from the repository's root, empty by default. Each directory one
   matches is a fixture project. A file is in the fixture project of the
   outermost directory above it that a glob matches. The setting decides how
   the gate runs (ADR-0007), so a change to it reaches everything.
2. **A changed file in a fixture project reaches what the tests that use the
   project run**, by rule 3, whatever the file holds. This comes before rules
   3 to 5. The tests that use a fixture project are:
   - each file of test cases outside the project with a string that names
     the project's path or a path inside it. The string is spelt from the
     file's own directory, from each directory above it, and from the
     repository's root. It is the text of a quoted string, or a run of text
     between what a double-quoted string or a heredoc interpolates, so
     `sprintf('tests/Contract/Runner/heavy/%s', $file)`, `__DIR__ . '/heavy'`
     and `dirname(__DIR__, 2) . '/Runner/heavy'` in `tests/Contract/Runner/`
     all name `tests/Contract/Runner/heavy`;
   - the tests that use test support with such a string, as rule 4 finds
     them;
   - the tests that use another fixture project one of whose files has such
     a string.
3. **What cannot be told reaches everything in the package, as rule 4 has
   it.** That is support that runs code when it is loaded and has such a
   string, a file that is no test and has one, or no coverage map to read.
4. **A fixture project that no test is found to name cannot be told
   either**, since a test can build its path as it runs. It reaches every
   unit of its package, and the plan says why: "No test is found to name the
   fixture `tests/fixtures/bank`, so what reads it cannot be told: every unit
   of the project is reached."
5. **A file moved into or out of a fixture project** reaches the tests that
   use the project, and also what rules 2 to 5 say of its path outside it.
6. **`affected` reads the rule the same way** (ADR-0020). It lists the tests
   that use the fixture project, and every test where they cannot be told,
   no test found to name it among them.
7. **This repository lists its fixture projects.** `mutation-gate.json` names
   the 16 that `phpunit.xml` leaves out of its suites, and a test keeps the
   two lists equal.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep ADR-0005's rule 4 for fixtures** | A spec that calls `it()` when loaded makes each fixture change a full run: about 1,480 shard-minutes over four pull requests. |
| **Read the fixture projects from PHPUnit's `<exclude>`** | An exclude says what is not a test. It does not say what reads the files, and Pest's own config, Codeception, PhpSpec and Testo have no such list. |
| **A fixture file reaches nothing, as rule 5 has it** | The tests that copy the fixture judge against it. A change to a fixture can turn a kill into a survivor, which no unit's own change would show. |
| **Any text that names the path, comments included** | A comment that names the project is no use of it, and strings are where a path that is used is written. |
| **A fixture no test is found to name reaches nothing** | The search reads strings, and a test can build the path as it runs. Reaching nothing would carry a result its changed fixture could move, the under-reach a carried result must never have. |
| **A string that names a directory above the project** | `tests` and `tests/Contract/Runner` name every fixture under them, so nearly every test would use each one. |

## Consequences

- Replayed against the default branch's coverage map and ledger, #485's diff
  reaches only the units of the tests it changed and of HeavyTest. It plans
  121 units on 3 shards, about 41 runner-minutes, where it planned 2,220 on 20,
  about 7 hours 20 minutes. #481's reaches only those of the tests it changed
  and of MissedTest and PatchedTest, the two that name its fixture. It plans
  659 units on 16 shards, about 3 hours 48 minutes.
- A test that builds a fixture's path as it runs, from parts no single string
  spells, is not found. Where no other test names the fixture, its change
  reaches every unit of its package. Where another test does, the fixture
  reaches that test's units and not the one the search missed, and the
  scheduled full run judges what it missed. Each of this repository's 16
  fixture projects is named by at least one test.
- A fixture project the setting leaves out reaches as ADR-0005 has it.
- The proof key does not change (ADR-0007).

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): rule 4, which this narrows for fixture projects
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the settings that decide how the gate runs, and the key
- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): `affected`, which reads the same rule
