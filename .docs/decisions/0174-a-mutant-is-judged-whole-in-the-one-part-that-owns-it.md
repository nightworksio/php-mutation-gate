# ADR-0174: A mutant is judged whole in the one part that owns it

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): decision 9's judging of a mutant in every part that holds a covering test
**Amends:** [0171](0171-a-test-no-loaded-file-declares-leaves-the-cut-standing-and-an-uncovered-mutant-is-judged-once.md): decision 3's owner, for a mutant the ledger names a likely killer of

## Context

Under `splitStream.sharding: tests` every shard takes every unit and streams
one part of the test files (ADR-0117). A shard judged each mutant that a
test of its part covers, by those tests alone, and the verdict merged the
parts. A mutant covered by tests in five parts was judged five times: each
part forked it at its own covering tests, a part whose tests did not kill
it forked it at every one of them, and each part that fell back on an own
run ran one of its own.

The gate's own full plan in run 44104819119 cut its test files into 19
parts. Shard 9's part held a nineteenth of the suite's time and reached
18,675 of the 56,473 mutants; it was cancelled at 15 minutes, 9 of them in
its streams. Over the parts of the probe's coverage map, the covered lines
were reached 7.2 times each on average at 19 parts, and 10.5 times at 38:
more shards made every shard reach a smaller share, but the run judged more.
The ledger named a likely killer for 45,610 of the mutants.

## Decision

1. **`splitStream.judging` says where a run that cuts its test files judges
   each mutant.** `whole`, the default, judges it in the one part that owns
   it, by every test that covers it; `parted` judges it in every part that
   holds a test that covers it, by that part's tests, as before this
   decision.
2. **The owner of a mutant is the part of its likely killer:** the first
   test the ledger names (ADR-0013) among every test that covers it. Where
   the ledger names none, the part of its first covering test owns it, and
   where no test covers it, the part its id falls to (ADR-0171). Every
   other part leaves it not reached. Every shard reads the same map and
   ledger, so every shard agrees on the owner.
3. **The owner's first pass streams its own part's files,** forking the
   mutants it owns at their likely killer, or at every covering test of the
   part where the ledger names none. **A second pass streams the covering
   tests the first did not**, for each owned mutant the first left
   undecided: every one for a mutant forked at its killer alone, and those
   of the other parts for the rest.
4. **A mutant the owner judges is judged whole:** its survival dooms the
   run (ADR-0008), static analysis checks it, a mutant a stream falls back
   on runs one own run by every covering test, and `flaky.confirmSurvivors`
   runs it again by every covering test, all in the owner alone.
5. **`splitStream.sharding` and `splitStream.judging` are set by the
   `StreamShards` builder.**

## Why no verdict moves

- The owner forks each mutant at each of its covering tests until one
  kills it, as the parts together did: a kill, a timeout or a survivor
  stands on the same tests.
- A test of another part runs in the owner's second pass in a stream of the
  files that declare it, unmutated, the child forked at the site as in any
  stream (ADR-0117); the parent is the child's control wherever it runs.
- A mutant every part left survived every covering test; the owner's
  survivor did the same in one shard, which the merge takes whole.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep judging in every part (`parted` as the default) | Each part forks a mutant another part already killed, and runs its own fall-back runs, so the run judges each covered mutant several times; it stays a setting. |
| `splitStream.sharding: source` | Each shard streams every test file that covers its part of the source, so every shard runs most of the suite unmutated. |
| Owners assigned in the plan, balanced by each mutant's cost | The plan does not make the mutants; owners there wait on the plan making them. The killer's part needs nothing every shard does not already read. |
| Owner by the mutant's id alone | It ignores where the likely killer runs, so most kills would wait for a second pass of another part's files. |

## Consequences

- Each covered mutant is forked once where its likely killer kills it, in
  one shard, not once in every part its tests reach.
- A mutant that survives, or whose killer no longer kills it, is streamed
  by its owner over the other parts' files that cover it, which the parts
  ran alongside their own before.
- A part that holds the likely killers of many mutants judges more of them;
  the cut still weighs only the tests' times.

## Related

- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md)
- [ADR-0162](0162-a-held-unit-is-judged-whole-in-one-shard.md)
- [ADR-0171](0171-a-test-no-loaded-file-declares-leaves-the-cut-standing-and-an-uncovered-mutant-is-judged-once.md)
