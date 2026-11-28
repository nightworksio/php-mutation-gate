# ADR-0334: A run that samples can sample a tree held to 100

**Status:** Accepted
**Date:** 2026-11-23

## Context

A sampled run judges a sample of the units no proof covers and estimates
each tree's score (ADR-0020). A tree held to a floor of 100 is never
sampled: no sample can show that a floor of 100 holds, since one unsampled
unit may hold a survivor.

A proof is keyed by the gate's own code (ADR-0084). A project that judges
itself with the gate it changes resets every key with each such change, and
a pull request then runs every unit. Where every tree is held to 100, a
pull request cannot sample its way out of that run, and a pull request that
samples by itself (ADR-0086) samples nothing.

## Decision

1. **`sampling.top`, false by default, has a run that samples sample a tree
   held to a floor of 100 too.** The units that hold new code stay exact,
   as in every sampled run.
2. **Such a tree passes where no mutant its exact units or its sample
   counted survived, and fails where one did.** A survivor a sample finds is
   a survivor of the tree, which no unsampled unit undoes. A pass says only
   that the run found none.
3. **The verdict names `sampling.top` as a loose value in force**
   (ADR-0142), and shows the tree's estimate as every sampled run does.
4. **Nothing else of a sampled run changes**: its sampled units' proofs are
   written, `passed` is never written, no floor is raised, and the default
   branch never samples by itself (ADR-0020, ADR-0086).

## Consequences

- A pull request of a project held to 100 can run its new code whole and a
  sample of the rest; the default branch's run judges every unit.
- A survivor in an unsampled unit of such a pull request is found on the
  default branch, not on the pull request.
- Where `sampling.top` is false, a tree held to 100 runs whole, as before.
