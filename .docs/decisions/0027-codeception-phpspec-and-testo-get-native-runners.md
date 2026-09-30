# ADR-0027: Codeception, PhpSpec and Testo get native runners on the gate's own mutants

**Status:** Accepted
**Date:** 2026-09-30

## Context

Three PHP test frameworks besides PHPUnit and Pest have users who want a
mutation gate: Codeception, PhpSpec and Testo. Infection runs each of them
through an adapter package of its own. The gate's Infection adapter does not:
it refuses a `testFramework` other than `phpunit` (ADR-0004 decision 4), and
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
   ADR-0004 decision 8, *loaded before the override* and *never loaded*,
   apply unchanged. No framework's bootstrap is edited.

3. **Codeception: suites that run in the test process.**
   - **Scope.** A suite whose enabled modules serve the application from
     another process (PhpBrowser, WebDriver, and remote coverage through
     `c3`) is refused per suite: *cannot judge: suite `acceptance` runs the
     application in another process, where a mutant cannot be served*.
     `doctor` names such a suite, and `trees` can leave its code out.
   - **Selection.** A mutant runs its covering tests exactly, as the group
     `-o "groups: mutation-gate: [<path:method>, …]"` with `-g mutation-gate`.
   - **The first killer** comes from a gate extension on `test.fail` and
     `test.error`, enabled for the run, which writes to the file
     `MUTATION_GATE_RESULTS` names.
   - **Groups and holds** are Codeception's `#[Group]` and `holds:` config
     groups (ADR-0005 decision 9).
   - **Coverage** is `--coverage-phpunit`, per test, and each test's time
     comes from `--xml`.
   - **A full kill matrix** runs without `--fail-fast`.
   - The first step of the build proves that the gate's extension can be
     enabled from the command line for one run, or through `-o
     "extensions: …"`, that one run can span several suites, and that per-test
     coverage keeps its attribution in unit suites. If one run cannot span
     suites, a mutant runs once per suite that holds its covering tests.

4. **PhpSpec: a gate extension selects the examples.**
   - **Selection.** A gate PhpSpec extension, enabled in a generated config
     that imports the project's own, reads the covering examples from a file
     the environment names, and skips every other example in
     `beforeExample`.
   - **The first killer** comes from `afterExample`, and each example's time
     from `ExampleEvent::getTime()`.
   - **Holds.** PhpSpec has no groups. The gate reads `#[Holds]` on a spec
     class or an example method from tokens, and the same extension applies
     it.
   - **Coverage** comes through `friends-of-phpspec/phpspec-code-coverage`.
     The adapter refuses a php-code-coverage release that extension cannot
     drive, and says which one it needs.
   - The first step of the build proves that an extension can skip an example
     from `beforeExample`. If it cannot, a mutant runs once per covering spec
     file (`run spec/FooSpec.php`), stopping at the first failure.

5. **Testo: supported at 1.0, pinned exactly.**
   - **The pin.** `composer.json` declares a `conflict` outside the Testo
     releases the Runner contract suite has passed, as it does for
     pest-plugin-mutate (ADR-0004 decision 3), and the daily runner canary
     (ADR-0017 decision 8) names the `conflict` line to write when a newer
     release passes.
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
   `runner`, and `init` asks. This amends ADR-0002 decision 5's runner row.
   The runner is in the proof key, as every runner's identity is (ADR-0007
   decision 2.4), and each native runner takes `runner.workers` (ADR-0023).

7. **Where it lives in the code.** `Adapter\Codeception`, `Adapter\PhpSpec`
   and `Adapter\Testo` each hold the runner and its extension or plugin
   class. An adapter names no other adapter (ADR-0001), so what they share,
   the override and the fork server of ADR-0023, is either a `Core` value or
   a piece the Cli hands each adapter. No port changes. The Runner contract
   suite (ADR-0004 decision 7) runs its fixture library against each of them,
   with each framework's own holds and group cases, and CI runs it against
   the lowest and highest supported release of each framework (ADR-0011).

8. **Supported versions.** Codeception ^5.3, PhpSpec ^8.2 and Testo at the
   exact releases its `conflict` allows join ADR-0011 decision 10's table,
   each tested at its lowest and highest supported release. This amends
   ADR-0011 decision 10.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Infection's mutants, with the gate driving the tests** | Infection makes and runs its mutants itself, and its generation is `@internal` on a 0.x release. There is no way in. |
| **Each framework's own bootstrap setting to serve the mutant** (Codeception's `--bootstrap`, PhpSpec's `bootstrap`), as Infection's adapters do | Three code paths instead of one, and a class Composer's `files` autoload loads before a bootstrap escapes the override. |
| **Every Codeception suite**, as Infection's adapter runs them | A suite that serves the application from another process judges the original code while claiming to judge the mutant. |
| **One PhpSpec process per covering spec file, always** | No extension to depend on. A mutant covered by ten specs pays ten PhpSpec boots. It stays the fallback. |
| **The whole PhpSpec suite per mutant**, as Infection's adapter runs it | No better than Infection, and far slower than selecting examples. |
| **Testo only through Infection until it reaches 1.0** | No churn to absorb. The pin and the canary already turn churn into a failed canary, never a user's failed run. |
| **A native runner whenever its framework is installed** | Every Infection user's scores would move on upgrade. |

## Consequences

**Codeception, PhpSpec and Testo projects get everything Pest has**: holds,
the first killer, a full kill matrix, learned costs, and exact selection of the
covering tests, which is finer than Infection's adapters give for Codeception
and PhpSpec.

**Out-of-process suites are refused by name.** A Codeception suite that drives
the application from outside is never judged by a mutant it cannot reach.

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
