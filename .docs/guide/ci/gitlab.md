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
      later=""
      case "$CI_PIPELINE_SOURCE" in
        merge_request_event)
          base="--changed-since=$CI_MERGE_REQUEST_DIFF_BASE_SHA"
          later="--deliver-later" ;;
        schedule) base="" ;;
        *) base="--changed-since=last-passed" ;;
      esac
      vendor/bin/mutation-gate plan --ci=gitlab $base $later
  artifacts:
    paths: [.mutation-gate/]

mutation:
  stage: test
  needs: [mutation-plan]
  variables:
    PARENT_PIPELINE_ID: $CI_PIPELINE_ID
    MUTATION_GATE_SOURCE: $CI_PIPELINE_SOURCE
    MUTATION_GATE_MERGE_REQUEST_IID: $CI_MERGE_REQUEST_IID
  trigger:
    include:
      - artifact: .mutation-gate/pipeline.yml
        job: mutation-plan
    strategy: mirror

mutation-comment:
  stage: test
  extends: .mutation-gate-comment
  needs: [mutation-plan]
  rules:
    - if: $CI_PIPELINE_SOURCE == "merge_request_event"
  variables:
    MUTATION_GATE_DELIVERY: .mutation-gate/delivery/planned
```

The child pipeline holds one job with `parallel: matrix` over the shards and
a verdict that runs even after a failed shard, and the `mutation` trigger job
takes its result. Keep `.mutation-gate/ledger` in a `cache:` keyed by branch,
such as `key: mutation-gate-ledger-$CI_COMMIT_REF_SLUG`.

That cache is safe only while the project's CI/CD setting *Use separate
caches for protected branches* stays on, as it is by default. With it on, a
protected branch's pipelines keep a cache apart from every other pipeline's
under the same key, so a merge request can neither write nor read the default
branch's ledger. With it off, a branch can edit the pipeline to use the
default branch's key, and the next default-branch run reads what that branch
wrote.

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

## The merge request note

List `gitlab-comment` in the config's `reports`, and the gate keeps one note on
each merge request, updated in place on every run: its planned state once the
plan is made, and the verdict once the shards are judged
([ADR-0024](../../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md)).
Set `MUTATION_GATE_GITLAB_TOKEN` to a project access token with the scope `api`
and the role Reporter, as a masked CI/CD variable that is not protected, since
a merge request's pipeline runs on its own branch.

No job that runs the project's code holds the token. On a merge request the
plan and the verdict that holds no keys run with `--deliver-later`, and leave
the note in `.mutation-gate/delivery`. Two jobs send it with `deliver`: the
`mutation-comment` job above, after the plan, and `mutation-gate-comment` in
the child pipeline, after the verdict. Both extend `.mutation-gate-comment`,
the hidden job the template defines with the gate's own image, pinned to the
release `init` names, so they load none of the project's code. The trigger job
hands the child pipeline the merge request's IID in
`MUTATION_GATE_MERGE_REQUEST_IID`, since GitLab gives a child pipeline none of
the merge request's variables. Every job that extends `.mutation-gate` drops
the token before `composer install`, and the runners withhold it from the
tests. A branch can edit the pipeline, so anyone who may push one can read the
token: give it no role above Reporter.

A fork's merge request pipeline runs in the fork, with none of the project's
variables, so it writes no note and says why. GitLab finds mentions and issue
references in the rendered note, so each `@`, `#`, `!` and `~` the project
wrote stands in an element of its own
([ADR-0048](../../decisions/0048-the-gitlab-and-bitbucket-comments-relay-the-delivery-and-bitbucket-finds-its-own-by-editing.md)).
The `gitlab` report puts the survivors in the merge request's Code Quality
widget.
