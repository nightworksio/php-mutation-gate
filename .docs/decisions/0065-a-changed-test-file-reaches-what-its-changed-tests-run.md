# ADR-0065: A changed test file reaches what its changed tests run

**Status:** Accepted
**Date:** 2026-11-03
**Amends:** [0005](0005-what-a-change-reaches-is-what-is-mutated.md): its changed test that reaches every unit its tests run

## Context

ADR-0005's rule 4.3 has a changed test reach every unit its tests execute,
by the coverage map. The rule reads a test file whole: a change to one test
of a file reaches what every test of the file runs, as the runner selects
the file to judge each unit.

Pull requests of this repository that touch a handful of tests plan 789 to
944 units (#471, #496, #499, #512, #517). One changed Cli command test file
runs about 750 files. #517 changed two of the 43 tests of
`tests/Unit/Cli/Command/RunCommandTest.php`, and that file alone reached
761 files.

The coverage map already holds each test by its id, with the lines it runs.
The runner places each id in the test file that declares it.

## Decision

1. **`reach.changedTests` chooses what a changed test file reaches.** It is
   `narrow` or `file`, `narrow` by default. It decides how the gate runs
   (ADR-0005), so it is compared and not keyed (ADR-0007).
2. **`narrow`: a changed test file reaches what its changed tests run.**
   - The gate finds each test the map places in the file in both versions
     of it, by the method its id names: a method of a named class, and a
     Pest `it()` or `test()` called with a literal description at the
     file's top or inside a `describe()` so called, by the method Pest makes
     of its description under each `describe()` around it. A test runs from
     the end of the statement before it to the end of its own, its
     comments, attributes, chained calls and data set among it.
   - A test whose declaration differs between the versions, or stands in
     only one of them, is changed, with every row of its data set.
   - The change reaches every file the map says one of the changed tests
     covers, by its id. A change that touched none of them reaches nothing
     by itself.
3. **`narrow` widens to every test of the file where the change moved more.**
   The change then reaches every file the map says one of the file's tests
   covers, by its id. It widens where:
   - it moved what the tests share: anything outside the tests the map
     places in the file, such as an import, `setUp` or `tearDown`,
     `beforeEach` or `afterEach`, a helper, a data provider or `dataset()`,
     a property, a `describe()`'s own code, file-level code, or a test the
     map does not place there, as a test added under a map measured before
     it;
   - it changed a test, and a test of the file may depend on another: a
     Pest test's `->depends()`, a PHPUnit attribute whose name begins with
     `Depends`, or an `@depends` annotation;
   - the file is new, or was renamed, which renames every test it holds;
   - either version of the file cannot be read.
4. **`narrow` falls back to the whole file where the map places none of its
   tests by their ids.** The change then reaches what `file` reaches, and its
   reason says the map holds no test of the file by its id.
5. **`file`: a changed test file reaches every unit its tests run**, as the
   runner selects the file to judge each unit. That is ADR-0005's rule 4.3
   as it stood.
6. **What else rule 4.3 says holds in both modes.** A changed test inside a
   module's directory reaches every tree of that module, and a deleted test,
   or no map to read, reaches everything in its package.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **The whole file always (`file` as the only rule)** | One changed line of a large test file reaches what every one of its tests runs. #517 reached 850 units on 16 shards that way. |
| **Narrow by the changed lines git reports** | `--unified=0` gives no line for a pure deletion, and a line inside a test says nothing about a test added, removed or renamed. Comparing each test's declaration between the versions sees all of them. |
| **Let an added import change nothing** | An import can change what an unqualified name in any test resolves to. The rule keeps everything outside the tests shared. |
| **Widen to the file's tests as the runner selects the file** | The runner selects a test file for every unit a filter cannot name, so that set holds files none of the file's tests runs. The map's ids say what the tests run. |
| **Widen a renamed or new file only to its tests' own reach** | Every test it holds is renamed or new, so the whole file is what changed. |

## Consequences

- Of the units #517 planned, 734 are reached instead of 850, on 15 shards
  instead of 16. Its `RunCommandTest.php` changed 2 of its 43 tests, which
  run 671 files. Its other test files gained an import or a renamed test,
  and widen.
- #499 reaches 776 of its 890 units, on the same 15 shards: most of its test
  files are new, renamed, or gained an import.
- The two Cli command tests #517 changed run 671 files between them, so a
  change to a test that runs most of the gate still reaches most of it.
- A test that leaves state another test of its file reads is narrowed as if
  it did not. The scheduled full run (ADR-0005, rule 6) re-judges every unit.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): what a change reaches
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the shard cut
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the settings that are compared, not keyed
