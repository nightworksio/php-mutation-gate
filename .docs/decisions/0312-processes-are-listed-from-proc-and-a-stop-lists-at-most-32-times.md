# ADR-0312: Processes are listed from /proc, and a stop lists at most 32 times

**Status:** Accepted
**Date:** 2026-11-20

## Context

The gate stops a process with every process under it (`ProcessTable`,
ADR-0158): it freezes the process, then lists every process and freezes
each one it finds under it, listing by listing, until a listing finds none
it has not frozen, and then kills them all. It tells the processes it spares
from a listing too (ADR-0310), and a CI shard's progress reports the
largest processes from one (ADR-0157).

Each listing ran `ps` through a shell: one fork and two programs started
for every listing, in the gate, in each stream and in each warm worker.
The loop that lists had no bound. A mutant of the gate's own stopping code
that keeps the loop from ending, judged by a test that stops a process tree,
lists without pause until its test's deadline: the trace of shard 77 of run
44613843075 replayed (probe run 44812811852) showed about 190 `sh -c ps -A`
a second at its peak, from a few processes each starting about 470.
Shards that hold `tests/Process/Adapter/Native/ProcessShellTest.php`, which
stops many trees, lost their whole runner 2.5 to 4.5 minutes into their
second pass on ARM and x64 alike, with memory, signals, cgroups, disk and
network ruled out. A storm of process starts is the likeliest cause left;
it is not proven.

On Linux, `/proc` holds each process's parent, group, memory, start and
name in one `stat` file, which PHP reads without starting a process.

## Decision

1. **One listing, one port, one implementation.** `ProcessListing` (core)
   holds every process with its parent, group, memory, age and name. The
   port `RunningProcesses` answers it; `Process\LiveProcesses` is its one
   implementation and the one place the gate reads `/proc` or runs `ps`. On
   Linux it reads `/proc/<pid>/stat` for each process `/proc` names, the
   name taken up to the last `)`, since a name may hold parentheses and
   spaces itself; on a system without `/proc`, such as macOS, or where PHP
   cannot ask the clock ticks and page size (`posix_sysconf`), it runs `ps`
   once. A process that ends between the read of `/proc` and the read of its
   `stat` file is left out. `ProcessTable`, `SparedProcesses`, the warm
   workers and their clients, the split streams and a shard's progress take
   the port from their composition root: the CLI for the gate's own process
   and, through the Pest plugin it registers, for each split stream; the bin
   scripts for the warm workers and their clients; and the hunk `Pest\Patch`
   writes into Pest's runner, which watches the streams. That last root is an
   adapter, so A3 lets `Patch` name `LiveProcesses`, and only that, by an
   exact list the architecture test holds. A split stream lists only once one
   of its children is past its limit, not at each look its reaping loop
   takes.
2. **A stop under `processes.contain` lists nothing.** A process held in a
   cgroup of its own (ADR-0158) is stopped by killing every process its
   `cgroup.procs` holds, however deep or whoever's child it became, the
   process last, then its session, and then the cgroup whole and removed,
   as before.
3. **A stop lists at most `ProcessTable::LISTINGS` (32) times.** Each
   listing freezes every process it finds, and a frozen process starts no
   other, so a tree that stands still is whole after its depth plus one
   listings: a stop of a runner's tree (the gate, a worker, a test, a shell
   and a program) takes at most six. 32 leaves more than five times that
   for processes started between two listings, and at the most a stop costs
   32 listings, about 50 ms on a machine with 300 processes. A stop that
   reaches the bound kills every process it found and the session it leads,
   and the cgroup kill follows where the process has one.

## Consequences

- A stop whose listings converge signals the same processes in the same
  order as before; only the listing it reads them from changed. Its
  verdicts are unchanged.
- A mutant that keeps the stop from converging now ends after 32 listings,
  and its test goes on to what it asserts, instead of listing until the
  test's deadline. Such a mutant was a timeout before; now it is killed
  where its test's assertion fails, and survives where its test passes,
  which names a test that does not assert the stop's bound. Every other
  status is the same.
- On Linux a listing starts no process. In a Linux container with 300
  processes, a listing takes 1.6 ms from `/proc` against 4.9 ms through
  `ps` (627 against 203 a second), and a stop of a tree three deep 17.5 ms
  against 28.1 ms (median of 20). What a stop still starts is its `kill`
  commands.
- A process started after a stop's 32nd listing, outside a session the
  stopped process leads and outside a cgroup, is left running, as one whose
  parent ended before the stop always was.

## Related

- [ADR-0157](0157-a-ci-shard-leaves-its-progress-as-it-runs.md): a shard's progress, which reports the largest processes
- [ADR-0158](0158-the-gate-holds-every-process-it-starts-in-a-session-and-a-cgroup.md): the stop, the session and the cgroup
- [ADR-0310](0310-the-gate-never-signals-itself-its-ancestors-or-their-groups.md): the processes a stop spares
