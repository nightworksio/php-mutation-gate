# Floors and the score

The gate holds each part of your code to a floor, the lowest mutation score it
may have, and the floor only rises.

## The score

The gate computes every score itself, from each mutant's result, and never
uses a runner's own threshold, such as Pest's `--min` or Infection's
`--min-msi`
([ADR-0003](../decisions/0003-a-floor-only-rises.md)).

| A mutant that is | Counts as |
|------------------|-----------|
| killed by a test, or rejected by a static analyser | killed |
| timed out or out of the memory cap, where its tests finish unmutated under the same limit | killed |
| survived, uncovered, flaky or unjudged | not killed |
| ignored with a reason, or proven equivalent by the compiler | left out |

The score is killed ÷ (all mutants − those left out) × 100, truncated to two
decimals. With `uncovered: exclude`, uncovered mutants are left out too, and
still listed in every report. A set with nothing left to count has no score:
it passes, and every report says *nothing to mutate* rather than 100%.

## Trees and their floors

A tree is a path, such as `src` or `app/Domain`. With no config, the trees
are the directories `phpunit.xml`'s `<source>` names; `trees` in the config,
and in a monorepo each package's manifest, lay their own over them. A tree's
floor is the higher of the floor its config or manifest declares, a policy
minimum, and the floor the baseline records, what was achieved. A declared
floor of 0 needs a reason, and that tree is not mutated.

A tree is judged whole. Every unit in it, a file or a held path, takes its
result from this run, from a proof whose key still matches, or, on a pull
request, from the newest result for a unit the change does not reach. So
sharding and caching decide only where and whether a mutant runs, never how a
tree's score adds up.

## The baseline

The floors live in a committed file, `mutation-gate.baseline.json`, which
holds floors and nothing else, so it changes only when a floor moves, and a
reviewer sees it move ([the baseline format](../using/files.md#the-baseline)).

- **A floor rises with the score.** `baseline --write` sets every improved
  floor to its measured score. On a pull request, `baseline.improvement:
  require`, the default, fails a run whose score rose until the raised floor is
  committed with the change that earned it; `report` passes and shows the
  command.
- **A floor falls only on purpose.** A pull request that lowers one carries
  `"lowered": {"from": …, "reason": "…"}` beside it, checked against the
  baseline of the branch it merges into.
- **A first CI run with no baseline measures first.** It writes the floors it
  measured for you to commit, then refuses until they are
  ([ADR-0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md)).

## Floors beyond trees

- **New code** has a floor of its own, `newCode.floor`, 100 by default: the
  mutants on the lines a pull request, a push or a save added or changed. The
  tree's floor and the new-code floor must both hold, so a tree at 62% cannot
  take in new code at 62%.
- **Code owners** can each have one, `owners.floors`, judged over every file
  CODEOWNERS gives the owner
  ([ADR-0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md)).
- **Security mutants**, those of the mutators tagged `security`, are judged
  again in their package's security set, which ratchets in the baseline as a
  tree does, above the declared minimum `security.floor`
  ([ADR-0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md)).
- **Each test suite** gets a score of what it alone kills, which is
  reported and never judged
  ([ADR-0025](../decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md)).

## What is left out

- **Ignores** name a mutant by its id, or a path glob and a mutator, each with
  a reason and an optional expiry, under `ignores.entries`; `ignores.maxDays`
  requires every entry to expire within that many days. An ignore leaves out a
  survivor or an uncovered mutant, never one a time budget left unjudged
  ([ADR-0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md)).
- **Equivalent mutants** that compile to the original program are proven so and
  left out, under `equivalence.static`, on by default
  ([ADR-0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)).

[Configuration](../using/configuration.md#every-key) lists every key above,
with its default.
