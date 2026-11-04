# ADR-0075: The verification tail may fork from a booted Pest worker

**Status:** Accepted
**Date:** 2026-11-04
**Amends:** [0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): its rule 15, a Pest worker loading nothing of the project, for the verification tail

## Context

ADR-0070 forks the verification tail from warm workers. Under Pest, the
worker loads nothing of the project (ADR-0023 rule 15), so a forked run
saves PHP's start and a Laravel suite's migration, but still loads its test
files. That loading is most of a Pest run's start: on the gate's own suite,
Pest with every test file of its suites loaded and no test run takes
4.5 s on a laptop, against 0.19 s for PHP's start and 0.1 s for Composer's autoloader.

ADR-0023 kept the worker unbooted for mutants because Composer's autoload
files run as they load, by the command line and environment of the process
that loads them, and because a booted worker may load the file a mutant
changes. The tail's runs of every test file run unmutated code, or a
mutant of a file the boot need not load, and Pest's script sets the one
variable an autoload file reads, Collision's printer, before it loads them.

## Decision

1. **`pest.bootTail`, false by default, boots the tail's Pest workers.**
   Under `pest.patch` and `runner.workers: fork`, a worker of the tail that
   serves runs of every test file boots the project before it forks: it
   sets Collision's printer as Pest's script does, loads Composer's
   autoloader, boots Pest's kernel, applies the PHPUnit config's PHP
   settings, runs its bootstrap and each included suite's, and loads every
   test file of the suites the runs name through Pest's loader, as PHPUnit's
   own mapper selects them. It is in key item 3 (ADR-0007).
2. **Only runs of every test file of their suites fork from it:** the
   confirmation's run of every test file, a control of a whole suite, and
   a survivor's run again where it is not narrowed. A narrowed control, a
   baseline, a replay and a trial load some test files, so they keep the
   unbooted worker, and so does every mutant's own run of an invocation.
3. **`pest:patch` carries three changes to Pest itself.** In a child of a
   booted worker, Pest's script keeps the test suite its worker built, its
   kernel skips the bootstrappers and plugins its worker ran, and its test
   suite loader returns the class its worker found in each test file. A
   booting worker leaves the kernel dump to each child, and a kernel shuts
   down only in the process that booted it. A process no booted worker
   forked runs as Pest ships. The canary's runner contract runs them on the
   newest Pest.
4. **A boot that cannot stand in for a fresh start forks nothing.** Where
   the boot leaves a socket open, finds no PHPUnit config, or finds the
   config caching PHPUnit's test index, which may leave a fresh run's test
   files unloaded, the worker refuses every run and each starts fresh, with
   the reason warned of. A run of a mutant of a file the boot loaded, or of
   one that declares a class it declared, starts fresh, as ADR-0023 has it.

### Why a run from the booted worker is as fresh as one from a new process

- **The same loading.** A fresh run of every test file of its suites loads
  the autoloader, boots Pest's kernel, applies the PHPUnit config's PHP
  settings, runs its bootstraps once each, and loads exactly the test files
  PHPUnit's mapper selects for those suites, through Pest's loader. The
  worker does the same, in the same order, with PHPUnit's own mapper, so a
  child finds each file loaded once, as a fresh run does. PHPUnit includes
  a bootstrap with `include_once`, so a child's run of it does nothing more.
- **The same code.** The tail's controls serve the unmutated file, and a
  mutant's run whose file the boot loaded starts fresh, so no child runs
  code the worker compiled from another file. Opcache stays off.
- **The same state at its first test.** The worker runs no test, and builds
  no PHPUnit configuration: the child parses its own command line, so its
  filter, logs and printer are its own. Pest's subscribers and plugins boot
  once, in the worker, as they boot once in a fresh process.
- **The same isolation.** No socket the boot opened is shared, a child's
  generator is seeded afresh (ARCHITECTURE B2), and each child stops at its
  own limits, as ADR-0070 has it.

`WarmControlsTest`, in the runner contract, runs the same whole-suite
controls from a booted worker and from fresh processes with a real patched
Pest, at the lowest and the highest Pest the contract installs, and fails
where they differ in end or failing tests.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Boot only Composer's autoloader** | Saves a tenth of a second and brings back ADR-0023's autoload-file risk. |
| **Fork after PHPUnit built the suite** | PHPUnit builds its printer, logs and filter from the command line before the suite, so a child would inherit its worker's. |
| **Boot the worker for every tail run** | A run of some test files must not see what other test files declare (ADR-0036). |
| **Find the test files by the gate's own reading of the config** | PHPUnit's mapper is what a fresh run selects them by; any other reading may load a file the run would not. |
| **On by default** | A boot is a new source of state for a project's tests, and Pest's internals change between releases; a project opts in. |

## Consequences

- A whole-suite control or confirmation run saves its test files' loading,
  most of Pest's start in a large suite.
- Pest's script, kernel and loader carry `pest:patch` hunks, which the
  canary holds to the newest Pest.

## Related

- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): warm workers
- [ADR-0036](0036-a-kill-only-errors-made-counts-where-its-control-passes.md): what a narrowed run may load
- [ADR-0070](0070-the-verification-tail-forks-from-warm-workers.md): the tail's warm workers
