# ADR-0262: A process asks once how it leads a session

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0158](0158-the-gate-holds-every-process-it-starts-in-a-session-and-a-cgroup.md): how the leader is found

## Context

ADR-0158 starts every process the gate starts in a session of its own, under
`setsid` where the system has it, else under this PHP with no ini where it
can leave a session and replace itself. Each time the gate's settings are
read, it looks for `setsid` on the `PATH` and, where there is none, starts a
`php -n` child to ask whether that PHP can lead.

macOS has no `setsid`, so on a Mac every composition starts that child:
60 ms each time. In this package's own suite every test of a command pays
it, 67 ms of each composition in `VerdictCommandTest`, and `watch` pays it
every round.

## Decision

1. **A process asks the system once** where `setsid` is and whether this PHP
   can leave a session, and keeps both answers. Neither changes while the gate
   runs: the PHP binary is the one running, and a program installed meanwhile
   is no reason to hold processes differently mid-run.
2. **`processes.group` is still read each time.** The leader each setting
   chooses, and the refusal under `require`, follow from the kept answers as
   before.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Turning `processes.group` off in tests** | Only the tests get faster, and the tests no longer hold processes as a user's run does. |
| **Asking once per composition, as before** | 60 ms per command and per `watch` round on every Mac, for answers that do not change. |
