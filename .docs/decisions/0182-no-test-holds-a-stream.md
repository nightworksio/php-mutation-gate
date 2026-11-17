# ADR-0182: No test holds a stream

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): how Pest's own process waits for a pass's streams; [0176](0176-a-test-can-say-no-fork-may-judge-it.md): the gate's own tests that share a socket or a daemon with a child

## Context

A split stream's parent runs each test once, unmutated, and forks a child
at each mutant's site (ADR-0117). Pest's own process waited for each
stream in turn, with no limit. A child is held to a limit (ADR-0177); the
parent was not.

Run locally on the plan of the gate's own shard 9, with streams that run
only the covering tests (ADR-0181), one stream sat 15 minutes in one test
of `ServingTest` until the run's cap, so the second pass never started.
66 children forked off that parent waited with it. The two tests before it
had failed in the parent, though the same tests pass on their own.

Those tests enter a project and talk to a daemon through a socket in that
project, or fork a process that stands in for one. A child forked in such
a test after it entered the project shares the project's directory, the
socket's path and the daemons with its parent and every sibling: each
child sends its own request to the daemon its parent started, writes the
file its parent then reads, removes and replaces the socket its parent
connects to, and starts daemons of its own there. Its parent's test then
reads a sibling's answer, or waits on a socket no daemon will answer.

## Decision

1. **The gate's own tests that enter a served project, or fork a process
   that stands in for a daemon, say no fork may judge them from there**
   (`Stream::unforkable()`, ADR-0176): `ServedProjects::enteredAt`,
   `ServedProjects::aside` and `AnsweringDaemon::after`. A child forked
   before that point stops there, and its mutant runs its own run.
2. **A stream's parent keeps the deadline of the test it runs** in its
   stream's mark (ADR-0173): the limit a child of that test is held to, of
   the map's time of the test (`timeouts.perTest` times it, at least
   `timeouts.testFloor`), or `timeouts.most` where the map timed none or
   `timeouts.perTest` is off, from the test's start. Each wait for a
   child's place moves it on by as long, as a child's limit leaves that
   wait out (ADR-0177). The parent clears it as the test ends.
3. **Pest's own process watches every stream of a pass at once**
   (`StreamWatch`), in place of waiting for each in turn. A stream whose
   parent passed its test's deadline is stopped with every process under
   it (`ProcessTree`, ADR-0158's freeze-then-kill), and a stream of the
   tests it had not ended, but that one, starts in its place, under a
   place one round of streams on, so its plan, ids and results files are
   its own. Where a stream runs whole files, its tests are not named by
   their ids, so none starts in its place, and the mutants of the tests it
   had not ended go to their own runs, as those of a stream a signal ended
   do (ADR-0181).
4. **A stream that ended has its mark taken away**: one a signal ended,
   or that was stopped, leaves it, and a mark left behind counts as a
   running stream among those that share the cores (ADR-0173).
5. **`splitStream.parentDeadline`** (`true`, `false`): `true`, by
   default, does the above; `false` waits for every stream however long
   its tests take. It decides how the gate runs and moves no verdict, so it
   is in no key.

## Why no verdict moves

- A mutant is killed in a stream only by a covering test its parent passed
  (ADR-0117), and survives there only where its parent passed every
  covering test with the mutant's child passing too (`StreamVerdicts`).
  The stopped test has no parent line, so no mutant is killed by it in the
  stream, and every mutant it covers that no other test killed leaves the
  stream for its own run, which runs that test as any own run does.
- The stream started in its place runs the same tests, by the same ids,
  forking at the same sites, and its kills are read as the stopped
  stream's would have been.
- A test that says no fork may judge it is judged by its mutants' own
  runs, as ADR-0176 sets out.

## Consequences

- A test that never ends costs a stream its test's limit, not the run.
- A test that the parent runs slower than its limit allows, on a loaded
  machine, sends the mutants only it covers to their own runs: slower,
  never another verdict.
- Mutants of the serve commands' code are judged by their own runs.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **An alarm in the parent that fails the stuck test** | A signal handler runs only between PHP's own steps, so a parent blocked in a system call or in C never runs it; and the gate's own tests use the same alarm. |
| **A process group per stream, killed whole** | The gate starts its processes in a session of their own, which it stops whole (ADR-0158); a stream that left its group would escape that. |
| **Stop the stream and leave its other tests to their own runs** | One stuck test would send every later test's mutants to their own runs, far slower than starting a stream on them. |
| **`timeouts.most` as the deadline** | Five minutes of a stream held by one test, where the map's time bounds it to seconds. |
