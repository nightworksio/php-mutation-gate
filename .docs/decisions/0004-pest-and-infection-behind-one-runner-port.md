# ADR-0004: Pest and Infection behind one Runner port, each reporting every mutant

**Status:** Accepted
**Date:** 2026-09-29

## Context

The gate needs four things from whatever mutates the code:
- which tests run which line (a per-test coverage map);
- the suite's groups;
- a mutation run over some files, judged by some tests;
- every mutant's result: where it is, what changed, and whether a test caught
  it.

v1 supports two runners: Pest's own mutation testing (`pestphp/pest-plugin-mutate`)
and Infection. Their capabilities were read from source: pest-plugin-mutate
5.0.2 as installed in the companion, and Infection 0.35.5, tagged 2026-09-27.
They differ in ways that shape the adapters.

- **Pest writes no machine-readable mutation report.** Its output is console
  text. Under `--parallel` each mutant prints as one character, and only escaped
  and uncovered mutants get a line with an `ID:`. The companion never parses it.
  It reads only the exit code of `--min`. Pest does emit an in-process event for
  every mutant: `Tested`, `Untested`, `Uncovered` and `Timeout`, each carrying a
  `MutationTest` with its `Mutation` (file, mutator, start and end line, diff,
  id) and `duration()`. That event facade is marked `@internal`.
- **Pest has five statuses:** `none`, `tested`, `untested`, `uncovered` and
  `timeout`. `tested` covers every non-zero exit, so a crash counts as a kill.
  The timeout is not configurable. It is the initial suite's duration plus the
  larger of 5 s and 20 %.
- **Pest opens every run with the whole suite under coverage.** It has no
  option to take a map another run wrote. The companion patches vendor code to
  make it take one (`scripts/patch_pest_mutate_shared_coverage.php`). A second
  patch (`patch_pest_mutate.php`) covers a line that every test covers. Pest
  builds one `--filter` argument naming every covering test, and for such a line
  that argument grows past the kernel's limit, so the child process never starts
  (`posix_spawn() failed: Argument list too long`).
- **Infection no longer runs Pest suites.** Support was removed (infection PR
  #2047, first released in 0.29.13). Its bundled framework is PHPUnit.
  Codeception, phpspec and testo adapters install separately.
- **Infection writes a JSON log, but only when the config file asks** (`logs.json`,
  since there is no `--logger-json` flag). The log has `stats` and per-status
  arrays (`killed`, `escaped`, `timeouted`, `errored`, `syntaxErrors`,
  `uncovered`, `ignored`, `killedByStaticAnalysis`). Each entry is
  `{mutator: {mutatorName, originalSourceCode, mutatedSourceCode,
  originalFilePath, originalStartLine}, diff, processOutput}`. It has **no
  mutant id and no duration**.
- **Infection reuses a coverage run natively.** `--coverage=<dir>` takes
  `coverage-xml/index.xml` plus one JUnit file, and `--skip-initial-tests` then
  skips its own opening run. Its timeout is a config value in seconds.
- **Neither runner's own mutant id identifies a mutant across machines.** Pest's
  is `xxh3(realpath . mutator . mutated file)`. Infection's is an md5 over the
  file path and parser attributes. Both change when the checkout moves.

## Decision

1. **The Runner port asks for exactly what the core needs.**

   | Method | Returns |
   |--------|---------|
   | `identity()` | The runner's name, the exact installed version of every package it drives, and a digest of the PHP it runs on: version, extensions and ini. All of these go into the content key (ADR-0007). |
   | `groups()` | The suite's groups, as the runner itself lists them |
   | `coverage(request)` | A per-test line map of the whole suite or of one group, plus each test's duration, either by running the suite or by reading a map another job wrote |
   | `judges(file, map)` | The test files that can judge a mutant of this file, by the runner's own selection rules (decision 5) |
   | `mutate(request)` | Every mutant's normalised result for some files, judged by the whole suite or by a group, under a deadline |
   | `retry(mutants, limit)` | The same, for a few mutants run again (ADR-0008) |

   A failed opening run, an unsupported version or a result that does not add up
   is returned as *cannot judge* with the runner's output (ADR-0001). The run
   stops with exit code 2.

2. **Every mutant is normalised to one record.**
   - **id**: the gate's own, 12 hex characters of a SHA-256 over:
     - the path relative to the repository;
     - the mutator;
     - the removed and added lines of the diff, with whitespace collapsed;
     - the mutant's position among mutants in the same file that share
       everything above.

     The line number is not an input, so an id survives code above it moving.
     The runner's native id is kept alongside for use within the same run only.
   - **where and what**: file, start and end line, the native mutator name, its
     family (ADR-0009), and the diff.
   - **status**: killed, survived, uncovered, timed out, errored or unjudged.
     Flaky and ignored are applied later by the gate (ADR-0008).
   - **duration**, where the runner reports one.

3. **The Pest adapter** (`pestphp/pest` ^5.1, `pestphp/pest-plugin-mutate`
   ^5.0).
   - **Invocation:**

     ```
     vendor/bin/pest --mutate --everything --parallel --path=<files>
         [--ignore=<held paths>] [--group=<holds group>] [--covered-only]
         [--processes=<n>]
     ```

     - `--everything` makes the gate, not a test's `covers()` or `mutates()`,
       decide what is mutated.
     - `--covered-only` is passed only under `uncovered: exclude` (ADR-0003).
     - `--min` is never passed.
     - A contract test proves that a suite using `covers()` is still judged by
       every covering test.
   - **Results** come from a small Pest plugin shipped in this package and
     listed in its `composer.json` under `extra.pest.plugins`, which is how Pest
     finds plugins.
     - It is inert unless `MUTATION_GATE_RESULTS` names a file. When it does,
       it subscribes to the mutate plugin's events and writes one JSON line per
       mutant: native id, file, lines, mutator, diff, status and duration.
     - The adapter fails closed. The records must add up to the counts on Pest's
       own summary line (`Mutations: … untested, … uncovered, … pending, …
       timeout, … tested`), and a missing file or a mismatch is *cannot judge*.
     - Because the events are `@internal`, only the pest-plugin-mutate versions
       the contract suite covers are allowed, and `composer.json` declares a
       `conflict` for the rest.
   - **Statuses**:

     | Pest status | Gate status |
     |-------------|-------------|
     | `tested` | killed. Pest cannot tell a crash from a failed assertion, so errored never comes from Pest. |
     | `untested` | survived |
     | `uncovered` | uncovered |
     | `timeout` | timed out |
     | `none` left at the end | unjudged |

   - **Coverage**:
     - `vendor/bin/pest --parallel --coverage-php=<map> --log-junit=<junit>`
       under pcov or Xdebug. The map is read with `phpunit/php-code-coverage`
       as the companion reads it.
     - Groups come from `vendor/bin/pest --list-groups --colors=never`. A
       listing without `Available test group` is *cannot judge*, never *no
       groups*.
   - **The companion's two patches are an opt-in.** Enabling it is
     `pest.patch: true`, plus `@php vendor/bin/mutation-gate pest:patch` in
     `post-install-cmd` and `post-update-cmd`. The command applies both
     patches:
     - Shards open on a canary group (`pest.canary`, default `mutation-canary`)
       and read the map the planning job wrote, instead of each running the
       whole suite again.
     - A `--filter` that will not fit is dropped, so that mutant runs against
       the whole suite. That can only kill more mutants, never fewer.

     Every anchor is checked before anything is written, and one that has moved
     fails the install. That is the companion's rule: a patch that quietly
     matched nothing is worse than none. With patching enabled, a canary group
     with no test in it is *cannot judge*. Without patching, every shard runs
     its own opening suite, and a line past the filter limit is *cannot judge*
     with a message pointing at the patch.
   - **Timeouts** are Pest's own and cannot be changed, so timeout triage for
     Pest does not rerun anything. It compares the covering tests' own time
     with the limit (ADR-0008).

4. **The Infection adapter** (`infection/infection` ~0.35.0, with PHPUnit 12
   or 13).
   - **Invocation:**

     ```
     vendor/bin/infection --configuration=<generated> --threads=<n|max>
         --no-progress --log-verbosity=all [--with-uncovered]
         [--coverage=<dir> --skip-initial-tests]
         [--test-framework-extra-args=<group or filter>] <files>
     ```

   - **Configuration.** The adapter writes a config per invocation. It starts
     from the project's own `infection.json5` when there is one (mutators,
     `bootstrap`, `phpUnit`, `initialTestsPhpOptions`, `testFrameworkExtraArgs`)
     and overrides what the gate owns:
     - `logs.json` points at the results file, and every other log is off.
     - `timeout` comes from the gate's config.
     - `minMsi` and `minCoveredMsi` are removed.
     - `tmpDir` is under `.mutation-gate/`.
   - **Held paths.** The group or the `#[Holds]` filter (ADR-0005) is passed
     through `--test-framework-extra-args`. A contract test proves it narrows
     both the opening run and every mutant's run.
   - **Results.** The adapter reads `logs.json`. Every stats count must equal
     the length of its array, or the run is *cannot judge*.

     | Infection status | Gate status |
     |------------------|-------------|
     | killed by tests, killed by SA | killed |
     | escaped | survived |
     | error, syntax error | errored |
     | timed out | timed out |
     | not covered | uncovered |
     | skipped (the covering tests alone take longer than the timeout) | unjudged, *too slow to judge* |
     | ignored | refused, because ignores live in the gate's config (ADR-0008) |

   - **Coverage** comes from `vendor/bin/phpunit --coverage-xml=<dir>/coverage-xml
     --log-junit=<dir>/junit.xml`. That is the layout `--coverage` expects, and
     the gate reads its per-line `covered by` entries as its own map, so one run
     serves both. Groups come from `vendor/bin/phpunit --list-groups`.
   - **Scope.** The adapter refuses a `testFramework` other than `phpunit`, and
     a `phpUnit.customPath` that points at Pest. A Pest project uses the Pest
     adapter.
   - **Timeouts.** A timed-out mutant is run again by narrowing to its file and
     its mutator (`<file> --mutators=<name>`) with the timeout doubled, and it is
     matched back by the gate's id (ADR-0008).

5. **Which tests can judge a file is the runner's answer, not a guess.** The
   content key needs it (ADR-0007).
   - **Pest** selects covering tests with an unanchored regular expression,
     `<Class>::(.*)<name>`. So a test class also selects every class whose name
     ends in the same letters. When the filter is dropped (decision 3), a mutant
     runs against the whole suite. The adapter answers with exactly that set:
     every file whose class ends in a covering test's class name, or every test
     where the filter would not fit. This is what the seed's `WhatJudgesAFile`
     computes.
   - **Infection** runs the covering test cases' classes, and the adapter
     answers with their files.

6. **Reproducing one mutant is runner-neutral.** `mutation-gate reproduce <id>`
   runs the runner over the one file with only that mutator. That is Pest's
   `--path` with `--mutator`, or Infection's positional path with `--mutators`.
   It finds the mutant by the gate's id and prints the diff, the covering tests
   and the runner's own output for it. Pest's `--id=<native id>` is used for
   that last run once the native id is known on this machine.

7. **One contract suite for every runner.** `tests/Contract/Runner` holds a
   fixture library with a known killed, survived, uncovered, timed-out and
   held mutant. Every adapter must produce the same normalised records for it.
   CI runs the suite against the lowest and highest supported version of each
   runner (ADR-0011).

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Parse Pest's console output** | Under `--parallel` a killed mutant is one character, with no file, line or id. Only escaped and uncovered mutants are named. Per-file counts, which the verdict needs below a floor of 100 (ADR-0003), are not there. |
| **One Pest invocation per file, reading the exit code** | Every invocation pays Pest's opening suite, which is what shared coverage exists to avoid, and the exit code still says nothing below a floor of 100. |
| **Infection for Pest projects** | Infection removed Pest support. Pointing its PHPUnit adapter at `vendor/bin/pest` depends on PHPUnit-shaped filters and JUnit that Pest does not promise. |
| **Pest's `--id` or Infection's id as the gate's id** | Both hash an absolute path, so an id printed in CI does not exist on a developer's machine, and an ignore written against it would never match. |
| **Infection's id from the Stryker report embedded in its HTML log** | Means parsing JSON out of an HTML page, and the gate needs no native id: narrowing to file and mutator finds a mutant in a handful of runs. |
| **Patching Pest by default** | Edits another package's vendor code on every install without being asked. Opt-in keeps that a visible decision in the project's own `composer.json`. |
| **Waiting for Pest to offer a report or a shared map** | Not in this package's control. The adapter works with what the supported versions ship, and the contract suite finds out when that changes. |
| **Codeception and phpspec through Infection** | Their coverage and group listing are not PHPUnit's, and nothing in the gate's reach, holds or proof key has been checked against them. An extension can add them later through the same port. |

## Consequences

**Every mutant is on record, from either runner.** The verdict, the baseline,
the proofs, the reports and the hints all read the same normalised records.

**The Pest adapter depends on an `@internal` API.** Each pest-plugin-mutate
release has to pass the contract suite before its `conflict` entry is relaxed.
A Pest release that moves the events fails the contract suite, not a user's
gate.

**Sharded Pest runs are fastest with the patch.** Without it each shard pays
one opening suite. The README says so beside the CI examples.

**Infection is pinned to a 0.x minor.** Each Infection minor may break its
interfaces, so each is added only once the contract suite passes on it.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-eight-ports.md): the port and its outcomes
- [ADR-0003](0003-a-floor-only-rises.md): how statuses become a score
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): holding groups and `#[Holds]`
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): runner identity and judging tests in the key
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): timeouts, flaky results and ignores
