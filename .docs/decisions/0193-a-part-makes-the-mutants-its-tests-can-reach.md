# ADR-0193: A part makes the mutants its tests can reach

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): which mutants a shard that judges by a part of the test files makes

## Context

Where a run cuts its test files across its shards (ADR-0117), each shard
made every mutant of every unit, then left to the other parts each mutant
no test of its own part covers, marking it not reached. On the gate's own
run that is 58,051 mutants made and marked in each of 19 shards, about
3,200 of them judged there: making and marking took about three of a
shard's eight minutes. The verdict merged the parts' outcomes of each unit
and refused parts that held other mutants than each other.

## Decision

1. **A part makes only the mutants of the lines its tests cover and of the
   lines no test covers.** In a patched Pest run that splits its streams
   and judges one part of the test files, the lines of each file that no
   test of the map covers, or that a test of its part covers, are the lines
   it mutates; Pest makes a mutant where any line it spans is one of them,
   as it does for a run of covered lines alone. A file it mutates every
   line of is made whole; one it mutates no line of is not made. A run
   that records each test's lines in place of the map, or that is not cut,
   makes every mutant. The setting `splitStream.reachableOnly`, on by
   default, turns it off: each part then makes every mutant.
2. **A part lists what it made.** Each file it made on some lines alone is
   listed beside the run's results with its count of lines and the spans
   it made; the gate clears the list before the run, reads it after, and
   the shard's result holds it under `madeLines`, by each file's path.
3. **The verdict merges parts made so, and checks them**: for each unit,
   every part holds exactly the mutants of all its parts that span a line
   it made, a part that lists no lines of a file holding every mutant of
   it; every part that lists a file counts its lines alike; and the parts
   together made every line of each file of the unit. Otherwise the unit
   cannot be judged. Parts that made every file whole are checked as
   before: each holds the same mutants.

## Why no verdict moves

- **Every mutant a part judges or owns is made there.** A part judges a
  mutant by the covering tests it holds, or, judging whole, by every
  covering test of a mutant it owns (ADR-0174); it owns one by its first
  covering test or its likely killer, both covering tests it holds, or,
  where no test covers it, by its id (ADR-0171). A held covering test covers a line the
  mutant spans, so that line is made; a mutant no test covers spans only
  lines no test covers, all of them made in every part.
- **A mutant a part does not make is one it would have left.** Such a
  mutant spans only lines covered by tests of other parts, so the part
  neither judges nor owns it, and marked it not reached, an outcome the
  merge takes no answer from.
- **No mutant goes unmade.** The check of decision 3 refuses a unit whose
  parts together left a line unmade, so a cut the parts disagree on cannot
  drop a mutant from the verdict; and a part holding other mutants than
  its lines say is refused as parts holding other mutants were.

## Alternatives considered

- **Make every mutant once in the plan, and ship each part its own**: the
  plan's job would make all 58,051, and every part would download the
  made mutants and their copies, about 460 MB unpacked, and still make the
  schema of each file. A part making the mutants it can reach needs no
  artifact, and makes the mutants of its tests' lines and of uncovered
  lines alone.
- **Make every mutant and mark faster**: the making itself stays, and
  every not-reached record is still written, read and merged.

## Consequences

- A part's result holds the mutants it made, not every mutant of its units.
- A shard result of a part lists `madeLines`; one made whole lists none.
- A verdict reading parts that made only their lines needs every part's
  result, as before.

## Related

- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): split streams and parts of the test files.
- [ADR-0166](0166-a-run-makes-its-mutants-side-by-side.md): making side by side, which now makes a part's lines.
- [ADR-0171](0171-a-test-no-loaded-file-declares-leaves-the-cut-standing-and-an-uncovered-mutant-is-judged-once.md), [ADR-0174](0174-a-mutant-is-judged-whole-in-the-one-part-that-owns-it.md): ownership across parts.
