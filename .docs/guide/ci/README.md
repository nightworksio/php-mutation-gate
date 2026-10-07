# Running the gate in CI

A CI run has three steps:

- **plan** works out what to mutate and cuts it into shards;
- **run** mutates one shard, once per shard and in parallel;
- **verdict** merges every shard's result and judges the floors. It is the
  check to require before a merge.

A proof ledger lets each step skip what an earlier run already proved. Where
it is kept, and who may write it, is in
[proofs and trust](../concepts/proofs-and-trust.md).

`vendor/bin/mutation-gate init --ci` writes the definition for the CI it
detects, or for the one you name:

| CI | `init` option | Page |
|----|---------------|------|
| GitHub Actions | `--ci=github` | [github-actions.md](github-actions.md) |
| GitLab CI | `--ci=gitlab` | [gitlab.md](gitlab.md) |
| Buildkite | `--ci=buildkite` | [buildkite.md](buildkite.md) |
| CircleCI | `--ci=circleci` | [circleci.md](circleci.md) |
| Azure DevOps | `--ci=azure` | [azure-devops.md](azure-devops.md) |
| Bitbucket Pipelines | `--ci=bitbucket` | [bitbucket.md](bitbucket.md) |
| Jenkins | `--ci=jenkins` | [jenkins.md](jenkins.md) |
| Anything else | `plan --ci=json` | [other.md](other.md) |

The package's own CI checks each definition `init` writes: the GitHub ones
with `actionlint`, the YAML ones against their provider's published JSON
Schema, and the Jenkinsfile, which has no schema, with a snapshot test.

## What every setup needs

- **A scheduled full run.** Run the gate on the default branch with no
  `--changed-since` at least once a week, on every CI. It catches what a
  change's reach cannot see
  ([ADR-0005](../../decisions/0005-what-a-change-reaches-is-what-is-mutated.md)).
  On GitHub Actions, run it twice a week: GitHub evicts a cache entry nothing
  restores for seven days
  ([ADR-0013](../../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)).
- **The Pest patches, for sharded Pest runs.** Set `pest.patch: true`, and add
  `@php vendor/bin/mutation-gate pest:patch` to `post-install-cmd` and
  `post-update-cmd` in `composer.json`. Every shard then reuses the planning
  job's coverage instead of running the whole suite again, and each mutant is
  allowed the time its own covering tests take, not the whole suite's.
- **The Infection patch, with Infection as the runner.** Add
  `@php vendor/bin/mutation-gate infection:patch` to `post-install-cmd` and
  `post-update-cmd`, so each mutant gets the gate's limit, with its
  `timeouts.seconds` floor, and its silence limit, and a test that stats a
  dangling link or an unreadable file reads it as PHP does while
  Infection's include-interceptor serves the mutant. It patches only the
  Infection releases the gate supports. Unpatched, each mutant keeps
  Infection's own limit, and every run says so in its report.
