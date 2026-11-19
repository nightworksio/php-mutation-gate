# ADR-0173: A stream forks on its share of the cores

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): decision 2's default of one child at once

## Context

A split stream holds at most `splitStream.children` children at once, 1
by default, and waits for one to end before it forks past that (ADR-0117).
A shard starts one stream for each of its processes, its part of the test
files cut among them by the map's times; how much forking each stream does
follows the mutants its tests reach, which the times do not weigh.

In run 44092741710 a shard of the gate's own plan on a 4-core runner
spent over 9 minutes in its streams. Twelve minutes into the job, 2 of its
streams were still running, each with one child, while the other cores had
no work.

## Decision

1. **`splitStream.children` takes `cores`, the default:** a stream holds at
   most the machine's logical cores divided by the streams of its run still
   running, at least one. Each stream marks itself running in the run's
   stream directory from its start to its end, and reads the marks again
   each time it forks, so a stream left running alone forks on every core.
2. **A whole count holds as before:** at most that many children at once,
   whatever else runs.

## Why no verdict moves

A child is forked at the same site of the same test with the same mutant,
whatever the number of its siblings, and is judged by its parent's own end
of that test as before (ADR-0117). The children running at once stay within
the cores: with every stream running, each holds one, as the old default
did.

## Alternatives considered

| Alternative | Why not |
|---|---|
| A larger fixed default | It oversubscribes the machine while every stream runs, and a child's limit is counted from its parent's time. |
| Cut the test files by the forks each will make | The forks a test makes are known only once the mutants are made and the stream reaches them; the shares still drift apart as streams kill mutants early or late. |
| One pool of children shared by every stream | Each child is a fork of its own stream's process, so a place must still be taken by that stream; the marks share the cores without a broker. |

## Consequences

- A shard's last running stream uses the machine as its streams end.
- A stream that ends without removing its mark leaves the others a smaller
  share, never fewer than one child.

## Related

- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0147](0147-a-stream-records-its-own-lines-and-holds-a-child-to-its-parent-s-steps.md)
