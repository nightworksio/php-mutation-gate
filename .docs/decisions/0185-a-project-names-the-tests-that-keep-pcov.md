# ADR-0185: A project names the tests that keep pcov

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0183](0183-pcov-is-off-wherever-no-test-reads-it.md): which tests keep pcov as PHP is configured

## Context

Under `pest.pcovOff`, every process a mutation run starts has pcov off,
except those that run a test reading the coverage driver (ADR-0183). The
gate finds those tests from their code: a test that executes, or is
declared in, a file that starts, stops or reads pcov or Xdebug's coverage,
picks a driver, asks whether pcov or Xdebug is on, or, in a test file,
starts a run that collects coverage.

A test can read the driver in a way that code does not show: through a
library the map records as no file of the project, through a string
built at runtime, or through a process it starts that the map cannot
follow. Such a test runs with pcov off in its stream, its children and its
mutants' own runs, and may pass or fail otherwise than it does in the
project's own suite, which can move a verdict.

## Decision

1. **`pest.pcovKept`** (a list of globs, none by default) names test files
   and source files, spelt from the project's root as every other glob is.
   A test declared in a file it names, or that executes one, keeps pcov as
   PHP is configured, as a test the gate finds reading the driver does:
   its streams are the readers' own, its own runs start cold with pcov
   kept, and the controls of its mutants keep it too (ADR-0183).
2. Every layer's globs count together, as a later layer can only name
   more tests that keep pcov, never fewer.
3. Pest's own mutation process is told the globs one to a line in
   `MUTATION_GATE_PCOV_KEPT_GLOBS` where it turns pcov off; nothing where
   pcov stays on, as then every test keeps it.
4. A list set enters the proof key, as it can move a verdict; the default,
   listing none, adds nothing to it.

## Why no verdict moves

A test the globs name runs with pcov as the project's own suite runs it,
in every process of the run; every other test runs as ADR-0183 sets out.
Naming a file that no test reads the driver from only keeps pcov on for
more tests, which is slower and judges alike.

## Consequences

- A project whose tests read the driver in a way the gate cannot see
  names them, and keeps the speed of `pest.pcovOff` for every other test.
- A glob that names too much costs speed, never a verdict.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Turn `pest.pcovOff` off for such a project** | Every test then pays pcov's cost, where a few need it. Kept as a setting. |
| **A list of test ids** | Ids change with every renamed test and data set; files and globs are how a project already names its tests and sources. |
| **Find every reader at runtime** | A test reads the driver before it can be told it is off; only a process started with pcov on runs it as the suite does. |
