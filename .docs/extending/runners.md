# Runners

A runner mutates the code and runs the tests against each mutant: Pest,
Infection, the gate's native runners for PHPUnit, PhpSpec, Codeception and
Testo, or one an extension adds. It implements
`NightWorksIO\MutationGate\Port\Runner`
([ADR-0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md)),
is registered with `Extensions::withRunner()`, and is chosen by `runner`.

## What the port asks

A failed opening run, an unsupported version or a result that does not add up
is answered as `CannotJudge`, with the runner's output. Every process it
starts runs without the variables the gate withholds from the project's
tests.

| Method | What it answers |
|--------|-----------------|
| `identity()` | Its name, the exact version of every package it drives, and a digest of the PHP it runs on, which the proof key reads |
| `behaviour()` | How it behaves where the flows must know it: `RunnerBehaviour::standard()` unless it behaves otherwise |
| `listing()` | The suites' tests and the groups each is in, as the runner lists them, running none |
| `coverage()` | Which tests run which line, with each test's duration: by running the suite, or reading a map another job wrote |
| `testsIn()`, `judges()` | The tests a test file holds, and the test files that can judge a mutant of a file, by the runner's own rules |
| `startUp()` | How long one run of no test takes, which every mutant's run pays before its first test |
| `mutate()` | Every mutant's result for the requested files, with the tests that killed each and the evidence of its kills, as far as the runner saw them ([ADR-0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md)); a runner that can keep a mutant from its run asks the pre-checker first |
| `controls()` | What each unmutated control finds, its file served as a mutant's is, so a kill is checked on the original |
| `checkable()` | A mutant as a static analyser checks it |
| `retry()`, `reproduce()` | Mutants run again as the run that made them asked, and one mutant run again on its own |
| `markers()` | The runner's own ignore markers, each with the `ignores.entries` entry that replaces it |
| `definitions()` | The files that define how it runs, such as its own config: a change to one reaches everything |
| `names()` | What each test id is: the whole test, or the data set row that folds into it |
| `rootedAt()` | The runner in a package's directory, for a monorepo |

The port's doc blocks say each answer in full.

## Holding it to the contract

A runner answers `behaviour()` with `RunnerBehaviour::standard()` unless
it behaves otherwise, and answers `coverage()` for a `CoverageRun`, the
tests it runs under coverage, and for a `CoverageRead`, the directory of a
map another job wrote. `RunnerContract::failures($runner, $library)` holds
it to what every runner answers, over a small library installed as a
project of its own
([ADR-0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md)):

1. Copy `vendor/nightworksio/mutation-gate/resources/contract/runner/src`
   into the library's `src`, and map the `Library\` namespace to it in the
   library's `composer.json`.
2. Write the tests `LibraryTest` lists, in the runner's own framework, each
   doing what its docblock says. Between them they kill one change of
   `Money`, let one survive, run none of a third and never let the loop of a
   fourth end; and they let the change of `Held` survive under the group
   `holds:src/Held.php`, which a test outside that group would kill.
3. Build the runner in the library's root, holding each mutant to a limit
   short enough that the endless loop times out.
4. Say how the runner spells the library, and ask for its failures:

   ```php
   $library = RunnerLibrary::spelling(
       adds: 'Plus',
       large: 'GreaterThan',
       unused: 'Minus',
       drains: 'Decrement',
       held: 'Plus',
       testEnding: 'Test.php',
   );

   expect([...RunnerContract::failures($runner, $library)])->toBe([]);
   ```

   Each argument is the runner's name for the mutator that makes that
   `LibraryChange`. `$library->unmeasured()` is the library as a runner
   spells it that reports neither the line a mutant ends on nor how
   long it took.
