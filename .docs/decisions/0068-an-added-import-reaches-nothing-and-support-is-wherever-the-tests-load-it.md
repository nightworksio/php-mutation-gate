# ADR-0068: An added import reaches nothing, a widened file reaches what `file` reaches, and support is wherever the tests load it

**Status:** Accepted
**Date:** 2026-11-03
**Amends:** [0065](0065-a-changed-test-file-reaches-what-its-changed-tests-run.md): its rules 2 and 3; [0005](0005-what-a-change-reaches-is-what-is-mutated.md): its rule 4.4, what test support is

## Context

ADR-0065 has `narrow` widen a changed test file to every test of it where the
change moved anything the tests share, an added import among them. It widens
to what the map's ids of the file's tests run, which leaves out what the
runner judges by the file: Pest selects every test file for a unit whose
filter does not fit, and `file` mode reaches those units.

ADR-0005's rule 4.4 counts as test support only the files under a suite's
test directories. A file the suite loads from anywhere else, as
`tests/Fakes/CiPlanFake.php` under `autoload-dev` beside a suite of
`tests/Unit`, reached nothing by itself. #499 changed it, and 137 tests use it.

## Decision

1. **An import a change only added reaches nothing by itself.** A test that
   uses it changes too, and is reached by its own declaration. The file still
   widens where it lost or changed an import, where one it held more than once
   is held fewer times, or where the change moved anything else the tests
   share.
2. **A widened file reaches what `file` mode reaches.** That is every unit the
   runner judges by the file, as for a unit whose filter does not fit, and
   every file the map says one of its tests runs.
3. **Test support is any PHP file the suite loads that is not a file of test
   cases.** That is a file under a suite's test directories, or under a
   directory or file a package's `autoload-dev` names, read from the root
   manifest and from each tree's package manifest. It reaches the tests that
   use it by rule 4.4. A file of a fixture project (ADR-0064) stays a fixture.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **An added import widens, as ADR-0065 had it** | It reaches every test of the file for a line that changes no test. The tests that use the new name change with it, and reach what they run. |
| **An added import reaches nothing, and so does a changed one** | A changed or removed import changes what a name resolves to in tests that did not change. |
| **Widen to what the file's tests run, as ADR-0065 had it** | It leaves out the units the runner judges by the file, as for a unit whose filter does not fit. A widened file is a whole file, and reaches what `file` reaches. |
| **Name support directories in the configuration** | `autoload-dev` already says where the tests load code from, so a setting would repeat it, and a repository that left one out would lose reach. |
| **Every file `autoload-dev` names reaches everything** | Support that only declares reaches the tests that name what it declares, as support under a suite's directories does. |

## Consequences

- #517 reaches 727 of its 850 units, on 15 shards instead of 16. Its other
  test files only gained imports.
- #499 reaches 1402 of its 1472 units, on 20 shards. Its change to
  `tests/Fakes/CiPlanFake.php` reaches the 137 tests that use it, which run
  1360 files. Without it, #499 reached 718 of its 890.
- A widened `RunCommandTest.php` of #517 reaches 812 units on 16 shards, as
  `file` mode does.
- An added import that gives a name an unchanged test of the file already
  spells a new meaning reaches nothing by itself. The scheduled full run
  (ADR-0005, rule 6) re-judges every unit.
- A PHP file under a directory `autoload-dev` names that runs code when it is
  loaded reaches every unit of its package, as support under a suite's
  directories does. A directory `reach.fixtures` matches holds a fixture
  project instead, as `tests/Fixtures/*` does in this repository.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): what a change reaches
- [ADR-0064](0064-a-fixture-project-reaches-the-tests-that-name-its-path.md): fixture projects
- [ADR-0066](0066-a-fixture-project-is-used-by-the-tests-that-name-what-it-declares.md): what a fixture project declares
- [ADR-0065](0065-a-changed-test-file-reaches-what-its-changed-tests-run.md): what a changed test file reaches
