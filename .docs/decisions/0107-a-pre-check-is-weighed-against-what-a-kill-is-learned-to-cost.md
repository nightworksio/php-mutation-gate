# ADR-0107: A pre-check is weighed against what a kill is learned to cost

**Status:** Accepted
**Date:** 2026-11-06
**Amends:** [0077](0077-a-mutant-is-checked-before-its-tests-by-default.md): item 1, what its judging tests' time is
**Amends:** [0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): rule 11, what the tests' time a check is weighed against is

## Context

ADR-0020 rule 11, as ADR-0077 turns it on by default, checks a mutant
before its tests where its mutator's rejection rate × its judging tests'
time is greater than one check's time. The tests' time is read from the
coverage map: the sum of every covering test's own time.

A mutant's run does not cost that sum. Its tests run likely killers first
and stop at the first kill, and a warm worker forks each run from a booted
process. The ledger already learns what a kill costs: each mutant a test
killed, how many times its covering tests' own time its run took
(`KillPace`, ADR-0073), recorded on every run whatever `timeouts.multiple`
says.

On the gate's own push run 42232973458, every mutant together had a median
of 0.207 s and a p90 of 0.49 s. The run made 8,837 checks at 2.11 s each,
7,898 of them before the tests, and they rejected 4,934 mutants. At that
rate a check pays only where the tests it spares take over 3.4 to 3.8 s,
which held for 3.3% of the run's mutants. Weighed against the covering
sum, rule 11 still checked most of them first.

## Decision

1. **`staticCheck.testsTime` names the time a check is weighed against.**
   - `learned`, the default: the covering tests' own time × the median of
     the ledger's kill paces, the pace the plan learns. Where it holds no
     kill, the covering tests' own time.
   - `covering`: the covering tests' own time, summed.
2. **The plan learns the pace and hands it to each shard** in its briefing,
   as `learnedPace`, beside a learned `timeouts.multiple`. It reads the
   kills a learned `timeouts.multiple` reads: the last 2,000 the run's own
   scope's ledger holds, or the default branch's where that holds none. It says what it learned, as in
   `staticCheck.testsTime learned: 0.9 times their tests' own time, the
   median of 812 kills.` It learns none under `covering`, or where checks
   run only after the tests.
3. **The pace is the kills' median, unbounded.** A learned
   `timeouts.multiple` bounds a percentile between 2 and 10, as it must
   allow the slowest kill. The pace estimates the typical one, which can
   take less than its covering tests' own time.
4. **Nothing else in rule 11 changes.** ADR-0077's items 2 and 3 compare
   one check with this time, and the 50-check learning stays. The
   placement and the pace judge only, so the key is unchanged (ADR-0007),
   and a pre-check never moves a score.

## Consequences

- With a warm ledger, a mutant whose kill is learned to cost less than one
  check is checked only after its tests. Fewer checks run beside the tests
  on the shard's cores.
- A cold ledger weighs checks as before until its first kills are recorded.
- A slow suite, whose kills take a large share of their covering tests'
  time, keeps its pre-checks under either value.
- `staticCheck.testsTime: covering` restores the earlier weighing.

## Alternatives

| Alternative | Why not |
|---|---|
| **Keep the covering sum** | It weighs a check against tests a forked run with likely killers first does not run, so most checks before the tests cost more than they save. |
| **Cap the time by the mutator's tallied seconds per mutant (ADR-0076)** | A tally's seconds are each mutant's share of its shard's wall time, apportioned by duration, so a mutant a check rejected before its tests weighs nothing. Each rejection would lower the cap that let its mutator be checked. |
| **A fixed time threshold** | ADR-0077 rejected one, and the learned pace adapts to the project's suite where a threshold cannot. |

## References

- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): rule 11, where each check is placed
- [ADR-0077](0077-a-mutant-is-checked-before-its-tests-by-default.md): checks before the tests by default
- [ADR-0073](0073-a-limit-can-be-learned-from-what-earlier-runs-measured.md): the kill paces the ledger records
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): what the key holds
