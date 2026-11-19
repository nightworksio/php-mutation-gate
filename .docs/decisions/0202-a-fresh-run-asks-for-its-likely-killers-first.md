# ADR-0202: A fresh run asks for its likely killers first

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0148](0148-a-mutant-of-a-file-pest-loads-first-is-judged-in-a-fresh-run.md): the order a fresh run runs its covering tests in

## Context

A mutant of a file Pest loads before the mutant is served is judged in a
fresh `pest` process over its covering tests (ADR-0148). None of the
gate's plugin's variables reaches that process, so the plugin never
rewrites its arguments, and the run takes Pest's own order. A mutant's
own run takes its likely killers first and the rest fastest first
(ADR-0013).

On the gate's own run the costliest mutants sit in config definitions and
flow code that the test harness loads first. Their median is 1,638 covering
tests, and their killer takes 0.114 s. The fresh run stops at its first
failing test, but in Pest's own order it can run hundreds of tests before it
reaches that one. On probe 44424669540 a shard spent 426 of its 470 s in one
fresh process and judged nothing.

## Decision

1. **A fresh run asks Pest for the order a mutant's own run takes**: its
   likely killers first, as the kill history ranks them, and the rest of its
   covering tests fastest first, by the map's times. The gate writes that
   order as the history Pest reads, the same one a mutant's own run reads
   (ADR-0013), in the mutant's own directory. It passes the history's
   directory and `--order-by=duration-ascending,defects` on the run's
   command line, so no plugin is involved.
2. **Its control asks for the same order** from a copy of its own. PHPUnit
   writes its history back as a run ends, so each run reads its own copy.
3. **`pest.freshOrder`**, on by default, turns it off. So does
   `tests.order: runner`. A project whose installed Pest is a branch rather
   than a release keeps Pest's own order, because Pest reads a history only
   when it names Pest's release.

## Which verdicts can differ

A covering test that does not depend on the tests before it fails or
passes in any order. For such tests the order decides only which failing
test the run meets first, and so which one is named for the kill, as it
does for a mutant's own run (ADR-0013). The limit, the memory cap, the
guard and the control are as before (ADR-0148).

An order-dependent test can move a status either way, under any order. A
test that fails only after another test ran mutated kills in one order
and not in another. So does a test that an earlier test hides the mutant
from, for example by filling a memoised value first: it kills where it
runs before that test, and lets the mutant survive where it runs after
it. A mutant's own run already reorders its tests the same way, likely
killers first, so its status depends on the order in the same cases. A
kill's control replays the kill's order (ADR-0143), so a test that fails
unmutated in that order never counts as a kill.

Like the order of a mutant's own run, the setting is in no proof key.

## Alternatives considered

- **Let the gate's plugin into the fresh run to reorder it**: the plugin's
  code is what may be mutated there. ADR-0148 keeps it out for that reason.
- **Run only the likely killers, then the rest**: two processes per
  mutant, and a second boot for every mutant its killer spares.

## Consequences

- A fresh run with a known killer ends after its boot and that test.
- Two small files are written per fresh run, next to its log.

## Related

- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)
- [ADR-0143](0143-a-kills-control-replays-its-order.md)
- [ADR-0148](0148-a-mutant-of-a-file-pest-loads-first-is-judged-in-a-fresh-run.md)
- [ADR-0187](0187-a-mutant-with-no-known-killer-forks-first-at-its-cheapest-test.md)
