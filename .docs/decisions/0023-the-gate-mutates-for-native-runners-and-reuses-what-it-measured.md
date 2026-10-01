# ADR-0023: The gate makes its own mutants for native runners, re-measures only the coverage that moved, fills every core, and boots each worker once

**Status:** Accepted
**Date:** 2026-09-30

## Context

A run's fixed costs sit where the runners' process models put them. What
follows was read in the vendored sources (pest-plugin-mutate 5.0.2, Pest
5.2.1, Infection 0.35.5, PHPUnit 13.3.4, nikic/php-parser 5.9.0) and in PHP's
manual.

- **Coverage is measured whole.** Every plan runs the suite under coverage,
  or reads the map another job wrote (ADR-0006 decision 1). Under Pest,
  every shard also runs its own opening suite unless `pest.patch` is on
  (ADR-0004 decision 3). On a large suite that coverage run is often the
  largest fixed cost of a pull request.
- **Both runners already use every core within one invocation.** Pest keeps
  a pool of `processes` slots under `--parallel`, by default the core count
  `fidry/cpu-core-counter` gives, and starts the next mutant's child as soon
  as a slot frees. Infection takes `--threads=<n>`. The idle cores are
  *between* invocations: a shard runs each held unit as an invocation of its
  own (ADR-0006 decision 3), and ADR-0004 decision 8's judging runs, survivor
  confirmation, Infection's timeout retries and the static-analysis checks
  of ADR-0020 run one after another.
- **One Pest `--mutate` runs per checkout.** Pest writes its opening
  coverage to a fixed path (ADR-0004 decision 3), and its mutated files
  under its own vendor directory.
- **Every mutant runs in a fresh PHP process.** Pest starts `vendor/bin/pest`
  per mutant, and its mutate plugin serves the mutated file through an
  override keyed by `PEST_MUTATION_TESTING` and `PEST_MUTATION_FILE`.
  Infection starts PHPUnit per mutant with a generated bootstrap that
  registers `IncludeInterceptor`, a `file://` stream wrapper that serves the
  mutant in place of the original. Testo's Infection bridge instead
  registers the interceptor through `php -d auto_prepend_file=…`, before
  Composer's autoloader loads anything.
- **PHP cannot unload or redefine a class.** A process that has loaded the
  original file cannot load its mutant. That is why each mutant gets a
  fresh process, and why the gate already guards against a file *loaded
  before the override* (ADR-0004 decision 8). Booting once and running many
  mutants therefore needs either a fork per mutant, or mutant schemata:
  every mutant of a file compiled into one version, switched by a variable
  (Untch, Offutt and Harrold, 1993). `pcntl`, which forks, is available only
  to PHP's CLI on Unix-like systems, and is not enabled by default.
- **PHPUnit 13.3 has what a runner needs.** An extension implements
  `PHPUnit\Runner\Extension\Extension::bootstrap(Configuration, Facade,
  ParameterCollection)`, and its `Facade` registers event subscribers. It is
  registered in `phpunit.xml` or with `--extension <class>`. The command
  line has `--test-id-filter-file`, `--run-test-id`, `--test-files-file`,
  `--stop-on-defect`, `--list-groups`, `--coverage-php`, `--coverage-xml` and
  `--log-junit`.
- **A plain PHPUnit project has no mutation runner but Infection.** Pest and
  Infection each make their own mutants. ADR-0001's context says the gate
  "does not mutate code itself", and ADR-0013 rejected generating mutants in
  the gate because the gate "would need a mutator engine matching each
  runner's exactly". ADR-0021 gives the gate a mutator SDK on php-parser 5,
  whose format-preserving printer changes only the lines a node spans.

## Decision

### Incremental coverage

1. **A test's coverage entry is keyed like a proof, and only entries whose
   key moved are measured again.**
   - An entry's key reads the test file and the support it names (ADR-0007
     decision 2, item 7's matching), every file the entry says the test
     executed, and what every key's base reads: the gate, the runner's
     identity, `installed.json`, the files that define the runner, and every
     file outside the test directories that is not a source file (item 6,
     less the sources). `proofs.ignore` narrows that last part exactly as it
     does for proofs.
   - An entry whose key moved is re-measured, a new test is measured, and a
     deleted test's entry is dropped.
   - A test whose code, support and executed files are unchanged takes the
     same path, so it covers the same lines. A test that could reach new
     code must execute a changed file to get there, so its key moved. A
     template or a fixture moves the non-source part, so every entry is
     re-measured: the gate cannot see what a template makes a test execute.
   - `coverage.incremental`, a boolean, `true` by default, judges only: an
     entry is reused only under its own key, so reuse never changes what a
     proof's key reads. Item 9 of the key (ADR-0007 decision 2) already
     holds each covered line's test ids.

2. **The map is kept beside the ledger, under the ledger's rules.**
   - The ProofStore port reads and keeps a named companion object per scope,
     beside the ledger, with the ledger's read and write rules (ADR-0007
     decision 4). The coverage map is one: `<scope>/coverage.json.gz`, the
     gate's format-1 map with a key per test entry and the `commit` and
     `dirty` fields of ADR-0020. The static analyser's cache is another
     (ADR-0020).
   - A run reads its own scope's map and the default branch's, and writes
     only its own, so a pull request cannot plant coverage that the default
     branch's reach or keys use. A map is data, never PHP (ADR-0006
     decision 1).
   - This amends ADR-0001 decision 2's `ProofStore` row and ADR-0007
     decision 4.

3. **Only some tests are measured, through the existing coverage method.**
   - The Runner port's `coverage(CoverageRun|CoverageRead)` takes a subset
     of tests in its `CoverageRun`.
   - **Pest:** `--coverage-php` with the test files as paths, and `--filter`
     for single tests where a file's entries are mixed.
   - **Infection:** PHPUnit with `--test-id-filter-file`, or `--filter` on a
     PHPUnit release without it, and `--coverage-xml`.
   - The gate merges the partial map into the kept one: re-measured entries
     are replaced, and each untouched test keeps its recorded duration.
   - The Runner contract suite asserts that the merged map equals a full
     run's on its fixture library (ADR-0004 decision 7).

4. **The gate writes each runner's own coverage input from its map.**
   - For Infection: `coverage-xml/index.xml`, one file per source with its
     `covered by` lists, and a JUnit file with each test's time. A contract
     test proves that Infection judges the same mutants from a written
     directory as from PHPUnit's own. This extends the directory ADR-0004
     decision 4 already writes from a handed-over map.
   - For Pest: the `--coverage-php` file `pest.patch` reads. Without
     `pest.patch`, Pest still runs its opening suite, and `doctor` says what
     that costs (ADR-0017 decision 10).
   - This amends ADR-0004 decisions 1, 3 and 4, and ADR-0006 decision 1.

### Every core

5. **A shard runs its work as a pool.**
   - The shard flow queues every piece of its work: each held unit's
     invocation, the invocation for the rest of the shard, judging runs,
     survivor confirmations, retries and static-analysis checks.
   - Workers take the next piece as cores free up. Each invocation is given
     a process count, a long one more, and the queue's small pieces fill
     the cores a finishing invocation leaves.
   - This amends ADR-0006 decision 3: a shard runs its invocations side by
     side.

6. **Invocations run side by side only where they cannot collide.**
   - **Infection:** each concurrent invocation has its own working directory
     under `.mutation-gate/infection/<n>/`, holding its config, logs,
     `tmpDir` and coverage directory.
   - **Pest:** with `pest.patch`, the patch also moves the opening map and
     the mutated files under the directory `MUTATION_GATE_WORK` names, so
     Pest invocations run side by side. Without it, Pest invocations stay
     serial, and `plan` says so once.
   - Judging runs, confirmations and checks are separate processes already.
   - `--processes` is still never passed to Pest (ADR-0004 decision 3).
     This amends ADR-0004 decision 3 with the patch's work directory.

7. **The pool counts the cores the process is allowed.**
   - `run.processes`: `auto` (default) or an integer. `auto` is the
     container's CPU quota where a cgroup sets one, and otherwise the online
     cores, as `fidry/cpu-core-counter` answers.
   - The pool never runs more processes than that across its workers.
   - It judges only: how many processes run never changes a mutant's result,
     since timeouts are triaged against measured test time (ADR-0008
     decision 2).
   - The JSON report's `run` section (ADR-0016 decision 18) shows each
     shard's pieces' summed time over its wall time.

### The gate's own mutants

8. **Native runners run the gate's own mutants.**
   - A native runner (the PHPUnit runner below, and ADR-0027's) mutates with
     ADR-0021's SDK: a first-party `default` set in `plugins/default/`, under
     ADR-0021's plugin rules, covers ADR-0009's families, and every enabled
     set adds to it.
   - The `default` set is a superset of Pest's `DefaultSet`. It holds Pest's
     159 mutators, with the five Assignment mutators whose names clash with
     Arithmetic's renamed `…Equal…`. It adds the two families Pest's set leaves
     out: `RemoveThrow` (Exception) and `PublicToProtected` (Visibility).
   - A first-party plugin counts as first party. `--no-extensions` means no
     third-party code, so it still loads the plugins this repository ships,
     recognised by their Composer package names from one list in `Core`, held
     as data rather than class names.
   - Files are parsed with php-parser 5 and printed with its
     format-preserving printer, so only the mutated node's lines change and
     the diff is exact.
   - The gate controls generation there, so it runs exact lists of mutants,
     shards by mutant, carries pruned results by mutant, and checks a mutant before its tests with
     no hand-off (ADR-0020, ADR-0025).
   - Pest and Infection make their own mutants.
   - This supersedes ADR-0001's context sentence "It does not mutate code
     itself", for native runners only, and ADR-0013's rejected alternative
     "Generating mutants in the gate", which is built for native runners.
     Its purpose there, proving equivalence before a run, stays ADR-0013's
     compiler check after the run.

9. **`runner: phpunit` runs each mutant through a PHPUnit extension.**
   - The command is `php -d opcache.enable_cli=0 -d
     auto_prepend_file=<override> vendor/bin/phpunit --extension
     'NightWorksIO\MutationGate\Adapter\PhpUnit\Extension'
     --test-id-filter-file=<ids> --stop-on-error --stop-on-failure
     --no-progress`. Opcache is off, so no cached original runs in the
     mutated file's place and no mutated file is cached for a later run. The
     run stops at the first test that fails or errors, and not at one that is
     only risky or warns.
   - The prepended override is the gate's own `file://` wrapper. It serves
     the mutated file from before Composer's autoloader loads anything,
     `files` autoloads included, wherever PHP includes the file by any path
     that names it, and falls back to the real file for every other path.
     The file and the mutated file each have a variable of their own.
   - ADR-0004 decision 8's guards check it: the wrapper writes to the file
     `MUTATION_GATE_GUARD` names each time it serves the mutated file, in any
     of the run's processes, and the extension writes there where opcache
     could serve a cached original. A run that served nothing, or could have
     served a cached original, leaves the mutant unjudged, with its reason.
     The gate refuses an override whose path PHP's command line would read
     as ini syntax.
   - The extension subscribes to `Test\PreparationStarted`, `Test\Failed`,
     `Test\Errored`, `Test\Passed`, `Test\Skipped`,
     `Test\MarkedIncomplete`, `TestSuite\Skipped`,
     `Test\BeforeFirstTestMethodErrored`, `Test\BeforeFirstTestMethodFailed`
     and `Test\Finished`, and appends each test that starts, how each test
     ended, and each class whose `setUpBeforeClass` failed or errored, to the
     file `MUTATION_GATE_RESULTS` names. A test skipped or marked incomplete,
     in its body, in `setUp`, for a requirement or with its whole class, has
     ended.
   - A test that fails or errors kills the mutant, and so does a test that
     started and neither finished nor was skipped or marked incomplete, whose
     process died, and a `setUpBeforeClass` that fails or errors, by each
     test of its class the run selected. A run stopped at its limit timed
     out, where the mutated file ran.
   - A run PHPUnit fails with no test failing, such as for a warning the
     project fails on, leaves the mutant unjudged, with what PHPUnit said. A
     run whose every test was skipped or marked incomplete leaves it
     unjudged too, with what PHPUnit said where it failed the run.
   - The gate enforces the timeout on the process.
   - The runner supports PHPUnit 13.2.0 and later, the first release with
     `--test-id-filter-file`: PHPUnit 12, 13.0 and 13.1 refuse the option.
     The gate cannot judge with a lower PHPUnit, and names the version
     installed.

10. **The PHPUnit runner has everything Pest's has.**
    - **Coverage:** `--coverage-php`, read as the Pest adapter reads it, with
      each test's duration from the map's own test results, and partial runs
      through `--group` or `--filter`. The project's PHPUnit loads the gate's
      extension, so the gate is installed in the project's vendor directory,
      and the map is written and read by one php-code-coverage. That matters:
      a map one php-code-coverage release writes can be one another refuses.
    - **Selection:** a mutant's covering tests by their ids, or, where PHPUnit
      cannot read an id back from a line, such as a data set's name with a
      line break, by the test files that declare their classes, found by
      their tokens, through `--test-files-file`. The same files are the
      tests that judge a file, and name each test. A test such a run did not
      select, run as it shares a file with one it did, kills where it fails,
      with the kill credited only to the tests that cover the mutant, and
      says nothing where it passes.
    - **Groups:** `--list-groups`. `#[Holds]` is read from tokens, and a held
      unit is narrowed by `--group=holds:<path>` or by the holding tests' ids.
    - **Kills:** the first killer from events, and `--kill-matrix=full` by
      leaving out `--stop-on-error` and `--stop-on-failure` (ADR-0014
      decision 7).
    - **Costs:** learned from the gate's own process timings (ADR-0006
      decision 4).
    - `Adapter\PhpUnit` holds the extension, the runner and the override. No
      port changes.

11. **Zero-config chooses the PHPUnit runner only where nothing else fits.**
    - `runner: phpunit` is chosen only when neither Pest's mutate plugin nor
      Infection is installed. With Infection installed it stays Infection,
      and with both runners installed the refusal of ADR-0002 decision 5
      applies.
    - `init` asks, and suggests `phpunit` for a PHPUnit project with no
      Infection config.
    - This amends ADR-0001 decision 2's runner row, ADR-0002 decision 5, and
      ADR-0011 decision 10 (the PHPUnit versions supported).

### Warm workers

12. **Under a native runner, a worker per core boots once and forks a child
    per mutant.**
    - The worker boots the autoloader, PHPUnit's configuration and its
      bootstrap, with the override registered at start (decision 9).
    - For each mutant it forks a child, which tells the override which
      mutant to serve, runs the selected tests, reports and exits. The parent
      is never touched by a mutant, so every mutant runs in a clean copy of
      the booted process.
    - Mutant schemata are not used: a schematised program is not the mutant
      program, and a mutant of a declaration cannot be switched at run time.
    - Pest and Infection start their own processes, so warm workers exist
      only under native runners.

13. **A worker forks only from a boot that holds nothing a child could
    share.**
    - The worker forks before the project's tests start, after only the
      autoloader, PHPUnit's configuration and the configured bootstrap.
    - Before the first fork a guard checks that no socket, `pgsql` or
      `mysqli` resource is open (`get_resources()`), and that no class of the
      unit is loaded.
    - A worker that fails the guard is dropped, and its mutants run in fresh
      processes. The guard's reason is recorded, as ADR-0004 decision 8's
      guards are, and `doctor` names the bootstrap line.

14. **Whether a worker forks is a setting that can move a result.**
    - `runner.workers`: `fork` or `fresh`. The default is `fork` where
      `pcntl` is loaded and the runner is native, and `fresh` otherwise.
    - It affects results and is in key item 3 (ADR-0007 decision 2.3), since
      a boot's state is a new source of state, as test order is (ADR-0013
      decision 4).
    - Survivor confirmation (ADR-0008 decision 3) always runs in a fresh
      process, so a survivor a warm boot made shows up as flaky.
    - The first step of the build measures the boot's share of a mutant's
      time on the benchmark's Laravel and Symfony projects (ADR-0017
      decision 14), and proves the guard on them.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Re-measuring the tests the change reaches, with no keys** | Misses a change outside sources and tests, such as a template or a fixture, that alters which code a test runs. |
| **Re-measuring everything whenever a source file changed** | Saves only on test-only changes. |
| **Keeping the map only locally** | CI, where the saving matters, never gets it. |
| **Keeping the map inside the ledger file** | Per-test line maps are far larger than proofs, and every ledger read would pay for them. |
| **A new Runner port method for partial coverage** | The same thing as a request field, with a second name. |
| **Infection only through a full PHPUnit run whenever an entry changed** | Infection users would lose the feature. |
| **Per-mutant scheduling by the gate, through one-mutant runs** | Every mutant pays a runner invocation and its opening run. |
| **Leaving the idle cores to the runners** | The serial gaps between invocations stay. |
| **A `git worktree` per concurrent Pest invocation** | Each needs its own `vendor`, and a checkout of a large repository per core. |
| **The host's core count** | Oversubscribes a container with a CPU quota, which is slower and times more mutants out. |
| **Infection's engine as a library for native runners** | Its generation is `@internal` on a 0.x release, and a runner "without Infection" would need Infection. |
| **pest-plugin-mutate's generator as a library** | Requires Pest in a project that chose not to use it, and its generator is internal too. |
| **A `phpunit.xml` generated per mutant**, as Infection writes | A config file per mutant, and the project's own config rewritten. |
| **Only what Infection already gives a PHPUnit project** | Loses first killers, the full kill matrix, holds and learned costs, which are the reasons for the runner. |
| **Preferring `phpunit` whenever a project runs PHPUnit** | Every Infection user's scores would move on upgrade. |
| **Mutant schemata** | The program under test is never the mutant program, with extra branches, other opcodes and a changed `__LINE__` and reflection, and mutants of declarations cannot be switched. |
| **No warm workers** | Each mutant pays the boot, which is most of its cost in a framework app. |
| **Forking after the suite's first test** | The first test's state, such as a transaction or a filled cache, leaks into every mutant. |
| **`runner.workers` judging only** | Asserts an equivalence nobody has proved. |

## Consequences

**A pull request re-measures the coverage its change can move, and nothing
else,** and every store keeps the map under the ledger's trust.

**A shard's cores stay busy** between invocations as well as within them.

**A plain PHPUnit project gets everything a Pest project has,** without
Infection, and the gate owns its mutants there: exact lists, checks with no
hand-off, and one boot per worker.

**The gate is a mutation engine for native runners,** with its own
operators to keep beside Pest's and Infection's.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-nine-ports.md): the context sentence this supersedes for native runners, and the `ProofStore` and runner rows
- [ADR-0002](0002-one-typed-config-from-several-formats.md): how zero-config chooses a runner
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the runners' coverage, the patch, and the guards the native runner reuses
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the coverage a plan hands on, and how a shard runs
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the key a coverage entry mirrors, and the store's scopes
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): survivor confirmation and timeout triage
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the supported PHPUnit versions
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the rejected alternative this supersedes, and state treated as order is
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): the full kill matrix
- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): the map's `commit` and `dirty`, and the static-analysis checks
- [ADR-0021](0021-mutators-are-written-once-and-first-party-sets-can-leave.md): the SDK the gate's mutants are made with
- [ADR-0025](0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md): pruning, whose carried results the gate matches by mutant
- [ADR-0027](0027-codeception-phpspec-and-testo-get-native-runners.md): the other native runners, on the same engine and override
