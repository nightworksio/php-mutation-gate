# ADR-0306: A mutant's place is counted over its whole file

**Status:** Accepted
**Date:** 2026-11-21
**Amends:** [0004](0004-pest-and-infection-behind-one-runner-port.md): §2, the mutant's position an id holds

## Context

A mutant's gate id holds its position among the mutants of its file that
share its path, mutator and collapsed diff (ADR-0004 §2). Pest numbered
that position over the mutants its run made. A part of a cut run makes
only the lines its tests cover and its share of the lines no test covers
(ADR-0193), and a share of the second wave makes only the mutants it is
told of. Where one statement stands on two lines and a part makes the
second line alone, that part numbers it first, as a run that makes both
numbers the first line. The parts of one plan then gave one mutant two
ids, or two mutants one id, and the verdict, which merges the parts by
id, could not judge the file.

Infection and the gate's own engine number every change of a file
whichever they run, so only Pest's numbering depends on the lines a run
makes.

## Decision

1. **A mutant's position is counted among every mutant the runner makes
   of its whole file**, in the order it makes them, whatever lines a part
   of a cut run makes and whichever mutants a run is told to make. A run
   that makes some of a file's mutants numbers each as a run that makes
   them all does, so the parts and shares of one plan name each mutant
   alike, and the verdict merges them.
2. **The plan tables every position once.** Under Pest, the plan makes
   every mutant of each file it runs, whole, in one patched run that
   judges none, and keeps each one's position in a table beside the plan
   (`change-places.json`). The table holds each file by the SHA-256 of its
   code and each mutant by its mutator, the name of its mutated copy,
   which Pest takes from the copy's code, and its lines.
3. **A part places its mutants by the table** where the table holds the
   file's code as the part reads it, was made by the same
   pest-plugin-mutate and php-parser, and holds every mutant the part
   made. Otherwise the part makes the whole file itself, with no schema
   touched, and its result warns which files it made whole. Both give the
   same position.
4. **The plan's run loads the gate's bridges** to the registered mutators
   a config turns on, as a mutation run does, so the table holds every
   mutant a part makes.

## Why no verdict moves

A run that makes a whole file reads no table and numbers its mutants as
before, so every id of an unsplit run is unchanged. A part or a share now
gives each mutant the id the unsplit run gives it. Before, the merge of
parts whose ids differed refused the file, and the verdict failed closed;
it never took a wrong status.

## Alternatives considered

- **Each part makes every file it touches whole:** the same ids, but every
  part pays for making every mutant of its files a second time.
- **Embed the table in `plan.json`:** every job decodes the plan, and the
  table is needed only by a part's Pest process.
- **Number by line:** an id would no longer survive code above it moving.

## Consequences

- The plan job runs Pest once more, making every mutant of the files it
  plans.
- A part reads the table in its Pest process; a table another install made
  is not read.

## Related

- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the record and its id
- [ADR-0193](0193-a-part-makes-the-mutants-its-tests-can-reach.md): the lines a part makes
