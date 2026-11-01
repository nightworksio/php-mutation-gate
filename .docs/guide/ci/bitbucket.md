# Bitbucket Pipelines

`init --ci=bitbucket` prints the pipelines to add to `bitbucket-pipelines.yml`:
the default branch's, every pull request's and the custom pipeline
`mutation-full`'s. A config it writes sets `ci.defaultBranch`, since Bitbucket
names no default branch, and where a config is kept without it, `init` says to
set it. Bitbucket's parallel steps are fixed in the file, so the plan
cuts exactly as many shards with `--shards`, and each step reads its shard
from `BITBUCKET_PARALLEL_STEP`, which counts from 0. A pull request is named
by `BITBUCKET_PR_ID`. Schedule the custom pipeline `mutation-full` on the
default branch twice a week for the full run.

Bitbucket's caches are shared by every branch, so the ledger lives in S3. Its
keys, `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`, are variables of the
deployment environment `mutation-gate-store`, and only a step that deploys to
it sees them. The verdicts of the default branch's pipeline and of
`mutation-full` deploy to it and write the ledger. Restrict the environment's
deployments to the default branch, which takes Bitbucket Premium: without that,
any branch whose pipeline names the environment gets the keys. Repository and
workspace variables, secured or not, reach every branch's pipeline, so they
cannot hold the keys.

Bitbucket runs a deployment only as an ordinary step, never as the `final` step
that runs after a failed one. On the default branch, a failed shard therefore
skips the verdict: the pipeline is red, with no *cannot judge* report. While
one pipeline deploys to `mutation-gate-store`, Bitbucket pauses any other at
its verdict; once the first ends, resume the paused pipeline or rerun it. A
pull request's verdict holds no keys. Every step but the deploying verdict, the
plan on the default branch included, reads the default branch's ledger through
`proofs.store.with.publicUrl`, so set it as [proofs and trust](../concepts/proofs-and-trust.md#pull-requests-from-forks)
describes. A pull request
from a fork starts no pipeline. The gate withholds `BITBUCKET_STEP_OIDC_TOKEN`
from the tests.

## The pull request comment and Code Insights

List `bitbucket-comment` in the config's `reports`, and the gate keeps one
comment on each pull request, updated in place on every run: its planned state
once the plan is made, and the verdict once the shards are judged
([ADR-0024](../../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md)).
Hold a repository access token with `pullrequest:write` alone in the secured
repository variable `MUTATION_GATE_BITBUCKET_TOKEN`.

Repository variables reach every step, so every step that runs the project's
code drops the token before `composer install`, and the runners withhold it
from the tests. On a pull request the plan and the verdict run with
`--deliver-later` and leave the comment in `.mutation-gate/delivery`. Two
`mutation: comment` steps send it with `deliver`, from the gate's own image,
pinned to the release `init` names, so they load none of the project's code:
one after the plan, and the pipeline's `final` step, which runs whatever the
shards did. Bitbucket allows one `final` step, so the verdict is an ordinary
step before it; after a failed shard Bitbucket skips the verdict, the pipeline
is red, and the comment keeps its planned state. A branch can edit the
pipeline, so anyone who may push one can read the token, as with every
repository variable.

A repository access token cannot ask Bitbucket who it is, so the comment is the
first that holds the marker and that the token may edit, oldest first, at most
ten tried; where none is, a new one is posted. Bitbucket shows raw HTML as
text, so the marker is a link reference definition, which shows nothing, a
folded block is shown as its summary in bold, and inline code as a code span
([ADR-0048](../../decisions/0048-the-gitlab-and-bitbucket-comments-relay-the-delivery-and-bitbucket-finds-its-own-by-editing.md)).

List `bitbucket-insights` too, and the verdict writes a Code Insights report on
the commit, with each mutant counted as not killed as an annotation on its line,
at most 1,000. The verdict writes it through the pipeline's own proxy, which
holds no token, so it needs no credential, and a pull request's report shows on
the pull request.

With Premium, a dynamic pipeline sizes the parallel group to the plan instead.
The plan step runs `plan` without `--shards`, so the cost model picks the
count, then writes a pipeline with one step per shard of
`.mutation-gate/plan.json`, each running
`vendor/bin/mutation-gate run --plan=.mutation-gate/plan.json --shard=<id>`,
and the verdict after them, and uploads it with Atlassian's pipe:

```yaml
- step:
    name: 'mutation: plan'
    script:
      - composer install --no-interaction --no-progress
      - vendor/bin/mutation-gate plan --changed-since=last-passed
      - <your script that writes generated-pipeline.yml from .mutation-gate/plan.json>
      - pipe: atlassian/bitbucket-upload-generated-pipeline:1.0.0
        variables:
          GENERATED_PIPELINE_FILE: generated-pipeline.yml
```
