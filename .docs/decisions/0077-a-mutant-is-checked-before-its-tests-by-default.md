# ADR-0077: A mutant is checked before its tests by default

**Status:** Accepted
**Date:** 2026-11-04
**Amends:** [0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): its rule 11, `staticCheck.before` false by default

## Context

ADR-0020 rule 11 places each static check where it pays. A mutant is
checked before its tests where its mutator's rejection rate × its judging
tests' time is greater than one check's time. `PreCheck` decides this from
the ledger's learned rates and the analyser's measured times. That rule
ran only under `staticCheck.before`, which was false by default, for two
measured reasons:

- The analyser's run over the originals took 188 to 205 s of every shard
  before any mutant ran.
- A check cost about as much as the test run it saved.

[ADR-0052](0052-phpstans-warm-up-uses-every-core-and-its-result-cache-is-kept-between-runs.md)
removed the first cost. The warm-up now runs on every core, and its result
cache is kept between runs. On the gate's own CI, the static check step
fell from 163 to 188 s to about 60 s on a cache miss. On a laptop, a
check after a warm-up took 2 to 3 s. That is much less than the covering tests of a
slow mutant, such as one that runs a Laravel suite on a database.

Rule 11's own condition already keeps a fast mutant's tests first. A
mutant is checked first only where the check is expected to save time, and
a mutator that rejects nothing in 50 checks is then checked only after the
tests.

Two gaps in rule 11 made a cold ledger slow. On the gate's own CI, one test
shard with no learned rates took 2048 s against 1219 s before, about 1430 s
of it in the static check:

- A shard offers all its mutants in one batch. Rule 11 read each mutator's
  rate before that batch, so it checked every mutant of a mutator with
  fewer than 50 checks, 2686 checks in that shard, not 50 per mutator.
- Before any check was timed, a check was taken to pay whatever its tests
  took, even where they took 0.19 s and a check took 2 s.

## Decision

1. **`staticCheck.before` is true by default.** Wherever an analyser is
   wired, rule 11's placement decides each mutant: it is checked before its
   tests where its mutator's rejection rate × its judging tests' time is
   greater than one check's time. Survivors are still checked after their
   tests.
2. **A mutant whose tests take no longer than one check is checked after
   them,** whatever its mutator's rate: a check saves at most its tests'
   time. Before any check is timed, one check is taken to last as long as
   the analyser's warm-up over the originals, which no check from its
   result cache outlasts.
3. **A run checks a mutator before its tests only until it holds 50 checks
   of it,** counting the ledger's checks and those this run already makes
   before their tests, in the same batch or an earlier one.
4. **`staticCheck.before: false` restores checking survivors only.** The
   analyser's run over the originals then waits until a survivor needs it.
5. Nothing else in rule 11 changes. The placement, the rates and the
   timings judge only, so the key is unchanged (ADR-0007), and a pre-check
   never moves a score.

## Consequences

- With an analyser wired, a shard runs the analyser over the originals
  before its first mutant, from the warm result cache where one is kept.
- A project with no analyser, or with `staticCheck.tool: none`, runs as
  before.
- A cold ledger checks at most 50 mutants of each mutator before their
  tests, and none whose tests take no longer than one check. Those first
  checks pay for the rates that later keep fast mutants' tests first.
- With no check timed, a shard warms the analyser up before it chooses, to
  time a check. A survivor's check after its tests reuses that warm-up.

## Alternatives

| Alternative | Why not |
|---|---|
| **Keep `false` by default** | It leaves the analyser idle for slow mutants now that ADR-0052 makes a check cheaper than their tests. |
| **A fixed time threshold in place of the learned rate** | Rule 11 already weighs the learned rate against the measured check time, so a fixed threshold would duplicate it less precisely. |

## References

- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): rule 11, where each check is placed
- [ADR-0052](0052-phpstans-warm-up-uses-every-core-and-its-result-cache-is-kept-between-runs.md): the warm-up on every core and its kept result cache
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): what the key holds
