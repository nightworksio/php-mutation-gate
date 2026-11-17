# ADR-0179: A mutant whose killer the first pass fell back on runs its own run

**Status:** Accepted
**Date:** 2026-11-17

## Context

Under `splitStream.killersFirst`, a stream's first pass forks each mutant
at its likely killer, and past it where it fell back on that test
(ADR-0174). Some likely killers cannot be forked in: the gate's own runner
contract tests say no fork may judge them once they reach their fixture
copy (ADR-0176), and a test that holds a writable file or a socket at the
mutant's site leaves that mutant alone (ADR-0175). A covering test the
streams fell back on leaves its mutant for its own run unless a stream
kills it elsewhere.

Every mutant such a first pass left undecided went to the second pass,
which streams each of its covering tests the first pass did not reach,
often whole test files of other parts. The mutant then still needed its own
run, which runs its likely killer first, whenever the second pass did not
kill it. In CI run 44152440509, each of three shards spent its last 8
minutes or more on the 1,000 to 1,500 mutants its first pass left.

## Decision

1. **`splitStream.fallbackOwnRun`, on by default: a mutant the first pass
   left undecided, whose likely killer it fell back on for that mutant,
   skips the second pass.** It goes to its own run, which under
   `tests.order` `killers-first` runs that killer first.
2. **Off, such a mutant streams in the second pass as before**, and runs
   its own run where no stream decides it.
3. A mutant with no likely killer, or whose killer the first pass forked
   it in, goes to the second pass as before.

## Why no status moves

- A stream that falls back on a covering test already leaves the mutant to
  its own run unless a stream kills it, so skipping the second pass only
  changes which run finds the kill: the own run's killer-first order, with
  its verification, instead of another test's stream.
- A mutant that survives survives its own run of every covering test either
  way.
- The test named as a kill's killer can differ: the likely killer instead
  of an earlier covering test the second pass reached first.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep streaming every undecided mutant in the second pass | It streams foreign test files for mutants that, in most cases, need their own run anyway. |
| Run the fixture tests in a process forked per child | A copy of a fixture is 23 MB in nearly 4,000 files (ADR-0176). |
| Skip the second pass for every mutant with a likely killer | A mutant whose killer did fork but let it survive still needs the other covering tests, and streaming them is cheaper than an own run. |

## Consequences

- The second pass streams only the mutants a stream could still judge.
- More mutants run their own runs; each starts with the test most likely
  to kill it.

## Related

- [ADR-0136](0136-a-first-pass-stream-may-run-only-the-tests-that-killed-before.md)
- [ADR-0174](0174-a-mutant-is-judged-whole-in-the-one-part-that-owns-it.md)
- [ADR-0175](0175-a-test-that-holds-a-descriptor-at-a-site-leaves-that-sites-mutant-alone.md)
- [ADR-0176](0176-a-test-can-say-no-fork-may-judge-it.md)
