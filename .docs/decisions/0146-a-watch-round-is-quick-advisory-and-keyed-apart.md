# ADR-0146: A round of `watch` is quick, advisory and keyed apart

**Status:** Accepted
**Date:** 2026-11-10
**Amends:** [0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): what a round of `watch` judges by and what it says, where `local.quick` is on
**Amends:** [0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): which test files a round of `watch` measures again, where `local.quick` is on

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
   stream can judge runs its own run as before.
4. **Its proofs are keyed apart.** The configuration part of every key a
   quick round builds carries `quick` (ADR-0007 item 3), so `pre-push`,
   `run` and CI never take a quick proof, and a later quick round does.
5. **It never says it passed.** The console report of a quick round opens
   with `mutation-gate: quick, not a verdict`, names its scope and says that
   `mutation-gate pre-push` gives the verdict; each line `watch` prints of a
   batch as it ends starts with `[quick]`.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `local.quick` | `true`, `false` | `true` | judges or reports only; a quick round's proofs are keyed apart |

### Why no verdict moves

- **Nothing outside `watch` reads a quick result.** Its proofs carry
  `quick` in their key, and no other run builds that key.
- **A kill is a real failure.** A stream's child fails a test its parent
  passes; the shorter order of the covering test files can kill a mutant a
  whole-suite stream would not (ADR-0136), which is why the round is
  labelled and keyed apart.
- **Survivors can only grow.** No static check and a map that can lag show
  more survivors, never fewer; `pre-push` judges them all again.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Stream the whole suite in each round** | The verdict CI gives, at seconds to minutes per save. |
| **Keep today's rounds and their tail** | A round stays at 15 s to its first result on a one-file fixture. |
| **Let `pre-push` take a quick kill** | A quick kill can be one a whole-suite stream would not make; taking it loosens the verdict. |
| **Measure every moved test file, as today** | The re-measure is the largest part of a round after a source save. |

## Consequences

- `watch` shows a save's survivors from the covering test files alone,
  first at each mutant's last killer.
- `pre-push` judges every unit a quick round judged again, by its own key.
- `local.quick: false` gives the rounds ADR-0010 describes.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): key item 3
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): split streams
- [ADR-0120](0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md): the local loop
- [ADR-0132](0132-a-static-graph-places-what-the-coverage-map-cannot-and-the-map-stays-the-check.md): the static graph in quick rounds
- [ADR-0136](0136-a-first-pass-stream-may-run-only-the-tests-that-killed-before.md): kills a shorter order can make
