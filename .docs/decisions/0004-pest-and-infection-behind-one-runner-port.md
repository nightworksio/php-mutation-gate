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

v1 supports two runners: Pest's own mutation testing
(`pestphp/pest-plugin-mutate`) and Infection. What follows comes from their
source and from scratch projects: pest-plugin-mutate 5.0.2 with Pest 5.2.1, and
Infection 0.35.5, with every option named here checked against 0.35.0 as well.
They differ in ways that shape the adapters.

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

- **It does not run Pest suites.** Releases from 0.29.13 on have no Pest
  adapter (infection PR #2047). Its bundled framework is PHPUnit. Codeception,
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
   | `identity()` | The runner's name, the exact installed version of every package it drives, and a digest of the PHP it runs on, as that PHP describes itself when started the way the runner starts it, without the variables withheld: version, extensions and their versions, every ini setting except the inert ones (ADR-0007), operating system family and architecture. All of these go into the content key (ADR-0007). |
   | `behaviour()` | How the runner behaves where the flows must know it, as a `RunnerBehaviour`: whether it reads `#[Holds]` as its test files load, whether a timeout's limit can be raised, the groups every proof key reads, whether each shard pays its own opening run, why a kill matrix of its run holds first killers only, and whether it runs a mutant per core or one at a time. `RunnerBehaviour::standard()` where it does none of these differently; Pest reads holds as they load and raises no limit, and patched, every key reads its canary, while unpatched, each shard opens on its own run; Infection stops each mutant at its first failing test, so it cannot record every killer; both run a mutant per core |
   | `groups()` | The suite's groups, as the runner itself lists them |
   | `coverage(CoverageRun\|CoverageRead)` | A per-test line map of the whole suite, one group or the tests a filter names, plus each test's duration: by running them for a `CoverageRun`, or by reading the gate's own map another job wrote for a `CoverageRead` |
   | `judges(file, map)` | The test files that can judge a mutant of this file, by the runner's own selection rules (decision 5) |
   | `startUp(file, withheld)` | How long one run of no test takes, started as the runner starts a mutant's own run of the file, whose mutant is an unchanged copy of it, and narrowed by a filter that matches no test (`(?!)`): what every mutant's run pays before its first test (ADR-0006, decision 4). The runner serves the file through the wrapper it serves a mutant through, so the run pays for that too. Pest runs itself with `--no-tia --bail --filter=(?!) --do-not-fail-on-empty-test-suite` in the environment pest-plugin-mutate gives a mutant's run under `--parallel`: `PEST_MUTATION_TESTING` naming the file, `PEST_MUTATION_FILE` naming the copy, `PARATEST`, `TEST_TOKEN`, `UNIQUE_TEST_TOKEN` and `LARAVEL_PARALLEL_TESTING`. It loads every test file, as a mutant's run does. Infection's is the project's PHPUnit with no PHP options, as Infection starts a mutant's, with its extra arguments and the same filter, on a config shaped by the steps of Infection's `MutationConfigBuilder` that change what a run loads. Those steps are: every path absolute from the config's directory, no loggers, coverage reports, colours, printer or default suite, the suites replaced by one holding the covering test files, here none, and the bootstrap replaced by Infection's, which lowers the process's priority, serves the copy through Infection's include interceptor and then loads the config's own bootstrap. So it loads no test file. The steps that only order, stop or report a run (the result cache, the fail-on attributes, stop-on-defect, stderr) are left out. The gate takes those steps itself, since Infection's builder is in the project's vendor, which the gate's process never loads. The run withholds what every child process withholds |
   | `mutate(request)` | Every mutant's normalised result for some files, judged by the whole suite or by a group, under a deadline |
   | `retry(request, mutants, limit)` | The same, for a few mutants run again, the invocation's request narrowed to them (ADR-0008) |
   | `reproduce(mutant, request, limit)` | One mutant run again on its own, the request narrowed to its file and its mutator, with what the runner printed (decision 6) |
   | `markers(files)` | The runner's own ignore markers in those files and in its config, each with the `ignores.entries` entry that replaces it (ADR-0008). Whether a run may go ahead with them is the verdict's to decide |
   | `definitions()` | The files that define how the runner runs, as paths from the project's root. Pest names `tests/Pest.php` and the PHPUnit config in the root; Infection names its config under each of its four names and the PHPUnit config in `phpUnit.configDir`, or in the root where the config sets none. Each PHPUnit config is named by each of the names PHPUnit looks for: `phpunit.xml`, `phpunit.dist.xml` and `phpunit.xml.dist`. A change to one reaches everything (ADR-0005), and every content key reads them (ADR-0007) |
   | `names(tests, withheld)` | Each test by its file and the description the runner gives it, or the data set row that folds into it (ADR-0014, decision 6). Infection names a test from the file that declares its class and its method. Pest lists the suite's tests, running none, and its plugin names each one. That listing withholds what every child process withholds |
   | `rootedAt(package)` | The same runner in a package's directory, with the package's tests, vendor and gate directory (ADR-0005, decision 7). A directory where the runner is not installed cannot be judged |

   A failed opening run, an unsupported version or a result that does not add up
   is returned as *cannot judge* with the runner's output (ADR-0001). The run
   stops with exit code 2.

2. **Every mutant is normalised to one record.**
   - **id**: the gate's own, 12 lowercase hex characters of a SHA-256 over:
     - the path relative to the repository;
     - the mutator's full name: its fully qualified class name for Pest, its
       name for Infection;
     - the removed and added lines of the diff, with whitespace collapsed;
     - the mutant's position among mutants in the same file that share
       everything above.

     The line number is not an input, so an id survives code above it moving.
     The runner's native id is kept alongside for use within the same run only.
   - **where and what**: file, start and end line where the runner gives them,
     the mutator's full name, its family (ADR-0009), and the diff.
   - **status**, as the runner reported it:
     - killed, survived, uncovered or errored;
     - timed out, or skipped (Infection's: too slow to run at all). Retries
       run in the shard, and the timeout rule is applied at verdict time
       (ADR-0008);
     - unjudged: no result, because the budget ran out, the runner stopped
       first, a covering test could not be put in Pest's filter (decision 3),
       or decision 8 could not judge it, with its reason.

     An Infection mutant that Infection reports as ignored (its
     `ignoreSourceCodeByRegex`) is recorded as *ignored by a native marker*
     (decision 4, ADR-0008). Flaky and ignored are otherwise applied later by
     the gate (ADR-0008).
   - **duration**, where the runner reports one.
   - **limit**, for a timed-out or skipped mutant: the seconds the runner
     allowed it, and whether it was retried. Timeout triage needs both
     (ADR-0008).

3. **The Pest adapter** (`pestphp/pest` ^5.1, `pestphp/pest-plugin-mutate`
   ^5.0, and the PHPUnit 13 release Pest pins).
   - **Invocation:**

     ```text
     <vendor>/pestphp/pest/bin/pest --mutate --no-cache --parallel --no-tia --everything
         --covered-only=false --stop-on-untested=false
         --stop-on-uncovered=false --retry=false --path=<files>
         --ignore=<held paths, or .mutation-gate>
         [--group=<holds group> --do-not-fail-on-empty-test-suite]
         [--mutator=<class names>]
     ```

     - Every run narrowed to a group also passes
       `--do-not-fail-on-empty-test-suite`. Under `--parallel`, Pest can sum a
       run that ran every test of the group as one with no tests, and fail it.
       A narrowed run with none of the group's tests passes too, so it kills
       no mutant.
     - `--path` makes the gate, not a test's `covers()` or `mutates()`, decide
       what is mutated and which tests judge it. A contract test proves that a
       suite using `covers()` is still judged by every covering test.
     - `--no-cache` keeps a stale cache from deciding a result.
     - `--min` is never passed, and the options after `--no-tia` undo what a
       project's own mutation config could set: covered lines only, a class
       list, an ignore list, a stop at the first escaped or uncovered
       mutant, and escaped mutants first. Uncovered mutants are always
       reported, and `uncovered: exclude` is applied by the gate (ADR-0003).
       A run that reaches its end is judged by its records whatever its exit
       code, since a project's own minimum score fails a finished run.
     - `--processes` is never passed. pest-plugin-mutate hands it on to each
       mutant's own run, which is not parallel and rejects it, so every
       covered mutant would read as killed. Pest runs one mutant per core,
       and a request's process count does not apply to it. The gate counts
       the cores as pest-plugin-mutate does, with `fidry/cpu-core-counter`,
       once per run, on the same machine, so the two counts agree.
     - A runner's behaviour says whether it runs a mutant per core or one
       at a time; one an extension adds runs one at a time unless it says
       otherwise. Pest and Infection run one per core, and every request the
       gate makes of them asks for every core: `<n>` in Infection's
       `--threads=<n>` is that count.
     - `<vendor>` is where Composer installed the project's packages:
       `COMPOSER_VENDOR_DIR`, then `config.vendor-dir`, then `vendor`. The
       gate reads Pest's versions from `<vendor>/composer/installed.json` and
       checks `pest:patch` there too. Pest's own script loads
       `vendor/autoload.php` from the directory that holds its vendor
       directory, so Pest runs only where that directory is named `vendor`,
       such as `lib/vendor`.
     - Pest runs on the PHP the gate runs on, which is first on its path,
       because Pest starts each mutant's run through the same script. It
       inherits the environment the gate's PHP started with, where a variable
       set later by `putenv()` alone does not reach it. It inherits none of
       the variables that make a process a paratest worker or a mutant's run,
       none of the gate's own, and none of the variables a request withholds.
       Every request withholds the CI's credentials (`AWS_*`, `ACTIONS_*`,
       `GITHUB_TOKEN`, `SONAR_TOKEN`) and the secrets the gate itself reads
       (`OTEL_EXPORTER_OTLP_HEADERS` and the alert channels'
       `MUTATION_GATE_*_URL` and `MUTATION_GATE_WEBHOOK_SECRET`), each CI plan
       adds its own CI's tokens (such as GitLab's `CI_JOB_TOKEN` and
       Buildkite's `BUILDKITE_AGENT_ACCESS_TOKEN`), and `runner.withhold`, a
       list of names or globs, adds a project's own. The list only ever grows.
     - Pest runs with the project root as its working directory, and one
       `--mutate` invocation at a time runs in a checkout, because each writes
       its opening map to the same path.
   - **Where Pest's filter cannot hold the covering tests.** The adapter builds
     Pest's filter from the gate's coverage map, as Pest does. A mutant with a
     covering test whose id that filter cannot express is recorded as
     unjudged, with the test named as the reason, never as killed or uncovered:
     Pest would have run no test for it and called the mutant killed, or
     dropped the test and called it uncovered. The filter names a test by
     the class after a namespace separator, so a PHPUnit class outside any
     namespace is one it cannot express: a PHPUnit class run by Pest belongs
     in a namespace.
   - **A small Pest plugin** ships in this package, listed in its
     `composer.json` under `extra.pest.plugins`, which is how Pest finds
     plugins. It implements Pest's `Bootable`, `TestCaseMethodFilter` and
     `HandlesArguments` contracts, and it does six jobs. It turns `#[Holds]`
     into groups and reports results, both described below. For decision 8,
     it keeps the mutated file of each mutant Pest leaves uncovered on a line
     that is not executable, and it guards each judging run. In a mutant's
     child process it records the first test that fails, and it orders the
     child's tests (ADR-0013, decisions 1 and 3). For a full kill matrix it
     drops `--bail` there and records every test that fails (ADR-0014,
     decision 7).
   - **It turns `#[Holds]` into groups** (ADR-0005).
     - In `boot()` it registers itself as a filter on Pest's test repository.
       Pest boots plugins after it loads `tests/Pest.php` and before it loads
       any other test file, in the main process, in every `--parallel` worker
       and in every mutant's child process.
     - Pest passes each test to the filter as it registers it, before it
       builds the test's class. That is the point where Pest turns its own
       `->group()` calls into PHPUnit `#[Group]` attributes.
     - The filter reads `#[Holds]` by reflection from the test's closure, and
       from the closure of every `describe` it is registered inside, which it
       finds on the call stack. For each path it adds
       `#[Group('holds:<path>')]` to the test, once. It never drops a test.
     - It does this in every Pest run, the gate's or not. A mutant's child
       process that lost the group would select no test, and Pest counts that
       as a kill.
     - Datasets inherit the group, because Pest puts it on the method that
       carries the data provider.
   - **It reports results.** This part is inert unless the environment variable
     `MUTATION_GATE_RESULTS` names a file, which only the adapter sets. A
     mutant's child process inherits it, and there the plugin appends the
     mutated file Pest serves and the id of the first test that fails
     (ADR-0013, decision 1), or of every test that fails under a full kill
     matrix (ADR-0014, decision 7).
     - At `FinishMutationSuite` it walks the suite's mutants and writes one JSON
       line per mutant: native id, file, lines, mutator class, diff, status and
       duration, and one line with the opening run's duration, from which the
       adapter computes each mutant's limit. That walk is the only place that
       sees a mutant with no result.
     - The adapter fails closed. The records must add up to the counts on Pest's
       own summary line (`Mutations: … untested, … uncovered, … pending, …
       timeout, … tested`), and a missing file or a mismatch is *cannot judge*.
       So is a whole record that names an event, or a status, the plugin does
       not write, lacks a field its event carries, or places a mutant on a line
       no file has. The last line, which a run stopped while it wrote leaves
       cut short, is skipped when it is not JSON; any other line that is not
       JSON refuses the file, since a line lost there could be the test that
       killed a mutant first. The plugin writes a status it does not know as
       Pest names it, for the adapter to refuse, and fails rather than write
       a record JSON cannot hold. The plugin and the adapter write and read the lines through
       one protocol: its events, its fields and Pest's statuses are each
       spelled once.
     - The plugin relies on Pest APIs that are `@internal`: the plugin
       contracts, the test repository, the test's closure and attributes, and
       the `describe` call's closure. It also relies on the mutate plugin's
       events, which are undocumented. So only the pest-plugin-mutate versions the contract suite
       covers are allowed, and `composer.json` declares a `conflict` for the
       rest.
   - **Statuses**:

     | Pest status | Gate status |
     |-------------|-------------|
     | `tested` | killed. Pest cannot tell a crash from a failed assertion, so errored never comes from Pest. |
     | `untested` | survived |
     | `uncovered` | uncovered, or judged by decision 8 on a line that is not executable |
     | `timeout` | timed out |
     | `none` left at the end (Pest's *pending*) | unjudged |

   - **Coverage** is an invocation of its own, never part of a mutation run:
     - `<vendor>/pestphp/pest/bin/pest --parallel --coverage-php=<dir>/coverage.php
       --log-junit=<dir>/junit.xml` under pcov or Xdebug, without `--coverage`,
       whose own report path would win. The map is read with
       `phpunit/php-code-coverage`, and its `testResults` carry each test's
       duration. That map is PHP, which reading runs, so only the job that
       wrote it reads it.
     - A map another job hands over with `--coverage=<dir>` is only ever the
       gate's own format 1 map in that directory (ADR-0006). A runner's
       `coverage.php` there is refused. For a shard that opens on the canary
       group, the job writes the handed-over map again as `--coverage-php`
       writes one, beside its results, and its Pest loads that.
     - Groups come from `<vendor>/pestphp/pest/bin/pest --list-groups --colors=never`. A
       listing without `Available test group` is *cannot judge*, never *no
       groups*.
   - **The in-house gate's patches are an opt-in.** Enabling them is
     `pest.patch: true` (a boolean, `false` by default), plus
     `@php vendor/bin/mutation-gate pest:patch` in `post-install-cmd` and
     `post-update-cmd`. The GitHub action runs `pest:patch` itself after it
     installs the project, wherever the effective config runs Pest with
     `pest.patch` on, so the Composer hook serves every other run. The
     command applies every patch to pest-plugin-mutate:
     - Shards open on a canary group (`pest.canary`, a group name,
       `mutation-canary` by default) and read the map the planning job wrote,
       instead of each running the whole suite again.
     - A `--filter` that will not fit is dropped, so that mutant runs every
       test its run loads. That can only kill more mutants, never fewer.
     - A run again makes only the mutants whose native ids a file beside the
       results lists.
     - A mutant's own run loads only the test files its covering tests need:
       the file that declares each covering test's class, and every test file
       Pest's parent process loaded that declares a name those use, in turn.
       The names are functions, classes, interfaces, traits and enums, and
       constants declared by `const` or `define()`, used in code or, fully
       qualified, in a quoted string. Where a covering test's class is not
       loaded, or the paths would not fit in the bytes a filter may take, it
       loads every test file. The patched plugin records the files each
       narrowed run loads.
     - A test file can also give another what it needs by what loading it
       does, which no name shows. So every narrowed run also loads each test
       file that is not inert, with what it needs. A test file is inert where
       its top only declares, imports, and makes the Pest registrations whose
       effects stay in the file: `test`, `it`, `todo`, `arch`, `describe`,
       `beforeEach`, `afterEach`, `beforeAll`, `afterAll`, `dataset`,
       `covers` and `uses`. Each is one call with methods chained on it, none
       of them `->in()`, whose arguments run nothing as the file loads:
       literals, constants, class names, arrays of these, and closures. Pest
       runs two closures as the file loads, so each is held to more: a
       `describe` body, whose statements must each be such a registration in
       turn, and a dataset handed to `dataset` or `->with()` as a closure,
       which may only return or yield what runs nothing. Anything else acts,
       at the top, in a `describe` body or in a dataset closure: a hook or a
       trait sent `->in()` a directory, `pest()` and `mutates()`, which change
       Pest's configuration, a write to `$_ENV`, the environment or
       `$GLOBALS`, an include, a call, or any other statement. The files Pest
       loads in every process, `tests/Pest.php` and its kind, are loaded
       anyway.
     - A narrowed kill counts only where the run can vouch for it:
       - a kill with no test named as its killer, or only tests that errored,
         does not count, as a helper a test calls by a name built at run time
         leaves;
       - any other counts only where every test in the files its run loaded
         passes on the unmutated code, loaded alone as that run loaded them.
         That run is made once for each set of files, and kept while the gate
         runs.

       A kill that does not count runs again with every test file within the
       time left, or is unjudged. A kill whose records cannot be read does
       not count either.
     - What a test's body leaves behind as it runs is not seen: a test that
       writes a global, a static or a file while it runs, which a test in
       another file reads, passes or fails by whether that other test ran
       first. Where a test needs that, and its narrowed run leaves it out, a
       kill can count that a run with every test file would not have made.

     Every anchor is checked before anything is written, and one that has moved
     fails the install: a patch that quietly matched nothing is worse than none.
     With patching enabled, a canary group with no test in it is *cannot
     judge*. Without patching, every shard runs its own opening suite, and a
     line past the filter limit is *cannot judge* with a message pointing at
     the patch.
   - **Timeouts** are Pest's own and cannot be changed, so timeout triage for
     Pest does not rerun anything. It compares the judging tests' own time
     with the limit (ADR-0008).

4. **The Infection adapter** (`infection/infection` ~0.35.0, with PHPUnit 12
   or 13).
   - **Invocation:**

     ```text
     vendor/bin/infection --configuration=<generated> --threads=<n>
         --no-progress --no-interaction --with-uncovered --logger-github=false
         --coverage=<dir> --skip-initial-tests
         [--test-framework-extra-args=<the project's, and a group or filter>]
         [--only-covering-test-cases] <files>
     ```

     - `--log-verbosity` is left at its default, and never `none`.
     - Infection never runs an opening suite of its own. It always reads a
       coverage directory the adapter wrote in its own job. For a run judged
       by the whole suite that reuses the planning job's coverage, the adapter
       writes the gate's map that job handed on (ADR-0006) into the layout
       below. That covers each file's lines and the tests that ran them, the
       methods its report says some test ran, with their lines, and a JUnit
       log with a suite for each test class, holding the file its tokens say
       declares it and its tests' times. Otherwise the adapter writes the
       directory by running PHPUnit under coverage (the **Coverage** item
       below). No report another job wrote is read. The adapter reads each
       mutant's limit from that directory's JUnit log, which is what
       Infection sums, and Infection deletes its own opening run's log when it
       finishes.
     - Infection and PHPUnit run on the PHP that runs the gate, with that PHP's
       directory first on the `PATH`, because Infection starts PHPUnit for each
       mutant through the script's `#!` line. They inherit no variable of
       another run (`INFECTION_*`, `MUTATION_GATE_*`, `PEST_MUTATION_*`,
       `PARATEST`, `TEST_TOKEN`, `UNIQUE_TEST_TOKEN`) and no credential
       (`AWS_*`, `GITHUB_TOKEN`, `SONAR_TOKEN`, `ACTIONS_*`, the gate's own
       secrets), because the
       project's tests and every mutant of its code run in them.
     - A project withholds its own credentials from either runner with
       `runner.withhold`, a list of variable names or globs whose `*` stands
       for any run of characters, such as `DEPLOY_*` or `COMPOSER_AUTH`. It
       only adds to what every run withholds, and it only grows: each layer of
       config adds its own to the earlier layers', whichever runner a later
       layer chooses, and a layer may write `withhold` alone for zero-config to
       find the runner. It is not part of a proof's key (ADR-0007).
     - A run stopped at its deadline is *cannot judge*: Infection writes its
       logs only when it finishes, so no mutant of such a run has a result.
       A stopped run is stopped with every process it started.
   - **Configuration.** The adapter writes a config per invocation. It reads
     the project's own config where there is one, the first of
     `infection.json5`, `infection.json`, `infection.json5.dist` and
     `infection.json.dist`, as Infection does.
     - It keeps only these keys of it: `mutators`, `bootstrap`, `phpUnit`,
       `initialTestsPhpOptions`, `testFramework`, `staticAnalysisTool`,
       `staticAnalysisToolOptions`, `phpStan` and `mago`. The project's
       `testFrameworkExtraArgs` go on the command line, before the gate's own
       narrowing. Every other key either belongs to the gate or could change
       which mutants Infection makes or how it stops (`source`, `minMsi`,
       `minCoveredMsi`, `maxTimeouts`, `timeoutsAsEscaped`,
       `ignoreMsiWithNoMutations`, `threads`, `logs` and any key a later
       release adds), so the gate writes it itself or leaves it out.
     - Every path is absolute, and `phpUnit.configDir` is set, because the
       generated file lives under `.mutation-gate/`.
     - `source.directories` are the directories of the files the run mutates.
     - `logs.json` and `logs.text` point into `.mutation-gate/infection/logs/`,
       and every other log is off.
     - `timeout` is `timeouts.seconds` (ADR-0008), or the doubled cap of a
       retry.
     - `tmpDir` is under `.mutation-gate/`.
     - A run is judged by its logs and their counts, never by Infection's exit
       code. The logs, the generated config and the coverage reports an
       earlier run left are removed before a run, and a file that cannot be
       removed is *cannot judge*.
   - **Held paths** (ADR-0005) always take coverage from their own opening run,
     never from a whole-suite map. The adapter runs PHPUnit under coverage
     narrowed to the holding tests, and Infection reads that run's directory.
     The narrowing also goes through `--test-framework-extra-args`.
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
     | `killedByStaticAnalysisCount` | `killedByStaticAnalysis` | killed by static analysis |
     | `escapedCount` | `escaped` | survived |
     | `errorCount` | `errored` | errored |
     | `syntaxErrorCount` | `syntaxErrors` | errored |
     | `timeOutCount` | `timeouted` | timed out |
     | `notCoveredCount` | `uncovered` | uncovered |
     | `skippedCount` | `Skipped mutants:` in `logs.text` | skipped |
     | `ignoredCount` | `ignored` | ignored by a native marker when `ignores.native: allow`; otherwise *cannot judge*, because the run refuses native markers before it starts (ADR-0008) |

     Each count must equal the number of its mutants, and `totalMutantsCount`
     must equal the sum of the counts.

     A mutant under `killed` carries the tests that killed it (ADR-0014): the
     ones PHPUnit's output, the entry's `processOutput`, lists under `There was
     1 failure:` or `There were 2 errors:`. Each is named as the coverage map
     names it, `<class>::<method>` with `#<data set>` for a data set's test.
     Infection's mutant runs stop at the first defect, so that is the first
     killer. A mutant killed by static analysis, an error or a timeout, or
     with no such list, carries none, and one killed by static analysis
     carries no rejection either, since the log names no finding.
   - **Coverage** comes from `vendor/bin/phpunit --coverage-xml=<dir>/coverage-xml
     --log-junit=<dir>/junit.xml`, or `phpUnit.customPath`, with
     `initialTestsPhpOptions` as PHP options, the project's
     `testFrameworkExtraArgs` and, for a held path, its group or filter. That
     is the layout `--coverage` expects, and the gate reads its per-line
     `covered by` entries as its own map, so one run serves both. The map
     also keeps the methods Infection finds a signature mutant's tests by:
     each method of the report's classes or, where they have none, of its
     traits, with the start and end lines the report gives it, less each
     whose coverage Infection reads as none (under one percent). Groups come
     from `vendor/bin/phpunit --list-groups`.
   - **Limits.** A timed-out or skipped mutant's limit is `min(5 s + 5 × T, timeout)`, where `T`
     is the sum of the JUnit times of the test classes covering its first
     line, each counted once, as Infection computes it.
   - **Native markers** (ADR-0008) are `@infection-ignore-all` in a comment of
     the files asked for, and each value of `ignore` or
     `ignoreSourceCodeByRegex` under `mutators` in the project's config: under
     a mutator, a profile, `global-ignore` or
     `global-ignoreSourceCodeByRegex`.
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
   - **Under Pest, a unit whose tokens hold a class or interface constant, a
     property default, an enum case, a plain function's or closure's parameter
     default or an attribute argument** can have mutants that decision 8 judges
     by reference, so for it the answer is every test file.

   A mutant's *judging tests* are the tests that decide its result: its
   covering tests, or for decision 8 the test files it selected and, where
   used, the fallback. Timeout triage, flaky triage and hints name these
   (ADR-0008, ADR-0009).

6. **Reproducing one mutant is runner-neutral.** `mutation-gate reproduce <id>`
   runs the runner over the one file with only that mutator: Pest's `--path`
   with `--mutator=<class name>`, or Infection's positional path with the
   narrowed config of decision 4. It finds the mutant by the gate's id, or a
   unique prefix of six or more, in the newest record the readable ledgers
   hold, and runs it under the conditions of the run it came from: by the
   tests that judge its unit, allowed the configured time limit, withholding
   what a run withholds, and under the memory cap of decision 9. It prints
   the diff, what was recorded, what the run found, the judging tests and the
   runner's own output for it. It exits 0 where the run
   finds what was recorded, 1 where it finds something else, and 2 where no
   ledger read holds the mutant, the run no longer makes it, or the runner
   cannot judge. A mutant decision 8 judged is re-run through decision 8's
   steps instead, against its judging tests, because Pest reports it
   uncovered before any test runs.

7. **One contract suite for every runner.** `tests/Contract/Runner` holds a
   fixture library with a known killed, survived, uncovered, timed-out and
   held mutant. Every adapter must produce the same normalised records for it.
   The library also holds what each runner gets wrong on its own: for Pest, a
   test whose name its filter cannot express; for Infection, a mutant it skips.
   For Pest's `#[Holds]`, the suite asserts the exact set of tests Pest runs
   for `--group=holds:<path>`, serially and under `--parallel`, and in a
   mutant's child process:

   - a held `it()`, `test()` and `arch()` closure, with `function` and with
     `fn`;
   - a held closure whose path is a class constant, not a literal;
   - a PHPUnit class with `#[Holds]` and its matching `#[Group]`, run by Pest;
   - a held `describe`, with a nested `describe`, a higher-order test and a
     skipped test inside;
   - a held test with a dataset, where every row is selected;
   - a test that covers the path but does not hold it, which is never
     selected;
   - two `#[Holds]` on one closure;
   - `--list-groups`, which shows every `holds:` group.

   CI runs the suite against the lowest and highest supported version of each
   runner (ADR-0011).

8. **A mutant on a line that is not executable is judged by the tests that
   reference its symbol** (Pest only).
   - **Which mutants.** php-code-coverage leaves some lines out of the
     coverage map altogether: class and interface constants, enum cases,
     property declarations, parameters of plain functions and closures, and
     attribute arguments. Pest marks every mutant there *uncovered* and runs no
     test. The gate takes these mutants from the `Uncovered` events, reads
     what the first token the mutated copy writes differently stands in, and
     judges them itself.
     - A global `const` or `define()` sits on an executable line, so an
       uncovered mutant there stays uncovered.
     - pest-plugin-mutate makes no mutant of a string-backed enum case's
       value, only of an int-backed one, so under Pest a string case's value
       is never mutated, and the gate has no mutant of it to judge.
     - Infection never generates mutants on constants, enum cases, property
       declarations or attribute arguments: it mutates only inside functions
       and their signatures. A parameter default is in a signature, so
       Infection mutates it and reports it as it reports any mutant. A
       contract test proves both.
   - **Which tests judge it.** A scan of tokens, with names resolved through
     namespaces, imports and aliases, finds every reference to the symbol:
     - in test files, the test files themselves;
     - in source files, the test files that cover the line of each reference,
       read from the coverage map. So a constant read through `self::RATE`
       inside a covered method is judged by that method's tests. References
       are followed through constant expressions and defaults, at most three
       steps deep, and a longer chain counts as ambiguous.

     What counts as a reference:
     - `Owner::NAME`, and `self::`, `static::` and `parent::` inside the owner
       and its subclasses;
     - an inherited or interface constant through any class that extends or
       implements its owner;
     - an enum case through `Enum::Case`, and a case's backing value through
       any use of the enum, because `from()`, `tryFrom()`, `cases()` and
       serialisation all read it;
     - a static property through `Owner::$name`;
     - an instance property's default through any creation of the owner;
     - a plain function's parameter default through the covering tests of the
       lines that call it.

     An attribute's argument, a closure's parameter default and a method's
     parameter default have no reference a token scan can follow, so they are
     always ambiguous. In a
     held unit (ADR-0005), every set this decision names, the fallback
     included, is limited to the holding group.
   - **The fallback** is the set of test files that cover the owner's file.
     It is used when it has at most 10 test files:
     - when the scan is ambiguous (a variable class such as `$class::NAME`, a
       `constant()` call on a non-literal name, reflection on the owner, or
       `static::NAME` that a subclass overrides), the fallback is added to
       what the scan found;
     - when the direct references leave the mutant alive, it runs against the
       fallback too before it counts as a survivor.

     An ambiguous mutant whose fallback is over the bound is unjudged, and its
     reason says why, for example *ambiguous reference; src/Theme.php is
     covered by 152 test files*. An unambiguous survivor whose fallback is over the bound
     stays a survivor.
   - **No reference** makes the mutant unjudged, with the reason *no test
     reaches this value*. It is never passed.
   - **How it runs.** Pest serves a mutated file to a process through an
     override that its mutate plugin registers on every boot when
     `PEST_MUTATION_TESTING` names the original file and `PEST_MUTATION_FILE`
     names the mutated copy. That happens with or without `--mutate`, and in
     every `--parallel` worker, which inherits the environment. The gate uses
     the same mechanism Pest uses for its own mutants:
     1. The plugin copies each uncovered mutant's mutated file from its
        `Uncovered` event to `.mutation-gate/pest/mutants/<native id>.php`.
        Every run of the adapter starts with none left from an earlier one.
     2. The selected tests run once as they are, narrowed as in step 3, once
        for each set of test files. If they fail, the mutant is unjudged:
        *the selected tests fail on their own*.
     3. For each mutant, one at a time, the gate runs
        `<vendor>/pestphp/pest/bin/pest --no-tia --bail --colors=never` over
        the selected test files, with `--group=holds:<path>` for a held unit,
        from the project root, within Pest's own limit, withholding what the
        request withholds, with the two variables and `MUTATION_GATE_GUARD`
        set.
     4. A failing run kills the mutant, and a passing run leaves it alive for
        the fallback above. A run stopped at the limit times the mutant out.
        A missing mutated file makes it unjudged, *mutated file missing*,
        never killed.
   - **Guards.** When `MUTATION_GATE_GUARD` names a file, the plugin writes to
     it whether the original file was loaded before the override started,
     whether it was loaded at all, and the opcache settings. Composer's
     `files` autoload, `tests/Pest.php` and datasets can load a class before
     any plugin starts. Each of these makes the mutant unjudged, with its
     reason:
     - *loaded before the override*;
     - *never loaded*;
     - `opcache.enable_cli` or `opcache.file_cache` on, because a cached
       original could be served instead of the mutant;
     - no guard written at all: *the run wrote no guard, so the gate cannot
       tell the mutated file ran*.
   - **Costs.** Each run's time goes into the cost model like any other
     mutant's (ADR-0006).
   - **Contract tests**, one per kind of symbol:
     - a class constant named in a test through an alias;
     - a constant read only through `self::` in a covered method;
     - an inherited constant, and an interface constant;
     - an enum case's `->value`, a value seen only through `from()`, and a
       duplicate value;
     - a static property default, and an instance property default reached
       only through the fallback;
     - a plain function's parameter default, a closure's parameter default,
       and an attribute's argument;
     - a global `const` in a `files` autoload file, which stays uncovered,
       and a function's parameter default there, loaded before the override;
     - no reference, and a same-named constant in another class that does not
       count;
     - `$class::NAME` inside the bound, with two test files as arguments, and
       beyond it, and a chain four steps deep;
     - a held unit, where only the holding group judges;
     - a symlinked project root, and an enum `tests/Pest.php` loads before the
       override;
     - Infection, which emits no mutant on a constant, an enum case, a
       property or an attribute argument, and one on a parameter default.

9. **Every PHP process a mutation run starts has a memory cap.**
   - `runner.memory` is the `memory_limit` of each PHP process a mutation
     run starts: the runner's own, its opening run of the suite, and each
     mutant's process, and so of each reproduction (decision 6). It is written as PHP writes it, a whole number of
     bytes, `K`, `M` or `G`, such as `512M`. It is `1G` by default, and `-1`
     for none. A mutant that runs away with memory then stops alone, rather
     than taking the machine and every mutant still to run on it with it.
   - The adapter writes `memory_limit` to an ini file in a directory of its
     own in the run's workspace, and adds that directory to
     `PHP_INI_SCAN_DIR`, after the directories PHP scans already. A runner
     starts each mutant as a PHP process of its own, which takes none of the
     options of the command that started the runner but inherits its
     environment. A cap the adapter cannot write makes the run *cannot
     judge*.
   - A project that sets `memory_limit` itself sets it after PHP reads the
     cap, and so wins over it: with `<ini name="memory_limit">` under `<php>`
     in its PHPUnit config, or with `ini_set()` in a bootstrap file. doctor
     finds the first; the second shows only when the tests run.
   - Coverage runs, listing the tests, and the gate's own process run
     without the cap; the gate's own process takes the `memory_limit` its
     ledgers need (ADR-0013, decision 13).
   - The plan weighs the suite against the cap in force: `runner.memory`,
     or the `memory_limit` of the PHPUnit config the runner reads (the
     project root's for Pest; for Infection, the one in `phpUnit.configDir`,
     or the root's where its config sets none) where that is higher or none.
     Where the largest process of the coverage run it has just run held
     more, the plan is *cannot judge*, and says to raise `runner.memory`. The
     peak is the most resident memory `getrusage` counts for the processes
     the gate waited for, an upper bound on what `memory_limit` counts, so a
     suite near the cap can be refused though its mutants would fit. Where
     the system counts no such peak, or the plan reads a map another job
     wrote, it plans.
   - The plan owns the peak. It records the peak of the coverage run it
     planned from as `peak`, in bytes, within its digest, and every shard
     reads it from there, so a shard that reads the map the plan job handed
     over triages by the plan job's measurement. A plan without `peak`,
     made from a map another job wrote, or before plans recorded it,
     measured none.
   - A mutant whose own process ran out of exactly `runner.memory`, to the
     byte, is *out of memory*. PHP's fatal error names the limit it ran out
     of, and PHP logs it as it happens, before any shutdown function: Pest
     and PHPUnit end the process, or run out of memory themselves, before
     anything registered later could write it. So Pest's plugin logs PHP's
     errors in each mutant's own process to a file of that mutant's own
     (`log_errors` and `error_log`, which decide nothing a test computes),
     and reads it in the process that started the mutant; a test or config
     that sets `error_log` itself logs elsewhere. The Infection adapter reads
     the error from the output Infection logs for a mutant it counts killed
     or errored, which is the mutant's standard output alone, and the
     PHPUnit it runs sends `error_log` to a file of its own around each
     test, lost with the process. So the cap's ini file for an Infection run also sets
     `display_errors=stdout`. That decides where an error is shown and
     nothing a test computes: PHPUnit's own handler still takes every error a
     test raises, and only one raised outside it, such as in a bootstrap
     file, now also prints on standard output. A project that shows errors
     nowhere again leaves only PHPUnit's word that its process ended
     mid-test, which PHPUnit also prints after `exit` or `die`. So, under a
     cap, such a mutant is out of memory with no limit known, so too heavy
     to judge, only where the hiding is visible: the project's PHPUnit
     config sets `display_errors` in its `<ini>` to print nowhere or on
     standard error, or PHPUnit 12.5 says it hid the error. Errors hidden at
     runtime, such as by `ini_set` in a bootstrap file, are not seen, so a
     mutant out of the cap there is a false kill. Where the hiding is
     visible, an `exit` or `die` mid-test prints the same sentence, so it
     reads as too heavy to judge too. A limit the project set itself, any
     other fatal error PHP shows, `exit` and `die` where errors are not
     visibly hidden, the system's own out-of-memory killer and a crash keep
     the status the runner gave them.
   - Memory triage judges a mutant out of memory the way timeout triage
     judges a timeout (ADR-0008). Where the plan's peak is at most half the
     cap, the cap holds at least twice what the suite needs, so the mutant
     needed far more than its suite and ran away:
     it is *killed by the memory cap*, and counts as killed, with no killer
     named. Otherwise, or where the plan measured no peak, it is *too heavy
     to judge*, and counts as not killed, under the `unjudged` rule. Twice
     is one policy with doctor's `memory-cap-near`, which advises a cap of
     twice the peak.
   - A result records a mutant out of memory with its cap and the plan's
     peak, in bytes. A carried one is unjudged, as a timeout is, since
     triage can count it as a kill; a proved one is judged again under the
     peak it recorded.
   - Where the runner's own process runs out of the cap, the opening run
     of the suite included, the run is *cannot judge*, and says to raise
     `runner.memory`.
   - The cap's ini file is written, whole, into a directory of the runner's
     workspace for each process of the gate, which it empties first and
     removes when the run is done. A link at any level from the gate's own
     directory down to that one is refused; the levels above it are the
     project's, which the gate reads through as it reads the project. An
     entry other than a file in that directory is refused before a run;
     after one, the files are removed and the directory is left, so the next
     run refuses it.
   - The cap can change a mutant's result, so it is part of a proof's key
     (ADR-0007).
   - doctor's findings on it are advice (ADR-0017, decision 10):
     `memory-uncapped` where it is `-1`; `memory-cap-lifted` where the
     project's PHPUnit config sets a higher `memory_limit`, or none; and,
     under `--measure`, `memory-cap-near` where the suite's processes held
     over half the cap, by the most resident memory `getrusage` counts for
     the processes the gate waited for.

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
| **Patching a source file in place to judge a mutant on a line that is not executable, then restoring it** | Every other process sees the mutant meanwhile: an editor, another run, and Pest generating mutants from that same file. A run killed at its deadline leaves mutated source that a pre-push could commit before the next run restores it. Pest's own override changes nothing on disk. |
| **Pest's `--id` with a forced test set, for such a mutant** | Pest decides *uncovered* before it starts any process, so no option reaches the test run. |
| **Counting such mutants as uncovered, or leaving them out** | A constant or an enum value a test depends on would then be either always against the project or never judged, whatever the tests assert. Judging it against the tests that reference it gives the real answer. |
| **Patching Pest by default** | Edits another package's vendor code on every install without being asked. Opt-in keeps that a visible decision in the project's own `composer.json`. |
| **Waiting for Pest to offer a report or a shared map** | Not in this package's control. The adapter works with what the supported versions ship, and the contract suite finds out when that changes. |
| **The cap as `-d memory_limit` on the runner's command** | Only the process the gate starts takes it. Each mutant's process is started by the runner, and inherits the environment, not the options. |
| **An address-space limit (`ulimit -v`) on the runner's processes** | It counts reserved address space, which PHP's JIT and OPcache reserve far beyond what they use, it does not exist on Windows, and a process over it dies with no message that names the cause. |
| **Codeception and phpspec through Infection** | Their coverage and group listing are not PHPUnit's, and nothing in the gate's reach, holds or proof key has been checked against them. An extension can add them through the same port. |

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

- [ADR-0001](0001-a-framework-free-core-behind-nine-ports.md): the port and its outcomes
- [ADR-0003](0003-a-floor-only-rises.md): how statuses become a score
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): holding groups and `#[Holds]`
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): runner identity, and the tests that can judge a unit, in the key
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): timeouts, flaky results and ignores
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the first killer recorded, and the order of a mutant's tests
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): every killer recorded, for a full kill matrix
