# ADR-0090: Each test of a mutant's run is held to a multiple of its own time

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): its limits on a mutant's run, with one on each of its tests

## Context

ADR-0008 holds a mutant's run to two limits: a whole limit, three times its
covering tests' own time plus three times the start-up, and a silence
limit, the same rule of its slowest covering test's time, counted from the
last test that finished. Both are floored at `timeouts.seconds`, or at
`timeouts.tighter.floor` for the mutators it lists.

A mutant that hangs one test is stopped only once the silence limit runs
out, so a hang costs at least the floor. On run 42036642441, shard 3 spent
564 core-seconds on 21 timeouts: 14 silence stops between 12 and 30
seconds, and 7 at the 30-second whole limit. The tests they hung take
tenths of a second each, as the coverage map timed them.

The runner already tells the gate when each test starts and ends: a patched
Pest's mutant process beats on its error output, PHPUnit writes its events
as text under `--log-events-text`, and the gate's PHPUnit extension writes
a line for each test to the results file of a native run.

## Decision

1. **Each test the coverage map timed is held to a limit of its own:**
   `timeouts.perTest` times its own time, never under `timeouts.testFloor`
   seconds, 3 and 3 by default. A test still running past it stops the
   run, as the silence limit does, and the mutant times out, its reason
   naming the test and the limit. A test the map did not time is held only
   to the run's silence and whole limits. `timeouts.perTest: false` holds
   no test to a limit of its own. Both keys are in key item 3 (ADR-0007).
2. **The watchdog runs where the silence limit runs.** Under `pest.patch`,
   Pest's parent reads each mutant process's error output, on which the
   process now also writes each test's id, rawurlencoded, as it starts. The
   PHPUnit, PhpSpec, Codeception and Testo runners read the results file,
   whether the run is a fresh process or a warm worker's child. A run held
   to no silence limit, such as one whose filter was left out, still holds
   each timed test to its own.
3. **A timeout's unmutated control runs under the same watchdog.** Where a
   test of the control runs past its own limit too, the control runs out,
   and the timeout is too slow to judge (ADR-0008), never killed. A part
   of a heavy mutant's run, and its control, are held so too.
4. **A run a test's own limit stopped is controlled by that test alone.**
   The tests after it never ran with the mutant in place, so a slow one
   among them says nothing of the stop. The control runs the stopped test
   alone, selected as the run selects tests, under the same watchdog. Where
   the run loaded test files with no filter, the runner cannot run one test
   alone, and the control runs every test the run would.

### Why a stop at a test's own limit is a timeout and not a guess

- **The same evidence as a silence stop.** Both stop a run where a test has
  run longer than a multiple of what the coverage map measured of it. The
  silence limit weighs the slowest covering test; this weighs the test that
  is running, so a hang in a fast test stops at that test's own scale.
- **The control decides.** A stop only times a mutant out; triage still
  kills it only where its tests, served unmutated, pass within the limit
  under the same watchdog. A test that is merely slow on the runner runs
  past its own limit in the control too, and the timeout stays too slow to
  judge.
- **The stopped test alone is the evidence.** Its control tells whether
  that test, unmutated, finishes within the limit the stop measured it
  against. It does not catch a test that is slow only after particular
  other tests have run.
- **A floor keeps the noise out.** A test the map timed at 0.05 s is
  allowed 3 s, so scheduling and garbage collection on a busy runner do not
  stop it.

## Consequences

- A mutant that hangs a fast test costs about `timeouts.testFloor` seconds
  of its slot instead of the silence floor, 7 to 30 seconds on shard 3.
- The patched plugin writes one more line on a mutant process's error
  output per test, and Pest's parent parses it.
- Infection keeps its own limits: it starts its own processes and reports
  no test's start.

## Alternatives considered

| Alternative | Why not |
| --- | --- |
| Lower `timeouts.seconds` | Lowers every run's whole limit, so a mutant of a file with slow tests times out falsely, and lowers the silence floor only by the same amount. |
| A watchdog inside the mutant's process | A test that hangs in a busy loop or a blocking call never yields to it; only the parent, which the hang does not reach, can stop it. |
| Hold untimed tests to the floor | A test the map did not time can take any time; stopping it at 3 s would time out mutants whose tests are merely slow. |
