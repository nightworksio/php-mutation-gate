# ADR-0175: A test that holds a descriptor at a site leaves that site's mutant alone

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): decision 7's fallback, for a test that holds a descriptor only as it reaches a site
**Amends:** [0174](0174-a-mutant-is-judged-whole-in-the-one-part-that-owns-it.md): decision 4's fork past a likely killer, for a mutant whose site its test reached holding a descriptor

## Context

A stream forks no child while its test holds a descriptor a child would
share (ADR-0117 decision 7): a regular file open for writing, a pipe open
for writing or a socket the stream did not hold as it started. It checked
twice: as a test began, and at each site the test reached. A test that held
one at a site fell back whole: the stream forked nothing more in it, and
every mutant it covers was left for its own run unless another test killed
it.

Run locally on the plan of run 44104819119, shard 9 of 19 judging whole
fell back on 638 tests in its first pass. 630 of them held a descriptor only
at one site: none as they began, and none at the sites they had forked
before. Those tests write a file for a moment, and the code under mutation
runs while it is open: 242 of the sites were in `src/Mutator/Engine/Printer.php`.
3 had started a PHP process. The fallen-back tests left 2,096 mutants to a
second pass of every covering test, which ran over the other parts' files
for most of the shard's tail.

## Decision

1. **A test that holds a descriptor a child would share as it reaches a
   site falls back for that site's mutant alone.** The stream forks that
   mutant nowhere else in the test, which reached the site unmutated, and
   writes a fallback line naming the test and the mutant. It goes on forking
   the test's other sites: a site the test first reaches once the
   descriptor is closed forks as in any test, and a site it reached before
   forked already.
2. **A covering test that forked a mutant nowhere leaves that mutant for its
   own run**, as a test that forked nothing leaves every mutant it covers,
   unless another test kills it. A survivor still survived every covering
   test.
3. **The stream forks that mutant at the later tests that reach its site**,
   as it forks a mutant past a likely killer it fell back on (ADR-0174).
4. **A test that holds a descriptor as it begins, runs in a separate
   process, or started a PHP process before it reached a site still falls
   back whole.** A process it started ran without the child's mutant, at
   sites the stream never sees.
5. **A stream that records its tests' lines records a test that held a
   descriptor at a site as run in a process of its own**, once, as it did
   when the test fell back whole.

## Why no verdict moves

- A child forked at a site where no descriptor is held shares nothing a
  write could change in its control, wherever in the test the site is.
- The site the test reached holding a descriptor is not forked in that
  test, there or at a later reach, so no child misses an execution of the
  mutant.
- The mutant that test leaves still runs its own run, narrowed to its
  covering tests, unless a child kills it at another test: a kill stands on
  a test that failed against its unmutated control, and a survivor on every
  covering test.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep falling back on the whole test | Every mutant the test covers waits for its own run or for a second pass over other parts' files, for a descriptor held at one site. |
| Fork with the descriptor held and copy the file for the child | A pipe or a socket has nothing to copy, and a file the child writes through a shared offset moves the parent's. |
| Close the descriptor around a fork | The test's own code holds it; the stream cannot reopen it as it was. |

## Consequences

- A test that writes a file for a moment forks every site it reaches
  without one open, so its mutants are judged in the stream.
- The mutant whose site ran while the file was open still runs its own run
  of its covering tests, unless another test kills it.

## Related

- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0150](0150-a-mutant-reaches-the-php-processes-its-tests-start.md)
- [ADR-0174](0174-a-mutant-is-judged-whole-in-the-one-part-that-owns-it.md)
