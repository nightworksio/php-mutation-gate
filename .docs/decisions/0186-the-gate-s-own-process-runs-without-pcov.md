# ADR-0186: The gate's own process runs without pcov

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0183](0183-pcov-is-off-wherever-no-test-reads-it.md): which processes run with pcov

## Context

The action sets PHP up with pcov as its coverage driver, so `pcov.enabled`
is 1 in every PHP process of a job, the gate's own included. pcov hooks
every call PHP makes once it is enabled, whether or not anything collects.
Under `pest.pcovOff` the processes a mutation run starts run without it
(ADR-0183), but the gate's own process, which plans, reads the map, makes
the shards' briefs and judges between its runs, kept it.

A bounded sample of the gate's own process on the gate's shard 9, in the
minute it works alone between the held unit's run and the controls,
found pcov's call hook on top of the stack in about one in twelve of the
samples that were not waiting on a file, on top of the slower calls it
forces on everything else. The gate's own process collects no coverage:
every run that does is a process it starts.

## Decision

1. **The action starts the gate's own PHP with `-d pcov.enabled=0`** for
   `plan` and for each shard's `run`, and so does the reusable workflow's
   plan job. A `-d` on the gate's command line reaches no process it
   starts: each starts as PHP is configured, so coverage runs and Pest's
   own mutation process keep pcov, and `pest.pcovOff` decides for the
   rest as before.
2. **`gate-pcov`** (`off`, `on`), an input of the action and the reusable
   workflow: `off`, the default, does the above; `on` keeps pcov in the
   gate's own process as PHP is configured.

## Why no verdict moves

No test runs in the gate's own process, and nothing there reads the
coverage driver: the platform a proof is keyed by is that of the PHP the
runner starts, which is unchanged. Every process the gate starts sees the
same settings as before.

## Consequences

- The plan's and each shard's work in the gate's own process run at PHP's
  speed without pcov's hook.
- A project's own CI that starts the gate itself keeps pcov there unless
  it starts the gate the same way.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **The gate starts itself again without pcov** | PHP cannot say which `-d` it was started with, so the gate's own settings, such as its memory limit, would be lost. |
| **Turn pcov off in the setup step** | Every process of the job would then lack it, the coverage runs too. |
