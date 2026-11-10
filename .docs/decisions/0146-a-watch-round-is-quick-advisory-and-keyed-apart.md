# ADR-0146: A round of `watch` is quick, advisory and keyed apart

**Status:** Accepted
**Date:** 2026-11-10
**Amends:** [0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): what a round of `watch` judges by and what it says, where `local.quick` is on
**Amends:** [0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): which test files a round of `watch` measures again, where `local.quick` is on
**Amends:** [0120](0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md): what a change during a round stops, and what a round prints

## Context

A round of `watch` plans as `run` does (ADR-0010). After a save to a
source file it measures again every test file whose coverage entry reads
that file (ADR-0023), checks each mutant with the static analyser, and
runs each mutant's own run with its verification tail: on the quick-start
fixture, one source file and 12 mutants, a round takes 16.4 to 17.1 CPU-s
and its first result arrives about 15 s after the save.

A split stream (ADR-0117) runs the test files that cover a shard's mutants
once, unmutated, and forks a mutant only where a test executes its site;
its parent is the child's control, so a kill needs no other. A test that
never reaches a site costs its own time and judges nothing.

The coverage map can lag a save: a call the save adds runs code no kept
entry shows. The static graph (ADR-0132) places each test file that names
what a source file declares, without running anything.

## Decision

1. **`local.quick`, `true` by default, makes every round of `watch`
   quick.** `pre-push`, `run` and CI are never quick.
2. **A quick round takes a changed source file's coverage instead of
   measuring it.** After the first round, which measures as today, a round
   measures again only the test files that changed since the round before,
   or that the kept map holds nothing of. Every other test keeps its lines,
   and each PHP source file that changed since the round before is covered
   on every line by the union of every test the kept map shows running any
   line of it and every test of the test files the static graph says reach
   it, where `outbreak.graph` is not `off` and the graph answers. A test
   that does not run a line costs time in the stream and judges nothing.
3. **A quick round streams its mutants and checks none statically.** It
   lays `splitStream.enabled: true`, `splitStream.sharding: source` and
   `staticCheck.tool: none` over the config: each mutant is forked first at
   the test the ledger last saw kill it (ADR-0117 decision 8), then at every
   covering test, in the covering test files in suite order. A mutant no
   stream can judge runs its own run as before. These win over what the
   config sets, an explicit `splitStream.enabled: false` or
   `staticCheck.tool` among them: streaming is what makes a round quick,
   and `local.quick: false` gives rounds that judge by the config as it
   stands.
4. **Its proofs are keyed apart.** The configuration part of every key a
   quick round builds carries `quick` (ADR-0007 item 3), so `pre-push`,
   `run` and CI never take a quick proof, and a later quick round does.
5. **It never says it passed.** The console report of a quick round opens
   with `mutation-gate: quick, not a verdict`, names its scope and says that
   `mutation-gate pre-push` gives the verdict; each line `watch` prints of a
   batch as it ends starts with `[quick]`.
6. **It takes the retested and the saved units first.** A quick round runs
   first the units whose last result is unsettled and which a test of a test
   file saved since the round before runs, by the kept map; then the units
   saved since the round before; then the rest in the order a budget takes
   them (ADR-0008). Within a run each mutant is forked first at its last
   killer, and inferred-subsumed mutants run last (ADR-0079): the order
   ADR-0133 decision 8 gives `outbreak serve`.
7. **A save cancels by unit.** Where `local.cancelByUnit` is on, a save
   during a round drops from its queue the units whose files it changed,
   and every other unit runs on. A batch that runs such a unit is halted:
   every `local.watchPoll`, a second at the least, the batch reads its
   units' files again, and once one has moved the gate sends itself
   `SIGUSR2`, on which every set of processes it awaits stops each one still
   running. Nothing a halted batch came to is read or told: every unit of it
   is left unjudged, as is every dropped unit, so their mutants are pending,
   never judged; what every other batch judged stands, keyed by the digests
   it was planned on. Where `pcntl` is missing a batch runs to its end. Off,
   a save stops the round before its next batch (ADR-0010).
8. **Each judged mutant is an event.** `watch --output=events` prints one
   line of JSON per judged mutant as its batch ends, `{"mutant", "site",
   "status", "by", "quick", "unit", "digest"}`: the status `killed`,
   `survived`, `timeout`, `pending` or `equivalent`, `by` the killing test,
   `digest` the digest the plan took of the unit's file; a mutant a marker
   ignores is no event; a survivor's verification run is inside its batch,
   so its event says what the verification judged. The PHP `serve` that
   `outbreak serve` starts sends the same lines to every client (ADR-0133
   decision 9). The console prints one block per unit as each batch ends,
   and ends a round with the whole report only with `watch --report`, or
   where `local.streamBatches` is off; `--output=problems` still ends every
   round with its problems.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `local.quick` | `true`, `false` | `true` | judges or reports only; a quick round's proofs are keyed apart |
| `local.cancelByUnit` | `true`, `false` | `true` | judges or reports only |

### Why no verdict moves

- **Nothing outside `watch` reads a quick result.** Its proofs carry
  `quick` in their key, and no other run builds that key.
- **A kill is a real failure.** A stream's child fails a test its parent
  passes; the shorter order of the covering test files can kill a mutant a
  whole-suite stream would not (ADR-0136), which is why the round is
  labelled and keyed apart.
- **Survivors can only grow.** No static check and a map that can lag show
  more survivors, never fewer; `pre-push` judges them all again.
- **A cancel judges nothing.** A stopped process can read to its runner as
  a timeout, so nothing a halted batch came to is read; its units and the
  dropped ones are left as units no batch took: unjudged, never killed.
- **Order moves no result.** It decides only which unit a budget reaches
  first.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Stream the whole suite in each round** | The verdict CI gives, at seconds to minutes per save. |
| **Keep today's rounds and their tail** | A round stays at 15 s to its first result on a one-file fixture. |
| **Lay the stream and the check only where the config leaves them unset** | A config that sets `staticCheck.tool` or turns streams off would make every round as slow as today while it still says quick; `local.quick: false` already gives those rounds. |
| **Let `pre-push` take a quick kill** | A quick kill can be one a whole-suite stream would not make; taking it loosens the verdict. |
| **Measure every moved test file, as today** | The re-measure is the largest part of a round after a source save. |
| **Stop the whole round on any save, as before** | A save to one unit throws away the work of every other; `local.cancelByUnit: false` keeps it. |
| **Read what a halted batch judged before the halt** | Its runner reads a stopped process as one that ran out of time, which counts as a kill; only a whole batch's result is sound. |
| **Print the whole report after every round** | It scrolls each round's blocks away; `--report` gives it. |

## Consequences

- `watch` shows a save's survivors from the covering test files alone,
  first at each mutant's last killer.
- `pre-push` judges every unit a quick round judged again, by its own key.
- `local.quick: false` gives the rounds ADR-0010 describes.
- A save shows its own unit's results first and leaves every other unit's
  work running.
- An editor or a daemon client reads one JSON line per judged mutant.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): key item 3
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): the order a budget takes units in
- [ADR-0079](0079-a-local-run-infers-what-a-killed-mutant-subsumes.md): subsumed mutants last
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): split streams
- [ADR-0120](0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md): the local loop
- [ADR-0132](0132-a-static-graph-places-what-the-coverage-map-cannot-and-the-map-stays-the-check.md): the static graph in quick rounds
- [ADR-0133](0133-outbreak-serve-holds-the-map-the-ledgers-and-the-coverage-in-memory.md): the same order, cancel and events in `outbreak serve`
- [ADR-0136](0136-a-first-pass-stream-may-run-only-the-tests-that-killed-before.md): kills a shorter order can make
