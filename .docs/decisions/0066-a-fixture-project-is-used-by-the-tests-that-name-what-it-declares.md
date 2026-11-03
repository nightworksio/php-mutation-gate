# ADR-0066: A fixture project is used by the tests that name what it declares, and this repository's data files are fixtures

**Status:** Accepted
**Date:** 2026-11-03
**Grows:** [0064](0064-a-fixture-project-reaches-the-tests-that-name-its-path.md): the tests that use a fixture project, for the classes and functions its PHP files declare
**Amends:** [0064](0064-a-fixture-project-reaches-the-tests-that-name-its-path.md): the fixture projects this repository lists

## Context

ADR-0064 finds the tests that use a fixture project by the strings that name
its path. A fixture can also be PHP that a test loads by name:
`tests/Fixtures/Codeception/HeldCest.php` declares a class that
`tests/Unit/Adapter/Codeception/TestTest.php` imports, and
`tests/Support/CodeceptionTests.php` names for four more tests. No string
names its path, so as a fixture project it would reach every unit of the
package, though as test support it reaches those five files alone.

The rest of `tests/Fixtures` is data: workflows, manifests, configs, replays
and reports that a test reads by its path. None of it is PHP the suite
loads, so a change to a file there reaches nothing, by ADR-0005's rule 5,
though the test that reads it judges against it.

## Decision

1. **A fixture project is also used by every file outside it that names a
   class or function one of its PHP files declares**, as a file that names
   its path is: a file of test cases is a user, support is followed to the
   tests that use it, as rule 4 finds them, and a file that is no test
   reaches every unit of the package. A project no file is found to use
   either way reaches every unit of its package, as ADR-0064's rule 4 says.
2. **This repository's `reach.fixtures` lists `tests/Fixtures/*`** beside
   the 16 projects `phpunit.xml` leaves out of its suites, so each directory
   of `tests/Fixtures` is a fixture project. The test that keeps the list
   equal to `phpunit.xml`'s exclusions reads the glob apart from them, and
   checks that it matches a directory.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **`tests/Fixtures/*` with users by path alone** | `HeldCest`, `HeldSpec` and `Archive` are loaded by name, so a change to one would reach every unit where today it reaches the tests that load it. |
| **List only the directories of `tests/Fixtures` that hold no PHP** | A class fixture of a project that uses the gate has the same need, and a list kept by hand drifts as directories are added. |
| **Leave `tests/Fixtures` as it is** | A changed workflow or manifest there reaches nothing, though the test that reads it can then fail or pass on the change. |

## Consequences

- A change to a data file under `tests/Fixtures` reaches what the tests that
  read it by its path run.
- A change to `HeldCest` reaches the same tests it reached as test support.
- Reading who uses a fixture project reads each of its PHP files'
  declarations, from the files `SupportUsers` already reads.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): the reach rules
- [ADR-0064](0064-a-fixture-project-reaches-the-tests-that-name-its-path.md): the fixture projects this grows
