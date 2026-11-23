# ADR-0331: A shard's run is kept in a process namespace of its own

**Status:** Accepted
**Date:** 2026-11-23

## Context

A mutant can change what a test signals. A project whose tests start,
stop and signal real processes, the gate's own among them, can under one
mutant send a signal to a process group, or to every process its user
may signal. That ends the mutation run, and it can end the runner's own
agent: the job then reports nothing until its timeout, and holds a place
in the pool for hours.

The gate spares its own lineage where it signals (ADR-0158). No guard in
the code under test holds once that code is mutated.

Linux gives a process namespace two properties: a process in it can
signal no process outside it, and its first process cannot be ended by a
signal sent from inside it.

## Decision

1. **The action runs a shard's `run` in a process namespace of its own**,
   as the user the job runs as, with the gate as the namespace's first
   process: `unshare --pid --fork --mount-proc --kill-child`, entered
   through `sudo` and left at once by `setpriv` to the job's user and
   groups. The environment, `PATH` and `HOME` are the job's.
2. **`contain` chooses.** `auto`, the default, does so where the runner
   is Linux, has `unshare` and `setpriv`, and lets the job use `sudo`
   without a password; elsewhere it says the run is not contained and
   runs as before. `on` fails the job where it cannot. `off` never does.
3. **A run a signal ends is a crashed run** (ADR-0330): the gate, the
   namespace's first process, lives to write what the run left.

## Consequences

- A mutant's test cannot end the runner, the services the job started,
  or another job's processes on a shared machine.
- The run sees only its own processes in `/proc`.
- Tests no longer see the job's other processes; a test that reads the
  process of its job's runner finds none.
- macOS and Windows runners, and Linux runners without `sudo`, run as
  before, and the action says so.
