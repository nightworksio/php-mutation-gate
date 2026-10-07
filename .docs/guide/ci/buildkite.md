# Buildkite

`vendor/bin/mutation-gate init --ci=buildkite` writes the pipeline, to
`ci.buildkite.definition` (`.buildkite/pipeline.yml` by default). Its plan
step uploads the rest:

```yaml
steps:
  - label: "mutation: plan"
    artifact_paths: ".mutation-gate/**/*"
    command:
      - unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN AWS_ROLE_ARN
      - composer install
      - |
        if [ "$BUILDKITE_PULL_REQUEST" != "false" ]; then base="--changed-since=origin/$BUILDKITE_PULL_REQUEST_BASE_BRANCH"
        elif [ "$BUILDKITE_SOURCE" = "schedule" ]; then base=""
        else base="--changed-since=last-passed"; fi
        vendor/bin/mutation-gate plan --ci=buildkite $base | buildkite-agent pipeline upload
```

The uploaded steps are one step per shard, a `wait` that continues on failure,
then the verdict, as two steps of which one runs. Each is built from your step
template (`ci.buildkite.step`), and they pass the plan and results with
`buildkite-agent artifact`. Schedule the pipeline weekly for the full run.

A cache plugin keyed by branch can keep `.mutation-gate/ledger` between builds,
but any branch can save a cache under any key on Buildkite, so a branch can
plant proofs the default branch then trusts. Keep the ledger in S3 with
credentials only the default branch's runs hold, and drop the cache steps. For
an S3 store, hold `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` as the
cluster secrets `MUTATION_GATE_STORE_AWS_ACCESS_KEY_ID` and
`MUTATION_GATE_STORE_AWS_SECRET_ACCESS_KEY`, whose access policy allows only
builds of the default branch, and never set them on the agents. A tag build's
branch is the tag's name, so have the policy refuse tag builds, or keep anyone
from pushing a tag named as the default branch. The verdict step
`mutation-gate-verdict-store` fetches the keys with `buildkite-agent secret
get` only on a push, a schedule, an API or a manual build of the default branch
that is neither a pull request nor a tag; its keyless twin,
`mutation-gate-verdict`, runs on every other build. A branch can edit the pipeline, so the secrets' access
policy, not the step's condition, keeps the keys from other branches. The plan
runs the whole suite, so it drops the keys before `composer install`, and
reads the store through `proofs.store.with.publicUrl`.
