# ADR-0158: The gate holds every process it starts in a session and a cgroup

**Status:** Accepted
**Date:** 2026-11-15

## Context

The gate stops a process at its deadline with every process under it
(`src/Core/Runner/ProcessTable.php`): it freezes the process, walks `ps`'s
parent links to freeze each process found under it, listing by listing, and
kills them all. A process whose parent ended before it is handed to the
system's first process, so no parent link leads to it, and the walk misses
it. A process that ended on its own leaves its children running unasked.

Under split streams (ADR-0117) the runner forks a child per mutant and
waits for each. A mutant that keeps the runner from waiting, such as one
that negates `Children::reapAll()`, ends the runner first and leaves its
children running, each in a test that may never end. Across a shard such
orphans pile up until the machine runs out of memory or processes, and the
hosted runner stops answering: shard 5 of the gate's own CI was lost at
about 49 minutes in runs 43712641293 and 43755388963 (ADR-0157).

A process leading a session of its own gives its session's id to every
process it starts that does not leave it, however deep and whoever its
parent is now; `kill -- -<id>` signals all of them. On Linux, a cgroup holds
every process started in it, whatever leaves its session, caps their memory,
swap and count, and `cgroup.kill` kills them all at once. GitHub's hosted
Linux runners run systemd on cgroup v2, and their `sudo` needs no password.

## Decision

1. **`processes.group` starts every process the gate starts as the leader of
   a session of its own:** `auto`, the default, `off` or `require`.
   - `auto` starts it under `setsid` where the system has it, else under
     the gate's PHP with no ini of its own, which leaves the session and
     replaces itself with the command where it can (`posix_setsid`,
     `pcntl_exec`), else as before.
   - `require` refuses to run where neither is there; `off` starts it as
     before.
   - A process stopped at its deadline, or for its silence or a test's
     limit, has its whole session frozen before the walk and killed after
     it. A process that ended on its own has whatever is left of its session
     killed.
   - The processes a mutant's test starts, and those its served children
     start (ADR-0150), stay in the session: nothing the gate starts for a
     mutant leaves it. The daemon of `mutation-gate serve` leaves its
     starter's session on purpose, and is not started through it
     (ADR-0078).
   - It changes no result, only what is left running, so a change to it
     re-runs nothing.
2. **`processes.contain` runs every process the gate starts in a cgroup of
   its own, on Linux:** `auto`, the default, `off` or `require`.
   - Where the cgroup the gate runs in sits in one the gate's user may
     write, with the `memory` and `pids` controllers handed down, the gate
     makes `mutation-gate-<pid>` beside it, capped at `processes.memory`
     of the machine's memory with `memory.swap.max` 0, and at
     `processes.pids` processes. Each process it starts joins
     `run-<random>` under that one before it becomes its command, with
     `memory.oom.group` set, so an out-of-memory kill takes the whole run.
     The gate kills that cgroup whole with `cgroup.kill` once the process
     ends or is stopped, and removes it; it removes its own when it exits.
   - **In CI, where none is handed to it, the gate hands itself one** with
     `sudo -n`: it makes `/sys/fs/cgroup/mutation-gate`, gives it to its own
     user, hands the two controllers down and moves its own process into
     `gate-<pid>` in it. Outside CI it never runs `sudo`.
   - `auto` runs uncontained where no cgroup can be had: off Linux, on
     cgroup v1, without a cgroup to write and outside CI, or on a kernel
     without `cgroup.kill`. `require` refuses to run there, saying why;
     `off` never contains.
   - It is a backstop beside the session: a process that leaves its session
     stays in its cgroup, and a run that takes the machine's memory or
     processes is stopped before the runner that hosts the job is.
3. **`processes.memory`, a whole number from 1 to 100, 85 by default,** is
   the share of the machine's memory, in percent, that every process the
   gate starts may hold together, with no swap. The rest is the runner's,
   the gate's own and the system's. **`processes.pids`, 8192 by default,**
   is how many processes they may be together.
4. **A contained run that goes past a cap ends as one killed by a signal.**
   Over its memory the kernel kills the run's cgroup whole; at its process
   count a fork fails. Either way the run ends as it would where PHP's
   `memory_limit` ended it, or a fork failed, without a cap on the
   machine. A change to `processes.contain`, `.memory` or `.pids` decides
   how the gate runs, so no proof is carried across it (ADR-0005), but
   none is in a proof's key: `auto` holds where the machine allows, which
   the key could not name.
5. **The CI templates need nothing of their own:** the GitHub action and the
   reusable workflow run the gate on a hosted runner, which hands itself its
   cgroup; a template of another CI does the same wherever its runner has
   systemd on cgroup v2 and `sudo` without a password, and runs
   uncontained elsewhere.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Find orphans by walking `ps` for processes of the system's first process | Every daemon of the machine is one; nothing tells the gate's apart but its session. |
| `systemd-run --scope` per process | It needs a user manager or `sudo` for every process the gate starts, and passes no environment through `sudo`. |
| Contain only on GitHub Actions, from the action | Every CI with systemd and passwordless `sudo` gains the same, and a step of the action cannot move a later step's process. |
| One cgroup for all the gate's processes, without one per process | A run's cgroup is what an out-of-memory kill takes whole and what `cgroup.kill` empties once it ends. |
| A cap per process instead of one shared | A pool of runs, each under a cap below the machine's memory, can still take it all together. |
| Contain by default only with `require` | A machine that cannot contain would refuse every run; `auto` keeps them running and contains wherever it can. |

## Consequences

- A runner that ends before its children leaves nothing running: its
  session, and on Linux its cgroup, are killed once it ends.
- A hosted runner keeps 15% of its memory however the mutants behave, so a
  runaway mutant fails its run instead of losing the job.
- A process the gate starts on macOS without `setsid` is started through a
  second PHP first, which adds a few milliseconds to each start.
- The gate's CI shards run `sudo -n` four times at start-up.

## Related

- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): the children a split stream forks
- [ADR-0157](0157-a-ci-shard-leaves-its-progress-as-it-runs.md): what a lost shard leaves to read
