# ADR-0307: A part makes a mutant by the lines it records

**Status:** Accepted
**Date:** 2026-11-21
**Amends:** [0193](0193-a-part-makes-the-mutants-its-tests-can-reach.md): §1, which lines decide that a part makes a mutant

## Context

A part of a cut run makes the mutants that span a line it made, and the
verdict expects of each part exactly the mutants whose lines touch a line
it made (ADR-0193). Pest decided the two by different lines:

- It records a mutant of part of a method's parameter, such as the
  default `false` of a promoted constructor parameter, as spanning from
  that node's first line to the method's last. It selects the mutant's
  covering tests by those lines, and the verdict reads them from the
  record.
- Its line filter, which a part's run makes its mutants through, took
  the node's own lines alone.

A part whose tests cover a line of the method body but not the
parameter's line therefore never made the mutant, though the verdict
expected it there and an unsplit run selects that part's tests as
covering it. On run 44861579327 the verdict's merge refused 198 of 3,036
units for it: `src/Core/Config/JobStop.php`'s mutant `e2e8b4205dae`,
recorded on lines 29 to 31, was held only by shard 22, which made lines 1
to 35, and by no shard that made line 31 alone.

## Decision

1. **A mutant's lines have one rule.** `MutantSpan` gives the lines a
   mutant of a node spans: the node's first line to its last, or to its
   method's last where the node is part of a method's parameter. Pest
   records a mutant's last line by it, and its line filter makes a node's
   mutant where a line it gives is one to mutate.
2. **A part makes exactly the mutants the verdict expects of it**: those
   whose recorded lines touch a line it made. The covering tests Pest
   selects for a mutant are those of the same lines, so every part holding
   a covering test of a mutant makes it.

## Why no verdict moves

- **An unsplit run makes the same mutants with the same ids.** The gate
  runs Pest with `--covered-only=false`, so a run that is not cut mutates
  every line and the line filter is not asked. A mutant's recorded lines
  are Pest's as before, so its id, its covering tests and its record do
  not change.
- **A cut run's verdict comes to equal the unsplit run's.** A mutant is now
  made in every part that holds a test of it, so each of its covering tests
  runs against it in its part, as the unsplit run runs them all; the part
  that owns it (ADR-0174) makes it. Before, a part holding only tests of
  the method body never ran them against it, and the merge refused the
  unit.

## Alternatives considered

- **The verdict expects a mutant by the node's own lines**: needs the
  node's lines in each record, and still leaves the tests of a part that
  covers only the method body unrun against the mutant, so a kill the
  unsplit run finds could be missed.
- **Each mutant made by one part alone, by its first line or the plan's
  table**: a part judges a mutant by the covering tests it holds
  (ADR-0117), so the parts holding its other covering tests would never
  run them against it.
- **A part makes every mutant of each file it touches, and keeps those its
  lines touch**: correct, but makes the whole of every file in every part,
  about 2.75 ms a mutant, where the filter makes the few extra mutants of
  a method's parameters alone.

## Consequences

- A mutant of part of a method's parameter is made in every part that
  made a line of the method.
- The patch changes Pest's `Support/NodeVisitor.php` as well.

## Related

- [ADR-0306](0306-a-mutants-place-is-counted-over-its-whole-file.md): a mutant's place is counted over its whole file.
