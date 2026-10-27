# ADR-0027: Codeception, PhpSpec and Testo get native runners on the gate's own mutants

**Status:** Accepted
**Date:** 2026-09-30

## Context

Three PHP test frameworks besides PHPUnit and Pest have users who want a
mutation gate: Codeception, PhpSpec and Testo. Infection runs each of them
through an adapter package of its own. The gate's Infection adapter does not:
it refuses a `testFramework` other than `phpunit` (ADR-0004), and
ADR-0004 rejected reaching them through Infection, because their coverage and
group listing are not PHPUnit's, and nothing in the gate's reach, holds or
proof key is checked against them.

ADR-0023 gives the gate its own mutants for native runners, built from the
mutator sets of ADR-0021, and runs each mutant with the gate's own file
override prepended to PHP. A native runner for these three frameworks can
then give them what Pest has: holds groups, the first killer (ADR-0013), a
full kill matrix (ADR-0014) and learned costs (ADR-0006).

What each framework offers, read at source (Codeception 5.3.6, PhpSpec 8.3.1,
Testo 0.10.54, and Infection 0.35.5 with its adapters):

| | Codeception | PhpSpec | Testo |
|---|---|---|---|
| Stable, PHP 8.5 | yes; CI on 8.2 to 8.5 | yes; declares 8.2 to 8.5 | no: 0.x, released almost daily; CI on 8.2 to 8.5 |
| Extension API | `Codeception\Extension`, on Symfony events: `test.before`, `test.fail`, `test.error`, and `test.after` with the test's duration | `PhpSpec\Extension::load(ServiceContainer, params)`; `beforeExample` and `afterExample`, whose event gives `getTime()` and `getResult()` | `PluginConfigurator`, interceptors, and PSR-14 events such as `TestStarting` and `TestFinished` |
| One test on the command line | `run suite path:method`, `--filter`, `name#N` for a data set | one locator per run, `spec/FooSpec.php:LINE`; no name filter | `--filter Class::method[:provider[:set]]`, `--path` |
| Groups | `#[Group]`, and config groups of `path:method` lists, set per run with `-o "groups: …"`; `-g` and `-x` | none | `#[Group]`; `--group=a --group=!b` |
| Per-test coverage | php-code-coverage 9 to 14; `--coverage-phpunit` writes PHPUnit's XML with `covered by` | per example, through `friends-of-phpspec/phpspec-code-coverage` 7.0, which allows php-code-coverage up to 11 | its own engine on pcov or Xdebug; `--coverage-xml` writes PHPUnit's layout with `covered by` |
| JUnit with times | `--xml` | `--format=junit` | `--log-junit` |
| Stop at the first failure | `--fail-fast` | `--stop-on-failure` | no option; `CancelTest` is its cooperative signal to stop |
| Infection's adapter | `infection/codeception-adapter` 0.4.7: a group of the covering test files, fail-fast | `infection/phpspec-adapter` 0.3.2: every mutant runs the whole suite, and no JUnit reaches Infection, so it has no timings | `testo/bridge-infection` 0.1.9: covering files and methods, no fail-fast, the mutant served through `-d auto_prepend_file` |

All three of Infection's adapters serve a mutant through
`infection/include-interceptor`. Codeception's acceptance and functional
suites that drive the application through PhpBrowser or WebDriver run it in
another process, whose coverage comes back through `c3.php`. An override in
the test process never reaches that process.

## Decision

1. **The gate's own engine makes the mutants.** A native runner for any of the
   three frameworks takes its mutants from the engine of ADR-0023: the
   mutator sets of ADR-0021, `default` among them. So the same code has the
   same mutants and the same ids under every native runner, and ADR-0020's
   static-analysis pre-check, ADR-0023's warm workers and ADR-0025's pruning
   apply to them as they do to the native PHPUnit runner.

2. **One override serves every native runner.** Each mutant's process is
   started as `php -d auto_prepend_file=<override> <the framework's
   command>`, ADR-0023's override, so the mutated file is served before
   Composer's autoloader loads anything, `files` autoloads included. Testo's
   own Infection bridge serves its mutants the same way. The guards of
   ADR-0004, *loaded before the override* and *never loaded*,
   apply unchanged. No framework's bootstrap is edited.

3. **Codeception: suites that run in the test process, selected by the
   gate's extension.**
   - **The extension.** Each run starts Codeception's own `codecept run`
     with the gate's extension enabled for that run (`--ext`) and its
     settings given as an override (`--override "extensions: config:
     <class>: <JSON>"`). The project's config is read as it is written. One
     run spans every suite.
   - **Scope.** A suite whose modules serve the application from another
     process (PhpBrowser or WebDriver, by name, by class or through another
     module's `depends`, or remote coverage through `coverage.remote`) holds
     no test the gate runs: the extension skips each of its tests, its
     directories hold none of the suite's tests, and every run's verdict and
     `doctor` name it. `trees` can leave its code out.
   - **Selection.** The extension skips every test the run does not select
     as Codeception starts it, by setting its metadata's skip, before its
     `_before`, its modules or the test run; Codeception counts it as
     skipped. A mutant's run selects its covering tests exactly, by their
     ids, from a file: a unit test as `Class::method`, with `#` and its data
     set's name, and a Cest as `Class::method`, with `#` and its example's
     index.
   - **The first killer** comes from the extension on `test.fail` and
     `test.error`, recorded to the file `MUTATION_GATE_RESULTS` names, and
     each test's time from `test.end`.
   - **Groups and holds** are Codeception's groups, and a `#[Holds]` on a
     test class or method puts its tests in the holding group (ADR-0005),
     which the extension reads by reflection in Codeception's process.
   - **Coverage.** The extension measures the lines each selected test runs
     in the project's own files with php-code-coverage 14, and writes the map
     in the format the native runner reads from PHPUnit, each test's time
     with it.
   - **Memory.** Codeception sets its own process's `memory_limit` from its
     `settings.memory_limit`, `1024M` unless its config says otherwise, so a
     mutant's run passes the gate's cap there.
   - **A full kill matrix** runs without `--fail-fast`.
   - **Listing.** A run that selects no test lists every test of the
     in-process suites and its groups to a file.

4. **PhpSpec: the gate's launcher loads an extension that selects the examples.**
   - **The launcher.** The runner starts the gate's launcher,
     `bin/mutation-gate-phpspec`, in place of PhpSpec's own script, with the
     project's autoloader. It takes off the options the gate tells it
     (`--mutation-gate-*`), loads the gate's extension into PhpSpec's
     container before PhpSpec reads the project's config, and runs PhpSpec's
     `run` on the rest. The project's config is read as it is written.
   - **Selection.** The extension's maintainer prepares every example first,
     above PhpSpec's own, and skips each one the run does not select by
     throwing PhpSpec's `SkippingException`, before its `let` or its
     collaborators run. PhpSpec dispatches `beforeExample` outside an
     example's own handling, where an exception breaks the run, so the skip
     is a maintainer's. A mutant's run selects its covering examples exactly,
     by their ids, `Class::method`, from a file.
   - **The first killer** comes from `afterExample`, recorded to the file
     `MUTATION_GATE_RESULTS` names, and each example's time from
     `ExampleEvent::getTime()`.
   - **Holds.** PhpSpec has no groups. A `#[Holds]` on a spec class or an
     example method puts its examples in the holding group, which the
     extension reads by reflection in PhpSpec's process, as Pest's filter
     does in Pest's.
   - **Coverage.** The extension measures the lines each selected example
     runs in the project's own files with php-code-coverage 14, and writes
     the map in the format the native runner reads from PHPUnit, each
     example's time with it.
   - **Listing.** A run that selects no example lists every example and its
     holding groups to a file.

5. **Testo: supported at 0.10, pinned exactly, until 1.0.**
   - **The pin.** 0.1.0 supports Testo 0.10.x, pinned exactly: `composer.json`
     declares a `conflict` outside the Testo releases the Runner contract
     suite has passed, as it does for pest-plugin-mutate (ADR-0004), and the
     daily runner canary (ADR-0017) names the `conflict` line to write when
     a newer release passes.
   - **1.0.** The range widens to Testo 1.0 in a minor release, once the
     Runner contract suite passes against it.
   - **The first failure.** A gate Testo plugin answers the first failing
     `TestFinished` by cancelling the tests that remain (`CancelTest`). The
     first step of the build proves that a plugin can do so. If it cannot, a
     mutant runs its covering tests to the end, and the first failure is still
     its first killer.
   - **Selection** is `--filter Class::method` and `--path`, **groups** are
     Testo's own, **coverage** is `--coverage-xml` and **timings** come from
     `--log-junit`.

6. **Each native runner is chosen by name, or by zero-config when it alone
   fits.** `runner: codeception`, `runner: phpspec` and `runner: testo` name
   one. Zero-config chooses one only when neither `pestphp/pest-plugin-mutate`
   nor `infection/infection` is installed and exactly one of the three
   frameworks is, by the same rule ADR-0023 gives the native PHPUnit runner.
   A project with two frameworks, such as Codeception beside PHPUnit, sets
   `runner`, and `init` asks. This amends ADR-0002's runner row.
   The runner is in the proof key, as every runner's identity is
   (ADR-0007), and each native runner takes `runner.workers` (ADR-0023).

7. **Where it lives in the code.** `Adapter\Native` holds the runner every
   framework shares: the override, each mutant's files and run, the coverage
   map it reads, the test files that declare each test, and the warm workers
   of ADR-0023. Each framework is a `Core\Native\Framework`, which says how
   it is started, how it selects and lists its tests, and how its warm
   worker boots, where it has one. `Adapter\PhpUnit`, `Adapter\Codeception`, `Adapter\PhpSpec` and
   `Adapter\Testo` each hold theirs, with its extension or plugin class. An
   adapter names no other adapter (ADR-0001), so the runner names only the
   `Core` interface, and the Cli hands it the framework a config names. No
   port changes. The Runner contract
   suite (ADR-0004) runs its fixture library against each of them,
   with each framework's own holds and group cases, and CI runs it against
   the lowest and highest supported release of each framework (ADR-0011).

8. **Supported versions.** Codeception ^5.3.6 and PhpSpec ^8.2, each with
   php-code-coverage ^14, and Testo at the exact releases its `conflict`
   allows join ADR-0011's table,
   each tested at its lowest and highest supported release. This amends
   ADR-0011.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Infection's mutants, with the gate driving the tests** | Infection makes and runs its mutants itself, and its generation is `@internal` on a 0.x release. There is no way in. |
| **Each framework's own bootstrap setting to serve the mutant** (Codeception's `--bootstrap`, PhpSpec's `bootstrap`), as Infection's adapters do | Three code paths instead of one, and a class Composer's `files` autoload loads before a bootstrap escapes the override. |
| **Every Codeception suite**, as Infection's adapter runs them | A suite that serves the application from another process judges the original code while claiming to judge the mutant. |
| **Codeception's own groups for selection** (`--override "groups: …"` with `-g`) and its `--coverage-phpunit` and `--xml` for coverage and times | The extension that records each test is needed either way. Selecting, measuring and listing there too reads one set of ids and writes the map the native runner already reads from the PhpSpec runner, where Codeception's XML coverage and report would each need a reader of their own. |
| **Skipping a test from `test.before`** | Codeception counts a test skipped there as failed, so every mutant's run would fail; at `test.start` it counts it as skipped. |
| **One PhpSpec process per covering spec file, always** | No extension to depend on. A mutant covered by ten specs pays ten PhpSpec boots. It stays the fallback. |
| **The whole PhpSpec suite per mutant**, as Infection's adapter runs it | No better than Infection, and far slower than selecting examples. |
| **Skipping an example from `beforeExample`** | PhpSpec dispatches it outside the example's own handling, so an exception thrown there breaks the run instead of skipping the example. |
| **A generated PhpSpec config that enables the extension** | PhpSpec reads one config and imports none, so a generated one would have to copy the project's suites and extensions; the launcher loads the extension beside the project's own config. |
| **Coverage through `friends-of-phpspec/phpspec-code-coverage`** | Its 7.0 allows php-code-coverage up to 11, whose map the native runner does not read; the extension measures with 14, the release the PHPUnit runner reads. |
| **Testo only through Infection until it reaches 1.0** | No churn to absorb. The pin and the canary already turn churn into a failed canary, never a user's failed run. |
| **A native runner whenever its framework is installed** | Every Infection user's scores would move on upgrade. |

## Consequences

**Codeception, PhpSpec and Testo projects get everything Pest has**: holds,
the first killer, a full kill matrix, learned costs, and exact selection of the
covering tests, which is finer than Infection's adapters give for Codeception
and PhpSpec.

**Out-of-process suites are left out by name.** A Codeception suite that
drives the application from outside is never judged by a mutant it cannot
reach, and the verdict and `doctor` say which suites those are.

**Testo's churn is the canary's problem.** A new Testo release reaches users
only once the contract suite passes on it.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-nine-ports.md): adapters that name no other adapter
- [ADR-0002](0002-one-typed-config-from-several-formats.md): zero-config's choice of runner
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the Runner port, the guards, and the contract suite
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): holding groups
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): supported versions
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the first killer
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): the full kill matrix
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): the runner canary and `doctor`
- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): the static-analysis pre-check
- [ADR-0021](0021-mutators-are-written-once-and-first-party-sets-can-leave.md): the mutator sets the engine runs
- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): the engine, the prepended override and warm workers
- [ADR-0025](0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md): pruning
