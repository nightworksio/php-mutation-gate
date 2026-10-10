# GitHub Actions

The repository is also a GitHub Action. Pin it to a full commit SHA, with its
tag in a comment. Each release has its own tag, such as `v0.1.0`, and a
release workflow moves the tag of its line, `v0.1`, to each new release of
0.1.

## One action in two jobs, for most projects

In the first job the action sets up PHP, installs your dependencies, keeps the
proof ledger in the Actions cache and runs the whole gate. It writes line
annotations and the step summary, and holds no token once your code runs. In
the second job, with `deliver: 'true'`, it runs none of your code. It posts
the sticky PR comment and, on a trusted run, writes the ledger to a store.

```yaml
name: mutation

on:
  pull_request:
  push:
    branches: [main] # your default branch
  schedule:
    - cron: '0 3 * * 1,4'

permissions:
  contents: read

jobs:
  mutation:
    name: mutation / verdict # the check ci.check names by default
    runs-on: ubuntu-latest
    permissions:
      contents: read
      actions: read
    steps:
      - uses: actions/checkout@<sha> # <tag>
        with:
          fetch-depth: 0
          persist-credentials: false
      - uses: nightworksio/php-mutation-gate@<sha> # v0.1.0
        with:
          php-version: '8.5'

  # Sends what the run left: the pull request comment, and on a trusted run
  # the ledger a store keeps. It checks nothing out and runs none of the
  # project's code.
  deliver:
    needs: mutation
    if: ${{ !cancelled() }}
    runs-on: ubuntu-latest
    permissions:
      contents: read
      pull-requests: write
      id-token: write # only for a store that signs in through OIDC
    steps:
      - uses: nightworksio/php-mutation-gate@<sha> # v0.1.0
        with:
          deliver: 'true'
          php-version: '8.5'
```

| Input | Default |
|-------|---------|
| `config` | the config file the gate finds |
| `php-version` | `8.5` |
| `runner` | the config's, or the one installed |
| `shard` | none: the whole gate runs |
| `mode` | `auto`: change-scoped on pull requests and pushes, full on schedules, manual runs, releases and tags; or `full`, or `changed` |
| `changed-since` | the pull request's base on `pull_request`, the default branch on any other branch, `last-passed` on the default branch; used when the mode is change-scoped |
| `budget` | none |
| `reports` | none; `<name>:<path>` lines, such as `sarif:build/mutation.sarif` |
| `cache` | `true`: keep the ledger in the Actions cache |
| `deliver` | `false`; `true` in a job of its own, after the run's, to send what the run left |

Its outputs are `verdict` (`passed`, `failed` or `cannot-judge`), `scores`
(JSON), `report-paths` (JSON) and `plan` (JSON). The one-step action writes
the badge and trend to `.mutation-gate/publish` but does not publish them,
because that needs `contents: write` in a job that also runs on pull requests.
The reusable workflow publishes them.

The run's job runs the project's tests, so it holds no token and no secret:
any step after the tests can be changed by them. What needs a credential, the
pull request comment and the ledger a store keeps, it leaves as the artifact
`mutation-gate-delivery`. The `deliver` job sends it with the job's token,
running the gate from its own copy at the action's path, installed from the
gate's own lock with no scripts or plugins, never from the project's `vendor`.
The directory store is the Actions cache, which the run's job keeps itself, so
a project that keeps its ledger there needs only that token.

For an S3 store, add `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` as secrets
of the environment `mutation-gate-store`, whose deployment branches are the
default branch alone, name the store in the repository variables
`MUTATION_GATE_STORE` and its location, as for the reusable workflow below, and
hand them to the `deliver` job's step. Azure and GCS sign in through GitHub's
OIDC instead, from the repository variables `AZURE_TENANT_ID` and
`AZURE_CLIENT_ID`, or `MUTATION_GATE_GCS_PROVIDER` and
`MUTATION_GATE_GCS_SERVICE_ACCOUNT`. The job `init --ci=github --single`
writes enters that environment on a push, a schedule or a manual run of the
default branch, and none on any other run, so a pull request's run reads the
store through `proofs.store.with.publicUrl`. A branch can edit the workflow,
so the environment's restriction, not the job's condition, keeps the keys from
other branches.

## Sharded, for large projects

The reusable workflow runs a `plan` job, one `shard` job per shard and a
`verdict` job. On a pull request, a shard that stops once its run cannot
pass fails its job, and the matrix cancels the other shards; the verdict
still runs and fails, naming the survivor. A last job, `publish`, runs on the default branch only and
publishes the badge and trend.

```yaml
name: mutation

on:
  pull_request:
  push:
    branches: [main] # your default branch
  schedule:
    - cron: '0 3 * * 1,4'

permissions:
  contents: read

jobs:
  mutation:
    uses: nightworksio/php-mutation-gate/.github/workflows/mutation-gate.yml@<sha> # v0.1.0
    permissions:
      contents: write        # used only by the default-branch publish job
      actions: read
      pull-requests: write
      id-token: write        # used only by fetch and deliver, for a store that signs in through OIDC
    with:
      php-version: '8.5'
```

It takes the action's inputs less `shard`. Its outputs are `verdict`, `scores`
and `plan`, and it uploads the reports as the artifact `mutation-gate-reports`.

The `plan`, `shard` and `verdict` jobs run the project's own code, so they hold
no secret and no token that can write. Whatever needs a credential runs in a
job that installs none of the project's code and runs the gate from its own
installation, at the workflow's commit:

- `fetch` reads the default branch's ledger from the proof store, and hands it
  to the plan and the verdict as the artifact `mutation-gate-fetched`, which
  they read where the `directory` store keeps it, so the project's config
  keeps `proofs.store` at `directory`. It runs
  where the repository variable `MUTATION_GATE_STORE` names a store, in the
  environment `mutation-gate-read`.
- `deliver-plan` posts the pull request comment's planned state, which the plan
  leaves as the artifact `mutation-gate-delivery-plan`.
- On a pull request, `survivors` runs the last run's survivors again beside
  the shards, and `deliver-survivors` writes the comment's *survivors
  re-checked* state over its planned one, from the artifact
  `mutation-gate-delivery-survivors`. The verdict judges nothing it found.
- `deliver` sends what the verdict leaves as the artifact
  `mutation-gate-delivery`. On every run it posts the comment. A trusted run, a
  push, schedule or dispatch on the default branch, enters the environment
  `mutation-gate-store`, and there it also writes the ledger, sends the alerts
  and exports the trace. GitHub refuses that environment to a run on any other
  branch, even one whose workflow names it.

To keep the ledger in S3, R2 or MinIO, set these under Settings:

1. **Repository variables**: `MUTATION_GATE_STORE` set to `s3`, and the
   store's location, `MUTATION_GATE_STORE_BUCKET`,
   `MUTATION_GATE_STORE_PREFIX`, `MUTATION_GATE_STORE_REGION` and, for R2 or
   MinIO, `MUTATION_GATE_STORE_ENDPOINT`, an `https://` URL. A location is no
   secret. The two jobs read it from these variables alone, never from a run
   of the project's code.
2. **The environment `mutation-gate-read`**, with no deployment branch rule and
   the secrets `MUTATION_GATE_READ_AWS_ACCESS_KEY_ID` and
   `MUTATION_GATE_READ_AWS_SECRET_ACCESS_KEY`. Their key may only read the
   default branch's ledger: `s3:GetObject` on
   `<bucket>/<prefix>/refs/heads/<default branch>/*`. Every run holds it, a
   pull request's among them. A fork's pull request is given no secret, and
   reads through `proofs.store.with.publicUrl` instead.
3. **The environment `mutation-gate-store`**: under Deployment branches and
   tags choose Selected branches and tags, and add the default branch's name
   as its one rule. Its secrets are `AWS_ACCESS_KEY_ID`,
   `AWS_SECRET_ACCESS_KEY` and, for temporary keys, `AWS_SESSION_TOKEN`,
   whose key may read and write the default branch's ledger alone:
   `s3:GetObject` and `s3:PutObject` on
   `<bucket>/<prefix>/refs/heads/<default branch>/*`. For alerts and traces,
   add `MUTATION_GATE_SLACK_URL`, `MUTATION_GATE_DISCORD_URL`,
   `MUTATION_GATE_WEBHOOK_URL`, `MUTATION_GATE_WEBHOOK_SECRET`,
   `OTEL_EXPORTER_OTLP_ENDPOINT` and `OTEL_EXPORTER_OTLP_HEADERS` there too.

Add the secrets to the environments, never to the repository: a repository
secret reaches a run on any branch.

A public repository may read the ledger without a key instead: make the
default branch's prefix public in the bucket policy, set
`proofs.store.with.publicUrl`, and give `mutation-gate-read` no secrets. The
plan and the verdict then read the ledger through that URL, and `fetch` hands
over none.

GitHub may give a scheduled run no default branch name. Set the repository
variable `MUTATION_GATE_DEFAULT_BRANCH` to that name, and a scheduled run reads
it there. Without either, a scheduled run holds no secrets and says so in the
deliver job's summary.

The reusable workflow reaches S3 with those key secrets only. To assume an AWS
role through OIDC instead, use the action's two jobs: the `deliver` job already
has `id-token: write`, so add `aws-actions/configure-aws-credentials` before
the action there, and trust the role as described above: an environment only the
default branch may deploy to, or the job's `job_workflow_ref`.

## Sharding with a matrix of your own

In a workflow of your own, `plan --ci=github` appends two step outputs to the file `GITHUB_OUTPUT`
names:

- `shards`, a JSON array of `{id, label}`, one per shard, which a matrix
  reads with `fromJson(needs.plan.outputs.shards)`;
- `plan`, the plan on one line.

An empty array skips the matrix job, and the verdict still runs. A matrix
runs at most 256 jobs, so a plan with more shards cannot be judged; set
`shards.max` to 256 or less.

Run the verdict even when a shard job failed (`if: always()`), so a missing
result is judged *cannot judge* (exit code 2) rather than skipped. Check out
with `fetch-depth: 0`: a kill proved at an earlier commit carries only where
the clone holds that commit.

## What the gate writes under GitHub Actions

These reporters run on their own under GitHub Actions
([reports](../../reference/reports.md)):

- **`github-annotations`**: line annotations, changed lines first.
- **`github-summary`**: the step summary, with every mutant counted as not
  killed.
- **`github-comment`**: one sticky comment on the pull request, updated in
  place. It needs `GITHUB_TOKEN` and `pull-requests: write`.
- **`badge`**: on the default branch, `badge.json`, `trend.json`, `trend.svg`
  and `savings.json` in `--publish-dir` (`.mutation-gate/publish` by
  default). Publishing that directory is up to your workflow. Published to a
  branch named `mutation-gate`, the badge reads:

  ```markdown
  ![mutation score](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/<owner>/<repo>/mutation-gate/badge.json)
  ```

To see survivors in code scanning, add a `sarif` report and upload it with
`github/codeql-action/upload-sarif`.

## Branch protection

Require the verdict's check in the default branch's protection rule. The
verdict reports under `ci.check`, `mutation / verdict` by default: the
action's first job above is named so, and the reusable workflow's `verdict` job
shows as `<calling job> / verdict`. It is the check through which a merged
pull request's verdict proves its commit, so a job named otherwise needs
`ci.check` set to its name. The verdict says *cannot judge* (exit code 2)
when the plan could not judge, or when any planned shard left no result.
Run it on a schedule as well: the full run, twice a week in the examples.

## Proofs

On GitHub, cache scoping keeps a pull request from writing what the default
branch reads, so the Actions cache can keep the ledger. A store in S3, Cloud
Storage or Azure Blob Storage needs credentials only the default branch's
runs hold: see [proofs and trust](../concepts/proofs-and-trust.md).
