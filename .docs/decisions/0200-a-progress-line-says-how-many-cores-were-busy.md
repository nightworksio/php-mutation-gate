# ADR-0200: A progress line says how many cores were busy

**Status:** Accepted
**Date:** 2026-11-19

## Context

The line the gate says once a minute while it waits on processes names
how many of them run and what the machine has left (ADR-0157). The count
is of the gate's own processes, not of the work: a Pest run with split
streams is one process that forks a child for each mutant on its share
of the cores (ADR-0173), and a control's run starts its own. A log that
says `1 running` cannot tell a run that keeps every core busy from one
that leaves three of four idle, and the cost of a shard can only be cut
where it shows which.

## Decision

1. **Each line says how many cores were busy, on average, since the line
   before**, or since the gate first waited, after what the machine has
   left: `cores 3.2 of 4 busy`.
2. **The count is the machine's**, as Linux's `/proc/stat` counts it: every
   tick but those idle or waiting on input and output, across every core
   it names, so a forked child is counted as its parent is.
3. **A system that does not say it adds nothing to the line.**

## Alternatives considered

- **The load average**: it counts processes waiting for a core as well
  as those on one, so a machine with more work than cores and one with
  every core busy read alike.
- **The CPU time of the gate's own child processes**: the system gives it
  only once a child has ended, so a long run says nothing until its end,
  and a split stream's forked children are counted only through it.

## Consequences

- A probe's log shows, minute by minute, where a shard leaves cores idle.
- The line reads one more small file once a minute.

## Related

- [ADR-0157](0157-a-ci-shard-leaves-its-progress-as-it-runs.md)
- [ADR-0173](0173-a-stream-forks-on-its-share-of-the-cores.md)
- [ADR-0178](0178-a-shards-progress-names-what-it-does-now.md)
