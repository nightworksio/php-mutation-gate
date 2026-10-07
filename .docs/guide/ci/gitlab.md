# GitLab CI

`parallel:matrix` has to be written before a pipeline starts, so the plan
writes a child pipeline and the parent triggers it. Define a hidden job,
`.mutation-gate`, that sets the image and installs dependencies, in the file
`ci.gitlab.template` names (`.gitlab/mutation-gate.yml` by default). The
generated jobs extend it, and so does the plan job. A merge request is judged
from its base, the default branch from the last commit that passed, and a
weekly scheduled pipeline mutates everything.

```yaml
include:
  - local: .gitlab/mutation-gate.yml

mutation-plan:
  stage: test
  extends: .mutation-gate
  script:
    - |
      case "$CI_PIPELINE_SOURCE" in
        merge_request_event) base="--changed-since=$CI_MERGE_REQUEST_DIFF_BASE_SHA" ;;
        schedule) base="" ;;
        *) base="--changed-since=last-passed" ;;
      esac
      vendor/bin/mutation-gate plan --ci=gitlab $base
  artifacts:
    paths: [.mutation-gate/]

mutation:
  stage: test
  needs: [mutation-plan]
  variables:
    PARENT_PIPELINE_ID: $CI_PIPELINE_ID
    MUTATION_GATE_SOURCE: $CI_PIPELINE_SOURCE
  trigger:
    include:
      - artifact: .mutation-gate/pipeline.yml
        job: mutation-plan
    strategy: mirror
```

The child pipeline holds one job with `parallel: matrix` over the shards and
a verdict that runs even after a failed shard, and the `mutation` trigger job
takes its result. Keep `.mutation-gate/ledger` in a `cache:` keyed by branch,
such as `key: mutation-gate-ledger-$CI_COMMIT_REF_SLUG`.

For an S3 store, set `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` as
protected, masked CI/CD variables, which only protected branches' pipelines
see, never a merge request's; keep the default branch the only protected one,
or trust everyone who may push to one. The verdict is two jobs, of which one
runs: `mutation-gate-verdict-store` on a push, a schedule or a manual run of
the default branch, which `MUTATION_GATE_SOURCE` tells the child pipeline,
since a child's own `CI_PIPELINE_SOURCE` is `parent_pipeline`; and
`mutation-gate-verdict` on every other. The hidden job's `before_script` drops
every variable the S3 store reads before `composer install` in every job but
`mutation-gate-verdict-store`, so the plan and the shards, which run the
project's tests, read the store through `proofs.store.with.publicUrl`. A
branch can edit the pipeline, so the variables' protection, not the jobs'
conditions, keeps the keys from other branches.
