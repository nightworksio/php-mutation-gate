# ADR-0305: A test part is priced by its own tests

**Status:** Accepted
**Date:** 2026-11-20
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): how a shard that streams one part of the test files is priced
**Amends:** [0195](0195-a-streamed-job-is-priced-at-its-wall-time.md): §1, a part's share of the cost

## Context

A run that cuts its test files into parts gives each shard every unit of
its package and one part of the test files. The shard judges each mutant
only by the tests of its part that cover it. The plan nevertheless
priced every part alike: each unit was weighed at its cost over the
number of parts. A unit whose covering tests all lie in one part was
priced in every part. In the parts its tests miss, it costs nothing.

The shards themselves place the test files into parts. Each file goes,
longest first by the map's times, to the part with the least time so far.
That placement lived in the Pest adapter, where the plan could not read it.

## Decision

1. **The placement of test files into parts lives in Core.**
   `TestFileParts` places each file, given its seconds, into a part by the
   same rule. The Pest adapter's `TestParts` sums each file's tests and
   asks it, so the plan and the shards place files by one rule.
2. **A unit's weight in a part is its cost times the share of its covering
   tests' seconds in that part.**
   - The plan reads each test's seconds and file from its coverage map, a
     test's class standing for its file (as the second wave's cut does,
     ADR-0195).
   - A unit with no timed covering test keeps its cost over the parts.
   - A held unit judged whole stays whole (ADR-0162).
   - The parts of a unit still add up to its cost.
3. **A shard weighs its units by the same prices.** A shard reads the map
   it is handed, which keeps every test's time, so it places the test files
   into parts as the plan does. It weighs each unit at its price in its own
   part where it runs in batches: under `triage.budget`, and where a shard
   that can no longer pass stops in chunks.

## Why no verdict moves

A price decides only how many shards there are, what each is expected to
take, and, under a budget, which units fit the time left. Every mutant a
part judges is still judged by the part's covering tests, with the same
verdict, and the shards place their test files exactly as before. Under
`triage.budget` a unit priced at nothing in a part its tests miss now fits
there, and one priced at its whole cost may not: the units a budget leaves
out of time follow their price in the part.

## Alternatives considered

- **Price each part by its own test files' seconds alone:** a part's cost
  is mostly its mutants' forks, which follow the units its tests cover.

## Consequences

- A shard whose part holds the tests of the costliest units is expected to
  take longer than the others, and a pulling job fits its items by that
  price (ADR-0197).
- The plan places files of equal time by class name, and the shards by
  path. Files that tie can fall to different parts in the plan than in the
  shards.

## Related

- [ADR-0303](0303-a-job-runs-its-own-tail-where-its-wave-outnumbers-the-pool.md): a job runs its own tail where its wave outnumbers the pool.
