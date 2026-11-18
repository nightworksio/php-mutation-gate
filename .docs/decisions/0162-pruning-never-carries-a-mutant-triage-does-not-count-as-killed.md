# ADR-0162: Pruning never carries a mutant triage does not count as killed

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0106](0106-carried-survivors-are-judged-again-and-their-re-check-is-bounded-and-shared.md): item 1, which results pruning carries

## Context

ADR-0106 stops pruning from carrying a unit whose newest result let a mutant
through, so a test added to kill that mutant counts. It names the statuses
that let a mutant through: survived, uncovered and unjudged.

A mutant can fail the verdict with any other status. A timeout counts as a
kill only where timeout triage confirms it: the mutant's tests, run unmutated
under the same limit, finished within it (ADR-0008). Where that control ran
out of the limit too, or never finished, the mutant is *too slow to judge*. A
mutant out of the memory cap whose control did not finish under the cap is
*too heavy to judge*. Both count as not killed.

Pruning read only the status. So it carried a timeout too slow to judge, as
it had carried survivors before ADR-0106, until the unit's own content
changed or `pruning.audit` ran out. A test made fast enough for the control
to finish counted for nothing in between. On this repository, eight mutants
of four units were carried as too slow to judge because one test ran out of
its limit unmutated. The pull request that made the test fast still failed
the verdict on them.

## Decision

1. **Pruning never carries a unit whose newest result holds a mutant triage
   does not count as killed.** The pruner judges each mutant of that result
   by the run's own triage (`timeouts.mode`, and the memory triage). Where one
   scores as not killed (survived, uncovered, unjudged, too slow to judge, or
   too heavy to judge), the unit runs every mutator. The rule stays per unit,
   as ADR-0106 decides.

2. **A kill triage confirmed is still carried.** That covers a timeout whose
   control finished within its limit, a mutant out of memory whose control
   held under the cap, and an errored mutant. A mutant left out of the score
   (ignored by its marker, or failing unmutated) does not stop a unit being
   pruned.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Adding the timed-out and out-of-memory statuses to those that let a mutant through** | Every timeout would stop pruning, including the ones triage confirmed as kills. |
| **Counting too slow to judge as let through in a mutator's window** | The window decides which mutators are pruned, not which results are carried. A ledger's window learned before the change would keep carrying the stale result. |
| **Waiting for `pruning.audit`** | The verdict fails on every push for up to a week after the test is fixed. |

## Consequences

**A fix that lets a timeout's control finish counts on the next run.** A unit
whose last result holds a too-slow or too-heavy mutant runs all its mutators
again until a run judges every mutant killed.

## Related

- [ADR-0025](0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md): pruning and carried results
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): timeout triage
- [ADR-0106](0106-carried-survivors-are-judged-again-and-their-re-check-is-bounded-and-shared.md): the rule this amends
