# ADR-0187: A mutant with no known killer forks first at its cheapest test

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): where a first-pass stream forks a mutant the ledger names no killer of; [0136](0136-a-first-pass-stream-may-run-only-the-tests-that-killed-before.md): which mutants a killers' pass runs

## Context

A first-pass stream forks a mutant the ledger names a likely killer of at
that test alone, and every other mutant at every covering test of the
shard's part it reaches (ADR-0117). The part's covering tests are cut
across the pass's streams, which run side by side and share no kills: a
mutant reached by tests of eight streams is forked at least once in each
of them, even where the first child of the first stream already killed it.

Run locally on the plan of the gate's own shard 9, the second pass judged
about 100 mutants in its first twelve minutes, and about 2,100 of the
shard's mutants were still unjudged at its 25-minute cap. A mutant's own run
already runs its covering tests fastest first after its likely killers
(ADR-0013); a stream had no such order.

## Decision

1. **`splitStream.firstFork` `cheapest`, the default, forks a mutant the
   ledger names no likely killer of at one covering test in the first
   pass:** the one of the shard's part the coverage map timed fastest, the
   first in covering order of those as fast, a test the map did not time
   after every one it did. The first pass streams that test alone for it.
2. **Where no child killed it there, the second pass forks it at every
   other covering test**, and at every one where the first pass left it at
   that test (ADR-0175); a mutant with no other covering test is decided
   by the first pass. A timeout there does not decide it: the second pass
   still looks for a kill.
3. **The ledger's likely killer stays first.** A mutant it names a killer
   of forks there, and is owned by that killer's part (ADR-0174), as
   before. A mutant no test of the part covers is unchanged.
4. **`splitStream.firstFork` `every` forks such a mutant at every covering
   test of the part in the first pass**, as before this decision. The key
   is in no proof key.

## Why no verdict moves

A mutant's status is its first covering test's kill, else its first
timeout, else survival once every covering test ran with the mutant in
force and passed in its parent (ADR-0117). Under `cheapest` a mutant stops
being forked only once a child killed it, the same status any later kill
would give. One that is not killed is forked at every covering test it was
not forked at, so every test's outcome is read before a timeout or a
survival is given, and a test the first pass did not run leaves the mutant
to its own run, as before. Only which kill is named first may differ: the
fastest killer, where the stream's cut named another.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep forking at every covering test (`every`) | Kept as the setting: each stream holding a covering test pays a child for a mutant another stream killed. |
| Share kills between a pass's streams | A child already running is not stopped by another stream's kill, and the streams would read a shared file at every fork; the cheapest-first fork removes most such children before they start. |
| Order each stream's tests fastest first | A stream's test order decides its makespan, not which tests fork a mutant; every covering test still forks it. |
| Fork at the cheapest test of every covering test, not the part's | A test the part does not hold is streamed by no first-pass stream of the shard. |

## Consequences

- A mutant that survives pays for its cheapest test in the first pass and
  its other covering tests in the second, as one the ledger names a killer
  of does.
- A killed mutant costs about one child of its fastest covering test.

## Related

- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0136](0136-a-first-pass-stream-may-run-only-the-tests-that-killed-before.md)
- [ADR-0174](0174-a-mutant-is-judged-whole-in-the-one-part-that-owns-it.md)
- [ADR-0175](0175-a-test-that-holds-a-descriptor-at-a-site-leaves-that-sites-mutant-alone.md)
