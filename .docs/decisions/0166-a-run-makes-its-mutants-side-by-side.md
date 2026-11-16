# ADR-0166: A run makes its mutants side by side

**Status:** Accepted
**Date:** 2026-11-16
**Amends:** [0091](0091-a-files-mutants-are-compiled-into-one-schema.md): where a file's mutants and its schema are made

## Context

A patched Pest run makes every mutant of every file it mutates before it
runs any, one file after another, in one process (ADR-0091). Where a
shard's mutants are all of the project's units, as under `splitStream`'s
sharding by tests, a run makes every mutant of the project: 55,747 on the
gate's own code, which took 549 s in one process on the probe machine,
while every other core waited.

## Decision

1. **`pest.makeSideBySide` `true`, the default, makes every file's mutants
   side by side before the run takes any.** The files are cut by size into
   a share for each core, each share made by a child forked from the run.
   A child leaves each mutant it made, and the schema of each of its files
   and the switch of each mutant it switches, in a file of its own, and
   ends by a signal, so nothing the run set to happen at its end runs in
   it. Bytes that are not UTF-8 are kept.
2. **The run takes each file's mutants as a child made them,** in the
   order Pest makes them, and the schemata as the child wrote them.
3. **A file no child made is made by the run, as before:** where the run
   cannot fork, the machine has one core, or a child failed.
4. **`pest.makeSideBySide: false` makes one file's mutants after
   another's,** in the run itself. It changes no output, and keys nothing.

## Why no verdict moves

Each file's mutants are made by the same code from the same file, in a
process that is a copy of the run, and handed back field by field. On the
gate's own 2,717 files, the 55,747 mutants made side by side are
byte-identical to those made in one process: id, mutator, lines, diff,
copy, schema and switch.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep making them in one process (`pest.makeSideBySide: false`) | Every core but one waits through the longest step before the first mutant runs. Kept as the setting. |
| Make every mutant once, as the plan is made, and hand the copies to every shard | The plan job sits before every shard, and the copies run to hundreds of megabytes; making them side by side in the run costs a share of that time and hands nothing on. |
| Hand the mutants back serialized | Pest's file object refuses serialization; the fields are scalars and hand back whole. |

## Consequences

- A run uses every core while it makes its mutants, and its memory grows
  by what each child writes to its copy of the run.

## Related

- [ADR-0091](0091-a-files-mutants-are-compiled-into-one-schema.md)
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
