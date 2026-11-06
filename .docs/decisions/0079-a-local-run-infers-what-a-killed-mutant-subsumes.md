# ADR-0079: A local run infers what a killed mutant subsumes

**Status:** Accepted
**Date:** 2026-11-06
**Amends:** [0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): what `watch` and pre-push show of a mutant their budget left

## Context

`watch` and pre-push run under a time budget (ADR-0010). A mutant the
budget does not reach is unjudged, and a person learns nothing of it until
a larger budget runs it.

Many mutants of a file say the same about its tests. Mutant A subsumes
mutant B where every test that kills A also kills B, so a run that killed A
would have killed B too. A run with a full kill matrix (ADR-0014) records
every test that kills each mutant, so it shows which mutant subsumes which.
Where no such run has judged a file, one rule still holds: a mutant that
changes the tokens of an expression subsumes the mutant that negates that
expression.

## Decision

1. **The ledger learns which mutant subsumes which.**
   - A run with a full kill matrix teaches it, for each file whose every
     mutant the run judged. It keeps, for each killed mutant, one killed
     mutant whose killers are all among its own, the one with the fewest
     killers.
   - The ledger keeps this in an optional `subsumption` section, with no
     format bump. A newer run replaces what the ledger held of each file it
     judged. A file it learned no mutant subsumed in holds nothing.
2. **The negation rule stands in where no run learned of a file.** Of a
   change and a negation of the same expression on the same line, the
   change subsumes the negation. Of two negations of one expression, the one
   with the lower id subsumes the other.
3. **A local run holds subsumed mutants back.** Outside CI, under
   `local.inferSubsumed` (true by default), the plan hands each shard which
   mutant subsumes which in its files, as either ledger learned it. The run
   runs each mutant another of its mutants subsumes after the others, so the
   budget reaches the subsumers first.
   - Patched Pest runs each mutated copy the hand-off names after the
     others. The hand-off then runs with or without a static check before
     the tests (ADR-0020).
   - The native runner starts them last.
   - Infection runs in its own order, and inference still applies to it.
4. **A mutant the budget left unrun shows its kill as inferred.** Where a
   mutant that subsumes it was killed, directly or through a chain of
   subsumers, its reason says so before why it was left. A Pest run that
   its deadline stopped leaves each mutant it never told of unjudged, before
   its own run finished, so inference reads it as unrun too.
5. **An inferred kill is never a proof.** The mutant stays unjudged. It is
   never kept as a kill, never counted in a score and never passes a
   verdict. CI never infers, and `local.inferSubsumed: false` turns both the
   order and the inference off.

## Consequences

- A local run judges the mutants that say the most first, and a person sees
  which of the rest their subsumers already account for.
- A run with a full kill matrix writes a little more to the ledger.
- A shard of a local plan reads one more handed file, `subsumption.json`
  beside its coverage map. CI plans write none.
- A survivor whose subsumer was killed contradicts what the ledger learned.
  It is never inferred: only a mutant that did not run is.

## Alternatives

| Alternative | Why not |
|---|---|
| **Count an inferred kill in the local score** | A subsumption learned from an older run can be wrong for the code now. A score that counts it could pass what a full run fails. |
| **Skip subsumed mutants altogether** | A subsumed mutant still runs where the budget allows. Skipping it would trade a judgement for a guess. |
| **Infer in CI too** | CI judges what is merged, and every mutant there has its own proof (ADR-0007). |

## References

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): what a proof holds
- [ADR-0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): the local modes and their budgets
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): the full kill matrix
- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): the hand-off of a Pest run's mutants
