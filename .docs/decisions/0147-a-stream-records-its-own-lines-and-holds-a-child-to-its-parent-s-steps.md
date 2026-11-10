# ADR-0147: A stream records its own lines and holds a child to its parent's steps

**Status:** Accepted
**Date:** 2026-11-10
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): decision 5, what a child is held to where `outbreak.so` counts steps; decision 7, a test that holds a database connection
**Amends:** [0146](0146-a-watch-round-is-quick-advisory-and-keyed-apart.md): decision 2, where a quick round's lines come from

## Context

A mutation run loads pcov and Xdebug wherever the PHP that runs it does.
pcov enabled hooks every op of every process, collecting or not; Xdebug in
any mode but `off` slows every call. A mutation run reads coverage only
where its streams record what their tests execute (ADR-0135).

A quick round of `watch` (ADR-0146) takes a changed source file's coverage
from the kept map and the static graph, and measures a changed test file
again with pcov: on the quick-start fixture that measure is the last pcov
run of a round. Its streams run the round's covering tests anyway, and
record nothing of them.

A stream holds a child to `timeouts.perTest` times the parent's own time of
the same test (ADR-0117 decision 5). Wall time moves with the machine's
load: a busy laptop stretches a child past its limit, and a timeout counts
as a kill.

A test that holds a socket the stream did not hold as it started forks
nothing (ADR-0117 decision 7): a child would share the connection, and its
writes would reach the server the parent's run is judged against. A
database connection is such a socket, so in a suite whose tests hold one,
each mutant those tests cover runs its own run.

`outbreak.so` (ADR-0130) already observes a stream's calls. TCE and the
duplicates judged once (ADR-0130, ADR-0137) apply wherever a run hands its
mutants over, `watch`'s rounds among them.

## Decision

1. **A mutation run starts with its coverage drivers off.** Its process
   and every process it starts get `PCOV_ENABLED=0` where `pest.pcovOff` is
   on, unless its streams record lines with pcov, and `XDEBUG_MODE=off`
   where `pest.xdebugOff` is on. A coverage run is untouched.
2. **`outbreak.so` records lines.** Where the stream's process starts with
   `lines` among `MUTATION_GATE_OBSERVE`, the extension takes over PHP's
   executor at startup, as pcov does, and records from `outbreak_lines_start()`
   to `outbreak_lines_stop()` each line an executed op of a user function
   carries, by file. A line pcov reports for no op may be among them, so its
   lines are a superset of pcov's executed lines: a test may cover more
   lines, never fewer. Where another extension took the executor over after
   it, `outbreak_lines_start()` answers false and nothing is recorded by it.
   Where `outbreak.lines` is on, a stream records with `outbreak.so` where it
   records, and with pcov elsewhere; off, with pcov alone. A run that stands in for the suite's coverage report (ADR-0135) records
   with pcov, whose executable lines its report reads.
3. **A quick round's streams record their tests' lines.** Where
   `local.recordLines` is on, each test a stream's parent runs whole is
   recorded, and the kept map takes the recorded lines in place of that
   test's entries after the round. A test that ran in a process of its own,
   or that the stream handed to a forked process, keeps its entries.
4. **A changed test file can be placed instead of measured.** Where
   `local.siteHits` is on and the round's streams record lines, a changed
   test file the static graph answers for is not measured: each of its
   tests keeps its entries and covers every line of each source file the
   graph says the test file reaches, until a stream records it. A test file
   the graph does not answer for is measured as before.
5. **A child is held to its parent's steps.** Where `outbreak.steps` is on
   and `outbreak.so` counts them, a stream's processes count steps: each
   jump an op takes back or forth, and each call of a user function. A child
   is a timeout once its steps since the fork pass `timeouts.perTest` times
   the parent's own steps from the fork to the test's end, plus
   `outbreak.stepFloor`; until the parent ends the test, it is held only to
   `timeouts.most`. Wall time then holds a child to `timeouts.most` alone. A
   child past its steps stops itself by `SIGXCPU`, which the parent reads as
   a timeout.
6. **A child reconnects to its own copy of the database.** Where
   `splitStream.reconnect` is on, a test that holds sockets the stream did
   not hold as it started forks where each of them is a connection of the
   application's own to a database, and none holds an open transaction. At
   the fork the parent copies each such database as its committed state
   stands, and the child connects to its copy before its mutant runs and
   drops it as it ends. A test that holds any other socket, a connection
   with an open transaction, or a database the parent cannot copy, falls
   back as ADR-0117 decision 7 says.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `pest.pcovOff` | `true`, `false` | `true` | speed only |
| `pest.xdebugOff` | `true`, `false` | `true` | speed only |
| `outbreak.lines` | `true`, `false` | `true` | which recorder a stream records with |
| `local.recordLines` | `true`, `false` | `true` | a quick round's map |
| `local.siteHits` | `true`, `false` | `true` | a quick round's map |
| `outbreak.steps` | `true`, `false` | `true` | which child is a timeout |
| `outbreak.stepFloor` | a count of steps | `10000000` | the least steps a child may take past its parent's share |
| `splitStream.reconnect` | `true`, `false` | `false` | which test a stream forks in |

### Why no verdict moves

- **Recorded lines are measured lines.** A stream's parent runs the test as
  a coverage run does, and the recorder's lines are a superset of pcov's.
- **A placed test file runs only where it reaches.** A stream forks a
  mutant only at a site a test executes; a placed test that executes
  nothing of a file costs its run and judges nothing. Quick rounds are
  advisory and keyed apart (ADR-0146).
- **A copy is the parent's database.** Without an open transaction every
  write the test made is committed, so the copy holds what the parent's
  connection reads, and nothing the child writes reaches the parent. State a
  server keeps per session, such as a temporary table, is not in a copy,
  so the setting is off by default.
- **Steps do not move with the machine.** A parent and its child count the
  same program's steps; a child that loops forever passes any limit, and
  one slowed by the machine's load passes none.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Count every op** | A call per op in every process of the stream; jumps and calls already bound every loop and every recursion. |
| **Record sites alone, through the switch function** | Gives the round's own mutants, not the lines a later round's mutants need from the kept map. |
| **Keep the wall-clock limit beside the step limit** | A loaded machine would still time children out. |
| **Leave pcov on in mutation runs** | Its hook costs every op of every process that never collects. |
| **A child reconnects to the same database** | Its writes reach the parent's server, and a reconnect drops the parent's open transaction. |
| **A child copies the database itself** | The parent runs on while the child copies, so the copy could hold the parent's later writes. |

## Consequences

- A mutation run spends nothing on coverage drivers it never reads.
- `watch` keeps a map as exact as a measured one for every test its
  streams ran, without a coverage run.
- A child's timeout no longer depends on what else the machine runs.
- A suite whose tests hold a database connection outside a transaction
  streams under `splitStream.reconnect`, paying a copy of the database per
  fork in place of a run per mutant.

## Related

- [ADR-0090](0090-each-test-of-a-mutants-run-is-held-to-a-multiple-of-its-own-time.md): per-test limits
- [ADR-0130](0130-a-mutant-that-compiles-to-its-original-is-equivalent-and-is-never-run.md): `outbreak.so`
- [ADR-0132](0132-a-static-graph-places-what-the-coverage-map-cannot-and-the-map-stays-the-check.md): the static graph
- [ADR-0135](0135-a-full-split-stream-run-starts-without-waiting-for-a-fresh-map.md): streams that record
