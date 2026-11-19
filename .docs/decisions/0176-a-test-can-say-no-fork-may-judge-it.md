# ADR-0176: A test can say no fork may judge it

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): decision 7's fallback, for a test that says no fork may judge it

## Context

A stream forks no child where its test holds a descriptor a child would
share (ADR-0117 decision 7, ADR-0175). The check sees descriptors, not what
they guard: a lock open for reading and writing that no test writes
through counts, and a directory two processes write into does not.

The gate's own runner contract tests run in copies of fixture projects,
one per process (`OwnFixtures`), each kept alive by a lock its process
holds until it ends. The lock was opened for reading and writing, so once a
stream ran its first fixture test, every later test in that stream held a
descriptor at every site. Run locally on the plan of run 44104819119, shard
9 of 19 wrote 7,123 held-descriptor fallbacks in its first pass; 6,920 of
them were these locks. Three of the first pass's eight streams forked no
child at all after their first fixture test, since the contract tests come
first in each stream's files, and the second pass fell back on most of the
tests it ran.

Opening the lock for reading alone stops it from counting, but it was also
what kept a fixture test from forking: a child forked in one, before it
reached the copy, would write into the copy its parent writes into, and a
failure that interference caused would read as a kill.

## Decision

1. **`Stream::unforkable()` says no fork may judge the current test from
   here.** In a child, it writes a fallback line naming the test and the
   child's mutant, which leaves that mutant to its own run of its covering
   tests unless another test kills it (ADR-0175), and ends the child as a
   passing one ends, so its parent reads no kill of it. In the stream's
   parent it falls back on the test, which forks nothing more. Where the
   process runs no stream it does nothing.
2. **The gate's fixture copies say so as a test reaches one.** Each path
   `OwnFixtures` places in a copy calls it, so a fixture test forks only at
   the sites it reaches before it touches the copy, and a child forked
   there stops before it does.
3. **A fixture copy's lock is held through a descriptor open for reading
   alone.** `flock` takes it so on every platform the suite runs on; the
   tests after a fixture test in the same stream fork as any test does.

## Why no verdict moves

- A child that stops at `unforkable()` gives no verdict at that test; its
  mutant runs its own run of the test, as a mutant of a test the stream
  fell back on does.
- The parent falls back on the test from that point, so no child is forked
  where it would share the copy; the children forked before it either stop
  at the same call or never reach the copy.
- A read-only descriptor is one a child cannot write through, so a fork
  past it changes nothing its parent reads.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep the lock open for writing | Every test after the first fixture test in a stream forks nothing, so most of each stream's mutants wait for their own runs. |
| A copy of the fixture for each child | A copy is 23 MB in nearly 4,000 files and took 1.6 s locally; a child forked at every early site would make one. |
| Run the fixture tests last in each stream | It needs to know which tests hold such state before the stream runs them, and still forks a child into its parent's copy. |
| Lock by process liveness and no descriptor | It also stops the lock from counting, and still forks fixture tests into a shared copy. |

## Consequences

- A stream's tests after a fixture test fork again; only the fixture tests
  themselves fall back, from the point they reach their copy.
- A test that shares state a fork must not touch can say so the same way.

## Related

- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0174](0174-a-mutant-is-judged-whole-in-the-one-part-that-owns-it.md)
- [ADR-0175](0175-a-test-that-holds-a-descriptor-at-a-site-leaves-that-sites-mutant-alone.md)
