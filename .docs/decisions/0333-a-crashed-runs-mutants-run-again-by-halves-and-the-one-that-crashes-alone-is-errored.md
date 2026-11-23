# ADR-0333: A crashed run's mutants run again by halves, and the one that crashes alone is errored

**Status:** Accepted
**Date:** 2026-11-23

## Context

A mutation run that crashes leaves the mutants it had not killed unjudged,
for a later wave (ADR-0330). A mutant that ends its own test runner does so
every time it runs: the wave after crashes on it as the first did, and every
mutant that shares its run stays unjudged with it.

A mutant that crashes the tests is detected. `errored` is the status of such
a mutant, and it scores as a kill. A crash tells the mutant from its
original only where the same tests finish on the original.

## Decision

1. **The job that ran a crashed run runs the mutants it left again**, in
   one run, as the time left allows.
2. **A run again that crashes too is narrowed.** Where it told of some
   mutants, those are judged by it and the rest run again. Where it told of
   none, the mutants are split in two halves and each half runs again.
3. **A mutant whose run alone crashes is judged by an unmutated control**
   of the tests that judge it, as a mutant out of memory is (ADR-0004).
   Where the control passes, the mutant is `errored`, saying that its run
   alone crashed. Where the control fails, it fails unmutated. Where the
   control does not finish, the mutant is unjudged, saying why.
4. **A mutant no time is left to run again stays as the crash left it**,
   and one whose control has not run is unjudged before its control: the
   wave after takes both.

## Consequences

- One mutant that ends its runner costs the runs that find it, about the
  binary logarithm of the mutants that shared its run, and no other
  mutant's verdict.
- A job fails for a crash only where it leaves mutants the crash left
  unjudged (ADR-0330).
- The time the runs again take is the step `suspects`, and the control is
  of the kind `crash`.
