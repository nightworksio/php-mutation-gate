# Azure DevOps

`init --ci=azure` writes the gate's jobs to `.azure/mutation-gate.yml` and
prints the lines that take them into the pipeline Azure DevOps runs. A config
it writes names that file as `ci.azure.definition`, and sets
`ci.defaultBranch`, since Azure DevOps names no default branch. Where a config
is kept, `init` says which of the two to set:

```yaml
jobs:
  - template: '.azure/mutation-gate.yml'
```

`plan --ci=azure` sets the plan step's output variable `matrix` to one leg per
shard, `{"s1": {"SHARD": "1"}, …}`, which the `mutation` job's
`strategy: matrix` reads. A plan with no shards sets one leg, `none`, whose
empty `SHARD` runs nothing, because Azure always makes at least one job. The
verdict runs with `condition: succeededOrFailed()`, so it says *cannot judge*
even when a shard failed. The `Cache@2` task keeps the ledger keyed by the
digest of the run's scope, and restores the default branch's second where
Azure lets the run read that branch's caches: a pull request reads its
target's, and any other run reads only `main`'s and `master`'s besides its
own. A cache is saved only by a job that succeeds, so a last job, which runs
whatever the verdict decided, saves the ledger the verdict wrote. A pull
request is named by `System.PullRequest.PullRequestNumber` where Azure sets it,
as for a GitHub repository, and by its id otherwise.

For an S3 store, the keys, `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`, are
secret variables of the variable group `mutation-gate-store`, whose Branch
control check allows the default branch alone. The verdict job takes the group
in, and maps the keys into its step, only on a push, a schedule or a manual run
of the default branch. A branch can edit the template, so the check, not the
template's condition, keeps the keys from other branches. The plan runs the
whole suite, so it never holds them. It, and the verdict step where
`System.PullRequest.IsFork` is `True`,
drop every variable the S3 store reads, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`, `AWS_ROLE_ARN`,
before the gate runs: a fork's build gets no secrets, and Azure hands it a
mapped one as the literal text `$(NAME)`. Every run without the keys
reads the default branch's ledger through `proofs.store.with.publicUrl`, so set
it as [proofs and trust](../concepts/proofs-and-trust.md#pull-requests-from-forks)
describes. The gate withholds `SYSTEM_ACCESSTOKEN` and
`AZURE_DEVOPS_EXT_PAT` from the tests. Schedule the pipeline on the
default branch twice a week, with `always: true`, for the full run.
