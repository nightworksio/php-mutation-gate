# ADR-0037: A declared value's lines are covered by the tests that read it, so its mutants run in the mutation step

**Status:** Accepted
**Date:** 2026-10-21

## Context

php-code-coverage marks no line of a class constant or an enum case run, so
Pest leaves every mutant there uncovered. ADR-0004 judges such a
mutant in a trial. The trial runs the test files that read the value, found by
a scan of the codebase, and then a small fallback of the test files that
cover the value's file. Each mutant is its own Pest process, one after
another, over whole test files.

In the gate's own full run on 2026-10-20, about 2,580 kills came from trials,
and 1,908 of them were of a constant's value. Trials took 1.19 of main's
12.28 shard-hours (run 39476018879), and a pull request's chunked run paid
the scan again in every chunk. The scan also looked for each read in every
file of the codebase: 3 to 4 seconds a value, over 3,700 files.

A global constant's line is executable, but the only test coverage marks on
it is the one that loaded the file, which need not read the value.

## Decision

1. **Before a patched shard's run, the map Pest opens on covers each declared
   value's lines with the tests that read it.** The values are each constant
   and backed case of a named class-like and each global constant a `const`
   statement or a `define()` with its name written out declares, in the
   files the run mutates. The lines run from the value's name to the end of
   its value.
2. **The tests are those of the test files one run judges the mutant by**:
   the test files that read the value, found by the scan, with the
   fallback too where it holds at most 10 files. A kill by either stands in a
   trial, and a survivor of the first runs the fallback, so one run of both
   reaches the verdict the trial would. Each is timed as the plan's whole map
   timed it. The map knows every test the coverage run finished, a test
   that ran no measured line included, as one that only reads the value
   does (ADR-0004), so no test of those files is left out of the run.
3. **A value the scan cannot fully follow keeps its trial.** Where a read is
   ambiguous, or no read is found, its lines are left as they were, and
   ADR-0004 judges its mutants by reference as before. A property's default, a
   parameter's default and an attribute's argument keep their trials too.
4. **Global constants are read by name.** A name that stands as a value is a
   read where the file may resolve it to the constant: through its
   namespace, its imports and the global namespace PHP falls back to. A
   `constant()` call with the name written out is a read, and one without it
   cannot be followed.
5. **The scan looks for a read only in a file that spells a word the read
   must spell**: a constant's name, `constant` or a reflection class, a global
   constant's name or `constant`, or an enum case's enum.
6. **One scan serves the run and its trials.** The map Pest opens on is
   written for each set of files a run mutates.

This amends ADR-0004 for a patched shard that opens on a map the
plan handed over. A run that measures its own coverage keeps ADR-0004's judging by
reference as it stands.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Correct only a statement's first line and a `match` head** | They held about 640 of the 2,580 trial kills, about 0.35 shard-hours. |
| **The readers alone, without the fallback** | A survivor of the readers would then never meet the fallback, and the verdict could differ from the trial's. |
| **Move ambiguous values too, with the fallback** | Past the bound the trial calls such a mutant unjudged; a mutation run can only kill it or let it survive. |

## Consequences

- A constant's mutants run in the mutation step, in parallel, in each
  mutant's own narrowed process, and their kills are controlled and confirmed
  as any narrowed kill is (ADR-0036).
- A global constant in a file loaded before Pest's override, as Composer's
  `files` autoload loads it, stays unjudged, as any mutant of such a file is.

## Related

- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): trials
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the shard's own map and the plan's whole map
- [ADR-0036](0036-a-kill-only-errors-made-counts-where-its-control-passes.md): narrowed kills
