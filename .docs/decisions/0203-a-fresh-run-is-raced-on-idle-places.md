# ADR-0203: A fresh run is raced on idle places

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0148](0148-a-mutant-of-a-file-pest-loads-first-is-judged-in-a-fresh-run.md): what settles a fresh run's mutant

## Context

A fresh run is one `pest` process over all of a mutant's covering tests,
stopped at its first failing test (ADR-0148). The fresh runs of a request
run side by side in its pool. At the end of a shard only a few are left,
and a run whose likely killer does not kill (ADR-0202) works through its
covering tests one at a time while the other places idle. On probe
44424669540 shards 9, 15 and 18 spent their last minutes this way, and
shard 18 judged nothing in 426 of its 470 s.

## Decision

1. **Where the fresh runs of a request leave places idle, each is raced.**
   Its covering tests are dealt, fastest first, to parts, and the parts run
   beside it on the idle places. The longest-limited runs are given their
   share first, and a run with a share under two parts is not raced.
2. **The fresh run itself runs as before**, and each part is a fresh run of
   its tests alone, under the fresh run's own limit, with its own files.
3. **A mutant is settled when its fresh run ends**, which judges it as
   before, **or when one of its parts kills it by a named covering test
   first.** The fresh run and the other parts are then stopped.
4. **A part's kill stands only once the same part, serving the file
   unmutated, passes.** Where that run fails or never starts, the fresh run
   runs again in full and judges the mutant.
5. **`pest.freshRace`**, on by default, turns it off.
6. A part's time and its unmutated run's are the `mutation` step's.

## Why no verdict moves

- A survivor, a timeout, running out of memory and an unjudged mutant all
  come from the fresh run alone, exactly as before. A part's own timeout
  or failure to start settles nothing.
- A part's kill is a covering test that fails with the mutant served and
  passes with the file unmutated, in the same process shape as the fresh
  run (ADR-0148). That is the evidence a fresh run's kill gives. The fresh
  run reaches that test unless an earlier test kills the mutant first, so
  only the test named for the kill can differ.
- Two cases can differ. The first is a test whose result depends on the
  tests before it in the fresh run: it fails in the part, without those
  tests, and passes in the fresh run. Requiring the part to pass unmutated
  is the same vouching ADR-0143 asks of a kill. The second is a fresh run
  that would run out of its limit before it reaches the part's killing
  test: the part judges that mutant killed, not timed out. A part runs
  under the fresh run's whole limit, never its own share of it, so a part
  never times out where the fresh run would not.

## Alternatives considered

- **Cut the fresh run itself into parts**: every kill outside the first
  part would then run without the tests before it, and a timeout would be
  measured against a part's own time rather than the whole run's.
- **Wait for the fresh run even after a part kills**: correct, but it
  spends the time the race exists to save.

## Consequences

- An idle place at the end of a shard runs a fresh run's tests instead of
  waiting on it.
- A part's kill costs one more short run, its control.
- The pool can now let go of a run before it ends (`endsSettling`).

## Related

- [ADR-0143](0143-a-kills-control-replays-its-order.md)
- [ADR-0148](0148-a-mutant-of-a-file-pest-loads-first-is-judged-in-a-fresh-run.md)
- [ADR-0202](0202-a-fresh-run-asks-for-its-likely-killers-first.md)
