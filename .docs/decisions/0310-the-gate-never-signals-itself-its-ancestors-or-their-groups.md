# ADR-0310: The gate never signals itself, its ancestors or their groups

**Status:** Accepted
**Date:** 2026-11-20

## Context

The gate stops processes by id: `ProcessTable` freezes a process and every
process a `ps` listing finds under it, kills them, and kills the group of a
process that led its session (ADR-0158). The gate also signals single
processes it forked: the progress reporter (ADR-0157), the plan's warm-up,
a split stream's late children (ADR-0117) and the serve daemon's child.

Each of these takes its target from data: a listing, a fork's result, a
stored id. A fault in that data, or a mutant of the code that reads it when
the gate's own tests judge the gate, can name the wrong process. Before
this decision only the ids under 2 were refused: 1 (the system's first
process), 0 (the caller's own group) and -1 (every process the caller may
signal).

On GitHub's hosted runners the job's processes share the runner agent's
process group and session: in run 44770323518 the gate (pid 5818), its step
shell and the job's other steps were in pgid and sid 2006, the group of
`hosted-compute-agent`, which also holds `provjobd`, `Runner.Listener` and
`Runner.Worker`. A group kill of the gate's own group, or a stop of any of
the gate's ancestors, takes the runner down: GitHub then waits about 45
minutes and reports that it lost communication with the server. That is how
shard 77 of run 44613843075 and shard 75 of run 44574323996 ended, and a
replay of shard 77 lost its runner the same way 3.5 minutes into the gate,
with 9 GB of memory available and no out-of-memory kill.

## Decision

1. **`SparedProcesses` names what one process never signals:** every id
   under 2, the process itself, each process above it (its parent, its
   parent's parent, up to the system's first process), and as groups those
   ids, its own process group and its own session. It is read from one
   `ps -A -o pid= -o ppid= -opgid=` listing and `posix_getsid()` where PHP
   has it.
2. **Every signal the gate sends goes through it.** `ProcessTable` leaves a
   spared id out of every freeze and kill, stops nothing where the process
   it is asked to stop is spared, and kills no spared group.
   `SparedProcesses` itself is the one place the gate calls `posix_kill` on
   another process or group: the progress reporter's end, the warm-up's
   drop, a stream's late children and the serve daemon's children.
3. **The gate's own process lists once.** Once its settings are read it
   tells itself what it spares (`MUTATION_GATE_SPARED`), so letting go of
   each process it started costs no listing. Any other process, such as a
   stream or a test's process, lists at each stop. The variable is withheld
   from every process the gate starts, as every `MUTATION_GATE_*` is, and a
   value told to another process is never taken.
4. **A warm worker's wake is not guarded.** It sends `SIGURG`, whose
   default action is to ignore it, so a wake that reaches the wrong process
   changes nothing.
5. **The gate does not leave the runner's group itself.** A gate that led a
   session of its own would no longer get the `SIGINT` a terminal sends its
   foreground group, so Ctrl-C would stop reaching a local run, and a CI
   runner that cancels a step by its group would leave the gate running.
   Refusing the group is enough: no signal of the gate's reaches it.

## Consequences

- Unmutated, the gate signals exactly what it signalled before: no process
  it starts is ever its own ancestor, nor is its group or session one it
  started.
- A mutant of the gate's own signalling code that names an ancestor, the
  gate's group or session, 1, 0 or -1 now leaves the target alone. The test
  that needed the signal fails, so the mutant is killed; before, it could
  stop the runner and leave its shard without a verdict.
- Stopping a process costs one more `ps` listing in a process other than the
  gate's own.
- A process beside the caller that is not its ancestor, such as another
  stream, is not spared: a signal sent to it by a fault still reaches it.
  Containing those signals is a separate decision.

## Related

- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): the children a split stream forks
- [ADR-0157](0157-a-ci-shard-leaves-its-progress-as-it-runs.md): what a lost shard leaves to read
- [ADR-0158](0158-the-gate-holds-every-process-it-starts-in-a-session-and-a-cgroup.md): the session and cgroup every process the gate starts is held in
