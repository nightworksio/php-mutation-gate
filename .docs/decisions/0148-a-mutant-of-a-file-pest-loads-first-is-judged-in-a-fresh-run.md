# ADR-0148: A mutant of a file Pest loads first is judged in a fresh run

**Status:** Accepted
**Date:** 2026-11-11

## Context

pest-plugin-mutate puts a mutant in its file's place once Pest has booted.
Every file Pest loads before that, its plugins and the code they load among
them, has already run as the original by the time the mutant is served. The
gate's own plugin records that case (`RecordEvent::Preloaded`), and the
Pest adapter leaves such a mutant unjudged, saying its file was loaded
before the mutant was in place (ADR-0004).

A project whose code Pest loads as it boots cannot judge those files. The
gate itself is such a project: its Pest plugin, the recording and ordering
it runs, `ServedClasses`, `SchemaSwitch`, `MutantFile`, `ForkedChild` and
`OrderDigest` load before the mutant is served. Its own config left those
21 files out of the `src` tree, so ADR-0011 rule 1's floor of 100 held over
a tree that judged less than all of `src`.

The native runners already serve a mutant before Composer's autoloader
loads anything (ADR-0023): PHP prepends the override, which registers the
gate's `file://` wrapper, and the wrapper serves the mutated file in the
original's place and writes `served` to the guard file once it does.

## Decision

1. **`pest.preloaded` says what becomes of a mutant whose own run had
   loaded its file first.** `fresh`, the default, judges it again; `unjudged`
   leaves it unjudged.
2. **Under `fresh`, each such mutant runs again in a fresh `pest` process.**
   The process's PHP has opcache off and prepends the native runners'
   override, told the file, the mutated file and the guard file by
   `MUTATION_GATE_MUTANT`, `MUTATION_GATE_MUTATED` and `MUTATION_GATE_GUARD`,
   so the mutant is in place before Pest boots. The mutated file is the
   mutant as Pest prints it. The run is Pest's own script over the mutant's
   covering tests, selected as its own run selected them: by their filter,
   loading the test files that hold them, where the filter fits, and every
   test of the tests that judge the request otherwise. It writes a JUnit log,
   and none of the gate's plugin's variables reaches it, so the plugin
   records nothing. No warm worker forks it.
3. **Its verdict comes from Pest's JUnit log, its exit status and the guard,
   never from the gate's plugin**, whose code is what may be mutated:
   - stopped at its limit: timed out where the guard says the mutant was
     served, and unjudged where it does not;
   - a guard that does not say the mutant was served: unjudged;
   - failed on exactly the memory cap: out of memory;
   - a covering test that failed or errored: killed, by those tests;
   - passed, with a test that passed: survived; passed with no test run, or
     every test skipped: unjudged;
   - failed with no test case logged, or with only tests that do not cover
     it failing: a kill no test is named for, which counts only once its
     control passes: the same run, through the same override, serving the
     file as Pest prints it unmutated (ADR-0108). It is unjudged where the
     control fails or never starts;
   - failed otherwise: unjudged, with what Pest said.
4. **The runs share the run's time.** Each is allowed the standard mutant
   limit of its covering tests' own time (ADR-0008) under the request's
   memory cap. They run side by side in the request's pool, none started once
   the request's time is up; a mutant whose run never starts stays unjudged
   as before. Their time is the run's `mutation` step, and the unmutated runs'
   its `controls` step.
5. **The gate's own config mutates all of `src`.** Its `src` tree excludes
   nothing.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `pest.preloaded` | `fresh`, `unjudged` | `fresh` | affects results: a mutant `unjudged` leaves unjudged is judged |

## Why no verdict moves

- **A kill is evidenced by the mutant alone.** A test that fails is named
  only where the guard says the mutated file ran. A run that fails before
  any test counts only where its control passes, the same process with the
  same override serving the file unmutated, so a bootstrap that fails on its
  own, a project that cannot boot fresh, or a wrapper that breaks the boot
  unjudges the mutant and kills nothing.
- **A survivor ran the mutant.** It survives only where the guard says the
  wrapper served the mutated file and a covering test passed. A file loaded
  some other way, such as from the gate's archive, or a mutant of the wrapper
  itself, which loads before it can serve, stays unjudged.
- **Nothing the mutant runs reads the verdict.** The JUnit log is PHPUnit's,
  the exit status the process's, and the gate's plugin is told nothing to
  record. A mutant of the plugin that writes the guard file can only take
  `served` away, which unjudges it.
- **`unjudged` is the earlier behaviour.** Neither value loosens soundness,
  so neither is a loose value.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **A second gate config, its trees run by a native runner** | The files' tests are Pest closures; a native runner needs them rewritten as PHPUnit classes. |
| **A second config holding the files under Pest with `runner.workers: fresh`** | pest-plugin-mutate still swaps the mutant in after Pest boots, so a fresh process loads the same files first. |
| **Leave the files out of the `src` tree** | The floor of 100 then holds over less than `src`, and a change to them is never mutated. |

## Consequences

- ADR-0011 rule 1 holds whole: the gate mutates every file of `src`.
- A project whose plugins or boot code load its own source judges those
  files, at the cost of one fresh Pest process per such mutant.
- A preloaded mutant whose fresh run fails before any test costs a second,
  unmutated run.
- A mutant of a file that loads before the override serves anything, such
  as `MutantFile`, stays unjudged with the guard's reason.

## Related

- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): a mutant whose file was loaded first is unjudged
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): the standard mutant limit
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the package gates itself at 100
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): a kill no test is named for
- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): the override and the gate's `file://` wrapper
- [ADR-0108](0108-no-shortcut-stands-on-a-result-its-tests-or-its-evidence-no-longer-hold.md): a run that fails before any test
