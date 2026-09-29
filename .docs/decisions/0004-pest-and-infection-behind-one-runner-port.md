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
and Infection. Their capabilities were read from source and tried in scratch
projects: pest-plugin-mutate 5.0.2 with Pest 5.2.1, and Infection 0.35.5, with
every option named here checked against 0.35.0 as well. They differ in ways
that shape the adapters.

**Pest.**

- **It writes no machine-readable mutation report.** Its output is console
  text. Under `--parallel` each mutant prints as one character, and only escaped
  and uncovered mutants get a line with an `ID:`. The in-house gate never parses
  it. It reads only the exit code of `--min`.
- **It emits in-process events**, through `Pest\Mutate\Event\Facade`, in the
  Pest process that runs the mutants: `Tested`, `Untested`, `Uncovered` and
  `Timeout` per mutant, and `FinishMutationSuite` at the end. Each carries a
  `MutationTest` with its `Mutation`: file, mutator, start and end line, diff
  and id. A mutant's `duration()` is set only after its event fires, so
  durations, and the mutants that never ran, are read at `FinishMutationSuite`.
  The facade and the events carry no `@internal` tag, but Pest documents none
  of them. The interfaces a Pest plugin implements, `Pest\Contracts\Plugins\*`,
  are `@internal`.
- **It has five statuses:** `none`, `tested`, `untested`, `uncovered` and
  `timeout`. `tested` covers every non-zero exit of a mutant's child process: a
  failed assertion, a crash, and a `--filter` that matches no test, which Pest
  answers with "No tests found." and exit code 1. A covering test whose id does
  not fit Pest's filter pattern is dropped from the filter, which can leave a
  covered mutant `uncovered`.
- **Its timeout is not configurable.** It is the opening run's duration plus
  the larger of 5 s and 20 %, truncated to whole seconds.
- **`covers()` and `mutates()` narrow a run.** Each puts its test file in the
  group `__pest_mutate_only`, and restricts the run to that group and its
  classes unless `--path`, `--class` or `--everything` is given.
- **It caches generated mutants** under its own vendor directory, pointing at
  mutated sources kept beside them. A cache restored without those files turns
  survivors into kills. `--no-cache` turns the cache off.
- **Its opening run always takes the whole suite under coverage**, into a fixed
  path in its vendor directory. `--coverage-php` cannot be combined with
  `--mutate`, and no option takes a map another run wrote. The in-house gate
  patches Pest's vendor code to make it take one. A second patch covers a line
  that every test covers. Pest builds one `--filter` argument naming every
  covering test, and for such a line that argument grows past the kernel's
  limit, so the child process never starts (`posix_spawn() failed: Argument
  list too long`).
- **Mutator names are short class names, and some are shared:** two
  `BitwiseAndToBitwiseOr` classes exist, in different namespaces. `--mutator`
  accepts a fully qualified class name.
- **Each Pest release pins one PHPUnit release.** Pest 5.2.1 requires PHPUnit
  13.3.4 exactly, so a Pest project runs PHPUnit 13.

**Infection.**

- **It does not run Pest suites.** Support was removed in infection PR #2047,
  first released in 0.29.13. Its bundled framework is PHPUnit. Codeception,
  phpspec and Testo adapters install separately.
- **It writes a JSON log, but only when the config file asks** (`logs.json`;
  its only JSON flag, `--logger-summary-json`, writes the totals alone).
  - The log has `stats` and per-status arrays: `killed`,
    `killedByStaticAnalysis`, `escaped`, `timeouted`, `errored`,
    `syntaxErrors`, `uncovered` and `ignored`.
  - Each entry is `{mutator: {mutatorName, originalSourceCode,
    mutatedSourceCode, originalFilePath, originalStartLine}, diff,
    processOutput}`. It has **no mutant id and no duration**.
  - `uncovered` is empty unless `--with-uncovered` is given, even when
    `stats.notCoveredCount` is not.
  - `stats.skippedCount` has no array. The text log (`logs.text`) lists
    skipped mutants under `Skipped mutants:`, each as
    `<n>) <path>:<line>    [M] <mutator> [ID] <hash>` followed by its diff.
  - `--log-verbosity=none` writes no log file at all. `default` and `all` write
    the same JSON.
- **Its per-mutant timeout is `min(5 s + 5 × T, timeout)`**, where `T` is the
  sum of the JUnit times of the test classes covering the mutant, and
  `timeout` is the config value in seconds, 10 by default. A mutant whose `T`
  is at least `timeout` is *skipped*: never run.
- **It reuses a coverage run natively.** `--coverage=<dir>` takes
  `coverage-xml/index.xml` plus one JUnit file, and `--skip-initial-tests` then
  skips its own opening run.
- **It passes `--test-framework-extra-args` to its opening run, and to each
  mutant run less `--configuration`, `--filter` and `--testsuite`**, which its
  PHPUnit adapter declares opening-run only. Each mutant run executes the test
  classes the coverage names, or with `--only-covering-test-cases` the covering
  test methods alone. A mutant run that executes no test counts as escaped.
- **`--mutators=<names>` replaces the config's mutator settings**, so a mutator
  configured in `infection.json5` behaves differently when named on the command
  line.
- **Relative paths in its config resolve against the config file's
  directory**, except `bootstrap`, which resolves against the working
  directory.
- **On GitHub Actions it writes annotations of its own** unless given
  `--logger-github=false`.

**Both.** Neither runner's own mutant id identifies a mutant across machines.
Pest's is `xxh3(realpath . mutator . mutated source)`. Infection's is an md5
over the file path, the mutator name, the mutation's index for that mutator and
its parser attributes. Both change when the checkout moves.

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
   - **id**: the gate's own, 12 lowercase hex characters of a SHA-256 over:
     - the path relative to the repository;
     - the mutator's full name: its class name for Pest, its name for
       Infection;
     - the removed and added lines of the diff, with whitespace collapsed;
     - the mutant's position among mutants in the same file that share
       everything above.

     The line number is not an input, so an id survives code above it moving.
     The runner's native id is kept alongside for use within the same run only.
   - **where and what**: file, start and end line where the runner gives them,
     the mutator's full name, its family (ADR-0009), and the diff.
   - **status**, as the runner reported it:
     - killed, survived, uncovered or errored;
     - timed out, or skipped (Infection's: too slow to run at all). Timeout
       triage judges both at verdict time (ADR-0008);
     - unjudged: no result, because the budget ran out or the runner stopped
       first.

     An Infection mutant that Infection's own config ignored is recorded as
     *ignored by a native marker* (decision 4, ADR-0008). Flaky and ignored
     are otherwise applied later by the gate (ADR-0008).
   - **duration**, where the runner reports one.

3. **The Pest adapter** (`pestphp/pest` ^5.1, `pestphp/pest-plugin-mutate`
   ^5.0, and the PHPUnit 13 release Pest pins).
   - **Invocation:**

     ```text
     vendor/bin/pest --mutate --no-cache --parallel --path=<files>
         [--ignore=<held paths>] [--group=<holds group>] [--processes=<n>]
     ```

     - `--path` makes the gate, not a test's `covers()` or `mutates()`, decide
       what is mutated and which tests judge it. A contract test proves that a
       suite using `covers()` is still judged by every covering test.
     - `--no-cache` keeps a stale cache from deciding a result.
     - `--covered-only` and `--min` are never passed. Uncovered mutants are
       always reported, and `uncovered: exclude` is applied by the gate
       (ADR-0003).
     - Pest runs with the project root as its working directory, and one Pest
       invocation at a time runs in a checkout, because each writes its opening
       map to the same path.
   - **Results** come from a small Pest plugin shipped in this package and
     listed in its `composer.json` under `extra.pest.plugins`, which is how Pest
     finds plugins.
     - It implements Pest's `Bootable` contract, and it is inert unless the
       environment variable `MUTATION_GATE_RESULTS` names a file, which only the
       adapter sets. Inside a mutant's child process it does nothing.
     - At `FinishMutationSuite` it walks the suite's mutants and writes one JSON
       line per mutant: native id, file, lines, mutator class, diff, status and
       duration. That walk is the only place that sees a mutant with no result.
     - The adapter fails closed. The records must add up to the counts on Pest's
       own summary line (`Mutations: … untested, … uncovered, … pending, …
       timeout, … tested`), and a missing file or a mismatch is *cannot judge*.
     - Because the plugin contracts are `@internal` and the events are
       undocumented, only the pest-plugin-mutate versions the contract suite
       covers are allowed, and `composer.json` declares a `conflict` for the
       rest.
   - **Statuses**:

     | Pest status | Gate status |
     |-------------|-------------|
     | `tested` | killed. Pest cannot tell a crash from a failed assertion, so errored never comes from Pest. |
     | `untested` | survived |
     | `uncovered` | uncovered |
     | `timeout` | timed out |
     | `none` left at the end (Pest's *pending*) | unjudged |

   - **Coverage** is an invocation of its own, never part of a mutation run:
     - `vendor/bin/pest --parallel --coverage-php=<dir>/coverage.php
       --log-junit=<dir>/junit.xml` under pcov or Xdebug, without `--coverage`,
       whose own report path would win. The map is read with
       `phpunit/php-code-coverage`, and its `testResults` carry each test's
       duration. This is the layout `--coverage=<dir>` expects from an earlier
       job (ADR-0006).
     - Groups come from `vendor/bin/pest --list-groups --colors=never`. A
       listing without `Available test group` is *cannot judge*, never *no
       groups*.
   - **The in-house gate's two patches are an opt-in.** Enabling it is
     `pest.patch: true` (a boolean, `false` by default), plus
     `@php vendor/bin/mutation-gate pest:patch` in `post-install-cmd` and
     `post-update-cmd`. The command applies both patches to
     pest-plugin-mutate:
     - Shards open on a canary group (`pest.canary`, a group name,
       `mutation-canary` by default) and read the map the planning job wrote,
       instead of each running the whole suite again.
     - A `--filter` that will not fit is dropped, so that mutant runs against
       the whole suite. That can only kill more mutants, never fewer.

     Every anchor is checked before anything is written, and one that has moved
     fails the install: a patch that quietly matched nothing is worse than none.
     With patching enabled, a canary group with no test in it is *cannot
     judge*. Without patching, every shard runs its own opening suite, and a
     line past the filter limit is *cannot judge* with a message pointing at
     the patch.
   - **Timeouts** are Pest's own and cannot be changed, so timeout triage for
     Pest does not rerun anything. It compares the covering tests' own time
     with the limit (ADR-0008).

4. **The Infection adapter** (`infection/infection` ~0.35.0, with PHPUnit 12
   or 13).
   - **Invocation:**

     ```text
     vendor/bin/infection --configuration=<generated> --threads=<n|max>
         --no-progress --with-uncovered --logger-github=false
         [--coverage=<dir> --skip-initial-tests]
         [--test-framework-extra-args=<group or filter>]
         [--only-covering-test-cases] <files>
     ```

     `--log-verbosity` is left at its default, and never `none`.
   - **Configuration.** The adapter writes a config per invocation. It starts
     from the project's own `infection.json5` when there is one (mutators,
     `bootstrap`, `phpUnit`, `initialTestsPhpOptions`, `testFrameworkExtraArgs`)
     and overrides what the gate owns:
     - every path is absolute, and `phpUnit.configDir` is set, because the
       generated file lives under `.mutation-gate/`;
     - `logs.json` and `logs.text` point into the results directory, and every
       other log is off;
     - `timeout` is `timeouts.seconds` (ADR-0008);
     - `minMsi` and `minCoveredMsi` are removed;
     - `tmpDir` is under `.mutation-gate/`.
   - **Held paths** (ADR-0005) always take coverage from their own opening run,
     never from a whole-suite map, so `--skip-initial-tests` is never passed for
     them. The narrowing goes through `--test-framework-extra-args`.
     - A group, `--group=holds:<path>`, narrows the opening run and every
       mutant run.
     - `#[Holds]` becomes a `--filter` naming the holding tests. It narrows the
       opening run, and each mutant run then executes only tests that run
       recorded. The adapter adds `--only-covering-test-cases`, so a mutant
       runs the holding test methods rather than their whole classes.
     - A contract test proves both.
   - **Results.** The adapter reads `logs.json`, and the skipped mutants from
     `logs.text`. The logs must add up, or the run is *cannot judge*:

     | `stats` count | Where the mutants are | Gate status |
     |---------------|-----------------------|-------------|
     | `killedCount` | `killed` | killed |
     | `killedByStaticAnalysisCount` | `killedByStaticAnalysis` | killed |
     | `escapedCount` | `escaped` | survived |
     | `errorCount` | `errored` | errored |
     | `syntaxErrorCount` | `syntaxErrors` | errored |
     | `timeOutCount` | `timeouted` | timed out |
     | `notCoveredCount` | `uncovered` | uncovered |
     | `skippedCount` | `Skipped mutants:` in `logs.text` | skipped |
     | `ignoredCount` | `ignored` | ignored by a native marker when `ignores.native: allow`; otherwise *cannot judge*, because the run refuses native markers before it starts (ADR-0008) |

     Each count must equal the number of its mutants, and `totalMutantsCount`
     must equal the sum of the counts.
   - **Coverage** comes from `vendor/bin/phpunit --coverage-xml=<dir>/coverage-xml
     --log-junit=<dir>/junit.xml`. That is the layout `--coverage` expects, and
     the gate reads its per-line `covered by` entries as its own map, so one run
     serves both. Groups come from `vendor/bin/phpunit --list-groups`.
   - **Scope.** The adapter refuses a `testFramework` other than `phpunit`, and
     a `phpUnit.customPath` that points at Pest. A Pest project uses the Pest
     adapter.
   - **Narrowing to one mutator.** A retry (ADR-0008) or a reproduce runs the
     mutant's file with a generated config whose `mutators` block keeps only
     that mutator, with the project's settings for it. `--mutators` is never
     used, because it drops those settings and the mutant could change. The
     mutant is matched back by the gate's id.

5. **Which tests can judge a file is the runner's answer, not a guess.** The
   content key needs it (ADR-0007).
   - **Pest** selects covering tests with an unanchored regular expression,
     `<Class>::(.*)<name>`. So a test class also selects every class whose name
     ends in the same letters. When the filter is dropped (decision 3), a mutant
     runs against the whole suite. The adapter answers with exactly that set:
     every file whose class ends in a covering test's class name, or every test
     where the filter would not fit. The seed computes the same set.
   - **Infection** runs the covering test cases' classes, and the adapter
     answers with their files.

6. **Reproducing one mutant is runner-neutral.** `mutation-gate reproduce <id>`
   runs the runner over the one file with only that mutator: Pest's `--path`
   with `--mutator=<class name>`, or Infection's positional path with the
   narrowed config of decision 4. It finds the mutant by the gate's id and
   prints the diff, the covering tests and the runner's own output for it.
   Pest's `--id=<native id>` is used for that last run once the native id is
   known on this machine.

7. **One contract suite for every runner.** `tests/Contract/Runner` holds a
   fixture library with a known killed, survived, uncovered, timed-out and
   held mutant. Every adapter must produce the same normalised records for it.
   The library also holds what each runner gets wrong on its own: for Pest, a
   test whose name its filter cannot express; for Infection, a mutant it skips.
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
| **Skipped mutants from Infection's HTML log, or only as a count** | The HTML log's embedded report gives skipped mutants the status `Ignored`, the same as ignored ones. A count alone gives no file, id or diff to report. The text log names each one, and the JSON log's count checks it. |
| **Passing `--covered-only`, or leaving out `--with-uncovered`, under `uncovered: exclude`** | The runners would then report uncovered mutants differently, Infection's log would not add up, and changing the setting would re-run every unit. The gate applies it at verdict time instead. |
| **Patching Pest by default** | Edits another package's vendor code on every install without being asked. Opt-in keeps that a visible decision in the project's own `composer.json`. |
| **Waiting for Pest to offer a report or a shared map** | Not in this package's control. The adapter works with what the supported versions ship, and the contract suite finds out when that changes. |
| **Codeception and phpspec through Infection** | Their coverage and group listing are not PHPUnit's, and nothing in the gate's reach, holds or proof key has been checked against them. An extension can add them later through the same port. |

## Consequences

**Every mutant is on record, from either runner.** The verdict, the baseline,
the proofs, the reports and the hints all read the same normalised records.

**The Pest adapter depends on an undocumented API and `@internal` plugin
contracts.** Each pest-plugin-mutate release has to pass the contract suite
before its `conflict` entry is relaxed. A Pest release that moves them fails
the contract suite, not a user's gate.

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
