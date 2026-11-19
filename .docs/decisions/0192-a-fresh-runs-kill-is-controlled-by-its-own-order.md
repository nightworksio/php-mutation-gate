# ADR-0192: A fresh run's kill is controlled by its own order

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0148](0148-a-mutant-of-a-file-pest-loads-first-is-judged-in-a-fresh-run.md): the evidence of a kill a fresh run names its tests for; [0143](0143-a-kills-control-replays-its-order.md): which kills a runner that replays its own kills still controls by their order

## Context

A mutant of a file Pest's warm worker had loaded runs again in a fresh
`pest` process (ADR-0148), which stops at its first failure. Its evidence
held no order. Pest replays the kills of its own runs itself, so a kill's
control ran its killers alone. Where the fresh run's filter would not fit,
the run loaded every test file, and the control ran every test of every
file: the whole suite.

On #599's gate run, the 22 kills of `src/Core/Format/Json.php` came from
fresh runs that each stopped after at most 1.2 seconds. Their two controls
took 284 s, 63% of the file's 451 s.

## Decision

1. **A fresh run's kill by a test named holds its order.** Its evidence
   lists the test cases Pest's JUnit log names, in the log's order, up to
   the last of its killers. PHPUnit writes a test case as each test ends, so
   that order is the order the run took.
2. **A kill whose evidence holds an order its runner did not replay is
   controlled by that order under `flaky.killControls: order`.** This holds
   even where the runner replays its own kills. The control runs those
   tests, in their order, with the file served unmutated, allowed the
   standard limit of their time. A file's kills
   are first controlled by the longest of their orders that starts with a
   kill's own (ADR-0089). Under `flaky.killControls: alone`, nothing changes.

## Why no verdict moves

- **It is the control ADR-0143 asks of every kill.** A kill stands where
  the tests its run took up to its last killer pass, unmutated, in that
  order. A fresh run's kill now has that control, as a native runner's does.
- **The control is the run, unmutated.** The run took its tests in Pest's
  own order up to its killer. The control selects those tests as any
  control selects a set of tests: by a filter, by their ids under
  `pest.longFilter: set` where no filter can hold them, and by the request's
  tests otherwise. Pest keeps them in the same order. It loads every test
  file, as the run did where it left its filter out, and as the killers'
  control of a filtered run already did.
- **The tests past the killer judge nothing.** The run never started them.
  A whole-suite control ran them, and a failure among them could only leave
  a kill unjudged that its own tests vouch for.
- **A kill run by a filter gains the tests before its killer.** Its control
  ran the killers alone. It now also runs the tests the run took before
  them, which only makes it stricter.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Order the fresh run's tests killers first** | The control ran the whole suite whatever the order, so ordering alone shortens nothing. With the order recorded, the fresh runs' kills on #599 already came within 1.2 s of their start. Ordering would need the gate's plugin, which a fresh run keeps out, or a PHPUnit result cache written for it. |
| **Read the order from the gate's plugin** | A fresh run keeps the plugin out, as its code may be the code under mutation (ADR-0148). PHPUnit's JUnit log is not. |
| **Keep whole-suite controls for runs that loaded every file** | They run tests the run never started, for no verdict. |

## Consequences

- A fresh run's kill costs a control as long as the run, not the whole
  suite.
- A kill run by a filter is controlled by the tests before its killer as
  well as the killer.

## Related

- [ADR-0089](0089-one-replay-to-the-end-of-a-whole-order-vouches-for-its-prefixes.md): a file's kills vouched for by one order
- [ADR-0143](0143-a-kills-control-replays-its-order.md): a kill's control replays its order
- [ADR-0148](0148-a-mutant-of-a-file-pest-loads-first-is-judged-in-a-fresh-run.md): fresh runs
