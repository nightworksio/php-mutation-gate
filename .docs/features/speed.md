# Speed

Mutation testing runs the suite once for every mutant, so the gate's speed
comes from running fewer mutants, running each one for less, and spreading
what is left over as many machines as the work needs. None of it changes a
verdict: [why a verdict can be trusted](trust.md) says what bounds each
shortcut.

## Running less

- **Only what a change reaches** is mutated on a pull request
  ([what a change reaches](reach.md)).
- **A proof cache keyed by content** skips every unit an earlier run already
  proved, kept in the GitHub Actions cache, a directory, S3 or R2, Google
  Cloud Storage or Azure Blob Storage
  ([ADR-0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md),
  [proofs and trust](../using/proofs.md)).
- **A pull request's own earlier runs**: a new push stands on the proofs its
  earlier runs made, and mutates only what they do not prove, unless
  `proofs.ownScope` is `false`
  ([ADR-0081](../decisions/0081-a-new-push-mutates-only-what-its-earlier-runs-do-not-prove.md)).
- **Pruning**: on unchanged code, a mutator that has let no mutant through
  lately is skipped and its last result carried, and the scheduled full run
  audits it
  ([ADR-0025](../decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md)).
- **Identical mutants run once**: mutants that leave their file alike share
  one verdict, under Pest and the PHPUnit, PhpSpec, Codeception and Testo
  runners.
- **A static analyser kills what it rejects**: Mago, PHPStan or Psalm checks
  each mutant, before its tests where that saves time, as `staticCheck.before`
  has it by default
  ([ADR-0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md),
  [ADR-0077](../decisions/0077-a-mutant-is-checked-before-its-tests-by-default.md)).
- **Coverage is measured again only for the tests whose inputs moved**, and
  the map is kept beside the ledger
  ([ADR-0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md)).
- **A sampled mode for huge repositories** judges a sample of each tree's
  units and its floor on the confidence interval of the estimate; new code and
  every tree at a floor of 100 are never sampled. Under `sampling.auto`, a
  pull request that reaches more than the threshold beyond its own lines
  samples by itself, and its plan says why
  ([ADR-0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md),
  [ADR-0086](../decisions/0086-a-pull-request-can-sample-by-itself-above-a-threshold.md)).
- **Locally, subsumed mutants run last**: the ledger learns which killed
  mutant subsumes which, and a local run under a budget shows a subsumed
  mutant's kill as inferred, never as a proof
  ([ADR-0079](../decisions/0079-a-local-run-infers-what-a-killed-mutant-subsumes.md)).

## Running each mutant for less

- **Likely killers first**: each mutant's tests run in the order of which of
  them killed it before, so a kill comes sooner
  ([ADR-0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)).
- **Limits from each mutant's own tests**: a mutant is allowed a multiple of
  its covering tests' time, not the whole suite's, and, under `pest.patch`
  and the native runners, each of its tests a multiple of its own; `timeouts.seconds`, `timeouts.multiple` and
  `shards.max` can each be learned from earlier runs
  ([ADR-0090](../decisions/0090-each-test-of-a-mutants-run-is-held-to-a-multiple-of-its-own-time.md),
  [ADR-0073](../decisions/0073-a-limit-can-be-learned-from-what-earlier-runs-measured.md)).
- **Warm workers**: the PHPUnit runner, and Pest under `pest.patch`, fork each
  mutant's run from a worker per process, which boots the autoloader and the
  bootstrap once
  ([ADR-0070](../decisions/0070-the-verification-tail-forks-from-warm-workers.md)).
- **One schema per file** under Pest: each file's mutants are compiled into
  one schema, every mutant a switch, so a worker compiles the file once
  ([ADR-0091](../decisions/0091-a-files-mutants-are-compiled-into-one-schema.md)).
- **Every core busy**: a shard runs its work as a pool over the cores its
  process is allowed.

## Spreading the work

- **Shards cut by learned cost**: the plan cuts the units into shards of
  about the same expected cost, from how long each file took in earlier
  shards, and every build of a branch learns from the others
  ([ADR-0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md),
  [ADR-0031](../decisions/0031-builds-of-a-branch-learn-from-each-others-timings.md)).
- **A shard count from a target wall time**, `shards.target`, which the plan
  warns about where it cannot meet it.
- **Every CI**: the plan is rendered for GitHub Actions, GitLab CI,
  Bitbucket Pipelines, Azure DevOps, Buildkite, CircleCI and Jenkins, or as
  JSON for any other ([running the gate in CI](../using/ci/README.md)).
- **Survivors first**: a new push to a pull request runs the last run's
  survivors again in a job of its own beside its shards, an early signal the
  verdict does not judge
  ([ADR-0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md)).
- **A doomed pull request stops early**: once a confirmed survivor leaves a
  run no way to pass, `run.failFast` decides whether its shard fails at once
  or after judging every unit
  ([ADR-0103](../decisions/0103-a-doomed-shard-fails-fast.md)).
- **Merge queues**: a `merge_group` run is change-scoped from its base and
  carries from the default branch's ledger, trusting no pull request's own
  proofs. Under `ci.trustMergedPullRequests`, off by default, a group whose
  tree is the one its pull request passed on reaches nothing
  ([ADR-0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md)).

## What a run saved

Every run says what it saved against a full run in one job: what reach and
proofs spared, and what sharding saved in waiting and cost in runner time
([ADR-0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md)).
Locally, `serve` keeps the gate loaded between runs
([local use](../using/local.md)).

## The benchmark

`bench.yml` runs the gate against plain Infection and plain Pest on four
pinned open-source projects: a cold full run, a warm full run, a replay of
each project's recent pull requests and an unchanged re-run, every arm three
times on one GitHub-hosted machine, and the gate sharded across machines.
Every scenario is shown, those where the gate is slower too. `composer bench
-- <project>` runs the same harness locally, and the
[README](../../README.md#performance) shows the newest committed result
([ADR-0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md)).
