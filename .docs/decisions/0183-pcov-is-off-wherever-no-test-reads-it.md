# ADR-0183: pcov is off wherever no test reads it

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0147](0147-a-stream-records-its-own-lines-and-holds-a-child-to-its-parent-s-steps.md): how `pest.pcovOff` turns pcov off, and where it leaves it on

## Context

pcov hooks every op of every process it is enabled in, whether or not
that process collects anything. A mutation run collects no coverage but
what its streams record (ADR-0147), so `pest.pcovOff`, on by default, was
to turn pcov off in every process a run starts. It set `PCOV_ENABLED=0`.
pcov reads no variable of its own, so that did nothing: every process of
every shard ran with pcov on, as the CI's PHP enables it. On the gate's
own `tests/Unit/Core/Config`, a quiet run took 8.7 s and 7.6 s with
`pcov.enabled=0` and 11.2 s and 11.1 s with it on.

`pcov.enabled` can be set only as PHP starts. Pest starts a mutant's own
run by its script, with no option of the command that started Pest, so a
`-d` option reaches no own run; a process inherits its environment, and
PHP reads `PHP_INI_SCAN_DIR` as it starts, as the memory cap already uses
(ADR-0020).

Two things set limits on where pcov may be off:

- Pest's own mutation process refuses to run where no coverage driver is
  enabled: "Mutation testing requires code coverage to be enabled."
- A test that reads the coverage driver passes or fails by whether pcov is
  on: run with pcov off in its stream, its own run and its control, a kill
  by it is left unjudged, which is another verdict.

## Decision

1. **A mutation run's processes turn pcov off by an ini file**: under
   `pest.pcovOff`, the run writes `pcov-off/pcov.ini` (`pcov.enabled=0`)
   in its workspace, and each process it starts scans that directory after
   every directory it scans already (`IniScan::adding`). The run keeps
   what `PHP_INI_SCAN_DIR` held before, and the directory it added, in
   variables of its own.
2. **Pest's own mutation process keeps pcov on**, and is told the
   directory (`MUTATION_GATE_PCOV_OFF`), which every process it starts
   adds: each stream, and so each child forked from it, each own run, and
   each warm worker its own runs fork from. The gate's processes after the
   mutation run, its controls, the survivors' runs again and the runs that
   open issues, add it directly.
3. **A process that runs a test that reads the coverage driver keeps
   pcov as PHP is configured** (`PcovTests`). A test reads it where it
   executes, by the map, or is declared in, a file that starts, stops or
   reads pcov or Xdebug's coverage, picks a driver through
   php-code-coverage, or asks whether pcov or Xdebug is on or loaded; or
   where its own test file starts a run with `--coverage-*` or
   `--path-coverage`. A source file that only names those options does not
   count: a run the gate's own code starts collects as PHP is configured
   (decision 4), and counting the gate's own option names made nearly every
   test of its shard 9 a reader, so every own run left the warm workers and
   the shard's preparation took 872 s where it took 257 s. The streams of a pass put the
   units of such tests in parts of their own, their share of the map's
   time of the pass's processes, at least one and leaving one where there
   are others; those streams keep pcov. A mutant whose covering tests hold
   such a test runs its own run in a process of its own, not a warm
   worker's fork, with pcov kept. The run lists those tests beside its
   results, and a control of any of them keeps pcov too.
4. **Every process the gate starts from a mutation run's processes runs
   PHP as it is configured** (`Withholding`, `IniScan::restored`): a
   coverage run that a test starts, through the gate's own code, collects
   as it does anywhere else.
5. **`Recorded::usable()` reads `pcov.enabled` alone.**
6. **`pest.pcovOff`** (`true`, `false`): `true`, by default, does the
   above; `false` leaves pcov as PHP is configured in every process.

## Why no verdict moves

- A test that reads the coverage driver runs with pcov as PHP is
  configured in every process that runs it: its stream, its children, its
  own run and its control.
- Every other test runs no code that reads the driver, so whether pcov is
  enabled changes nothing it can observe, but its speed.
- Pest's own mutation process runs no test.
- The runner contract test judges a mutant whose only test asks whether
  pcov is on, with `pest.pcovOff` on and off: killed in its stream by that
  test both ways. With the readers left undetected, the same run leaves it
  unjudged.

## Consequences

- Every non-reading test of a shard runs without pcov's hook.
- The streams of a pass are cut in two where some tests read the driver,
  so one more stream may start than there are processes where the pass has
  one process.
- A test that reads the driver through code the map never saw it execute,
  and that its own file does not name, is not found.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **`-d pcov.enabled=0` on each command** | Pest starts own runs by its script, with no option of Pest's own command. |
| **pcov off in Pest's own mutation process too, its check patched away** | One more patch of Pest, for a process that runs no test. |
| **Leave pcov on** | Its hook costs every op of every test of every shard. |
| **pcov off everywhere, readers included** | Moves verdicts: a kill by a test that reads the driver is left unjudged. |
