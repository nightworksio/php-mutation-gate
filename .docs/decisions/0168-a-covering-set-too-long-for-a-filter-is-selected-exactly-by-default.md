# ADR-0168: A covering set too long for a filter is selected exactly by default

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0138](0138-a-covering-set-too-long-for-a-filter-is-selected-exactly.md): `pest.longFilter`'s default, its proof done

## Context

ADR-0138 added `pest.longFilter: set`, which selects a mutant's covering
tests by their ids in a file where their filter is too long to pass, and
controls the kill by its killers. It kept `files` as the default until two
proofs were done: a property test that the set selects exactly what the
filter would, and an A/B of the gate's own run comparing each mutant's
status and killers.

The property test is in `tests/Unit/Adapter/Pest/Selection/SetAgainstFilterTest.php`.
Run 43934027017 of the gate's own code, under `files`, still spent 1,075 s
of one shard's 2,177 s, 679 s of another's 1,217 s and 611 s of a third's
2,171 s in controls, one control running for three to six minutes while
every other had ended, as ADR-0138 measured on earlier runs.

On one shard of the gate's own code cut to four files (`Path`, `LineTests`,
`Gzip`, `Fit`), the same plan and the same machine:

| `pest.longFilter` | Shard | Controls | Longest control |
|---|---:|---:|---:|
| `files` | past 1,500 s, cut off | one ran 185 whole test files for minutes | minutes |
| `set` | 1,331 s | 6, all passed, 14 s in all | 7.1 s |

Under `files` the control of one kill ran every test of 185 test files the
mutant's run had loaded, with `--bail`, for minutes; under `set` each kill's
control ran its killers, 10 or 23 tests in a few seconds.

## Decision

1. **`pest.longFilter` is `set` by default.** Where the PHPUnit Pest runs
   reads no `--test-id-filter-file`, a run is as under `files`, as before.
2. **`files` and `until-killer` stay settings,** for a suite whose map
   misses tests that kill.

## Why verdicts stay sound

As ADR-0138 sets out: under `set` a run executes exactly its covering
tests and its kills are controlled by their killers, as every filtered
run's are. A verdict can move only where the map misses a test that
kills; such a mutant survives and fails the run, never passes it.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep `files` | A control re-runs every test of every loaded file, minutes for a kill whose killers take a second. |
| `until-killer` by default | Its control stops at the last killer, but the mutant's own run still executes every loaded test before it, and a survivor every loaded test. |

## Consequences

- A kill in a hot file costs a control of its killers, and a survivor's run
  its covering tests, in place of every test of the files they load.

## Related

- [ADR-0138](0138-a-covering-set-too-long-for-a-filter-is-selected-exactly.md)
- [ADR-0167](0167-a-shard-says-where-each-controls-time-went.md)
