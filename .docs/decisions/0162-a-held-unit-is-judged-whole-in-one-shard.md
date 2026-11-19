# ADR-0162: A held unit is judged whole in one shard

**Status:** Accepted
**Date:** 2026-11-16
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): decision 9, what each shard takes of a held unit under `splitStream.sharding` `tests`

## Context

Under `splitStream.sharding` `tests`, every shard of a package takes every
unit of it and streams its own part of the test files (ADR-0117). A held
unit runs in its own invocation, judged by the tests that hold it
(ADR-0005), so every shard started one invocation for each held unit: a Pest
run that loads its suites and makes the unit's mutants, then its own runs
and controls, to judge them by the shard's part of a group of 2 to 143
tests. On the gate's own full run every item held the same 13 units; a
local item spent its first seven minutes on their invocations, about 45 to
60 s each, before its own stream began. Most parts hold none of a group's
tests, and judged nothing.

## Decision

1. **Under `splitStream.sharding` `tests`, the default, each held unit goes
   to one shard of its package alone,** the first held unit to the first
   part, the next to the next, in turn, and is judged there whole: its
   invocation runs every test that holds it, as a run that does not cut its
   test files does. Its cost is weighed whole in that shard.
2. **`splitStream.sharding` `tests-every-part` keeps every held unit in every
   shard,** judged by that shard's part of its holding tests, as before this
   decision.
3. **Every other unit is unchanged:** every shard takes it and streams its
   own part.

## Why no verdict moves

A held unit is judged by every test that holds it, in one shard, as a run
that does not cut its test files judges it. The verdict merges a unit across
the shards that hold it (ADR-0117), here one.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Skip a held unit's invocation in the parts that hold none of its tests | Each part still holds the unit and must give each of its mutants an answer, which only the invocation makes; and the parts that do hold some of its tests each judge it again. |
| Give every held unit to the first part | One shard would carry them all. |

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md)
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0161](0161-a-held-unit-s-coverage-is-measured-once-for-the-whole-run.md)
