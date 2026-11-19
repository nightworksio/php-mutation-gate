# ADR-0160: A mutant with no history takes its file's killers

**Status:** Accepted
**Date:** 2026-11-16
**Amends:** [0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): what a mutant takes where neither it nor its function has a history

## Context

Under `tests.order: killers-first`, a mutant's covering tests run with its
likely killers first and the rest fastest first, file by file. A mutant takes
its own history, or else its function's (ADR-0013). A mutant of a function
with no history runs its covering tests fastest first.

On the gate's own `src/Core/Config/**`, a line is covered by about 2,390
tests worth about 500 s of their own time. A mutant whose killer sorts late
runs past `timeouts.most` and is then judged in heavy parts. In run
43755388963, `Builtins.php:337` was killed by `ConsoleTest` only after
528 s, and the heavy parts of the `src/Core/Config` items took 2,700 to
10,000 s each.

## Decision

1. **A mutant with no history of its own or of its function's takes its
   file's.** The rankings of every function of its file are summed test by
   test, and the five tests with the most kills run first, most kills first.
   They run as likely killers do: as defects in the order PHPUnit reads.
2. **Its own history, then its function's, still wins wherever one exists.**

## Why no verdict moves

Ordering decides which test is found first, never whether one is
(ADR-0013). Every covering test still runs before a mutant survives.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Rank every test by its kills over the whole ledger | A test that kills much elsewhere says little about this file; the file's own killers say the most. |
| Put the test file named for the source file first | It holds only where a project names its tests that way. |

## Related

- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md)
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)
