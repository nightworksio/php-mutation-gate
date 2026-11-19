# ADR-0194: A first pass gives what it settled

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): when a mutant the split streams judged is given its result

## Context

A run's split streams judge in two passes (ADR-0117): the first forks each
mutant at its likely killer or its cheapest covering test (ADR-0187), the
second at the covering tests the first left. Every mutant was given its
result only once the second pass ended. A shard stopped at its deadline
(ADR-0190) while its second pass ran therefore kept no verdict of its
streams at all: on the gate's own run, shard 9 left all 3,150 of the
mutants it owned to the second wave, though its first pass had ended
minutes before.

## Decision

1. **A mutant the first pass settled is given its result before the second
   pass starts.** A mutant is settled where the second pass streams no
   site of it and runs no test file of any of its covering tests: neither
   the file declaring a covering test, nor any file a test of the second
   pass needs, which its streams load and run whole where they select no
   tests by their ids. Its result is written as it is given, so a run its
   deadline stops later keeps it.
2. **Every other mutant is given its result once the second pass ended**,
   as before.
3. **The setting `splitStream.earlyVerdicts`**, on by default, turns it
   off: every mutant is then given its result once the last pass ended.

## Why no verdict moves

A mutant's result is read from what the streams wrote of it: how each
child of it came out of each test, which tests the parent passed, which
tests a stream forked nothing in, and whether a stream loaded its file
before serving its schema or ran its site while booting.

- **What a child did** is written only by a child of that mutant, and the
  second pass forks only the mutants whose sites it streams.
- **Whether the parent passed a test, or forked nothing in it**, is
  written only by a stream that runs the test; the second pass's streams
  run only tests of the files they load, none of which declares a covering
  test of a settled mutant.
- **Whether a stream loaded a mutant's file before its schema, or ran its
  site while booting**, is written only of the sites the stream plans,
  and the second pass plans only the sites it streams.

So nothing the second pass writes is read for a settled mutant: its result
given before the second pass is the one it would be given after.

## Alternatives considered

- **Give each mutant of the second pass as soon as every stream running
  its covering tests' files ended**: every stream of a pass plans every
  site the pass streams, and any stream that starts later, one started in
  a stopped one's place among them, can find a mutant's file loaded before
  its schema was served, which leaves it to its own run. A mutant of the
  second pass is not settled until no stream of it can start.
- **Settle by the covering tests a second pass runs, not their files**: a
  stream selects its tests by a file of their ids, and runs every test of
  its files where that file cannot be written; the test files are what a
  stream is certain to run no more than.

## Consequences

- A shard stopped in its second pass keeps the verdicts of the mutants its
  first pass settled; only the rest go to the second wave.
- The order of a run's records changes: settled mutants come first.

## Related

- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): split streams and their passes.
- [ADR-0187](0187-a-mutant-with-no-known-killer-forks-first-at-its-cheapest-test.md): the first pass's fork at the cheapest covering test.
- [ADR-0190](0190-a-shard-hands-what-it-left-to-a-second-wave.md): the second wave that judges what a stopped shard left.
