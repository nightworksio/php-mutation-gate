# CircleCI

CircleCI's parallelism is fixed in the config, so the plan cuts exactly that
many shards, and each `mutation` node reads its shard from `CIRCLE_NODE_INDEX`.
The ledger's cache keys use a checksum of the branch name, so no branch's key
is a prefix of another's. Each branch's cache holds only its own ledger: the
verdict job drops the default branch's copy before saving, and the plan job
restores both caches in turn.

```yaml
jobs:
  mutation-plan:
    docker: [{ image: <a PHP 8.5 image with pcov> }]
    steps:
      - checkout
      - run: |
          mkdir -p .mutation-gate
          echo "$CIRCLE_BRANCH" > .mutation-gate/branch
          echo main > .mutation-gate/default-branch
      - restore_cache:
          keys: [mutation-gate-ledger-{{ checksum ".mutation-gate/branch" }}-]
      - restore_cache:
          keys: [mutation-gate-ledger-{{ checksum ".mutation-gate/default-branch" }}-]
      - run: composer install
      - run: |
          if [ "<< pipeline.trigger_source >>" = "scheduled_pipeline" ]; then base=""
          elif [ -n "$CIRCLE_PULL_REQUEST" ]; then base="--changed-since=origin/main"
          else base="--changed-since=last-passed"; fi
          vendor/bin/mutation-gate plan --shards=4 $base
      - persist_to_workspace: { root: ., paths: [.mutation-gate] }
  mutation:
    parallelism: 4
    docker: [{ image: <a PHP 8.5 image with pcov> }]
    steps:
      - checkout
      - attach_workspace: { at: . }
      - run: composer install
      - run: vendor/bin/mutation-gate run --plan=.mutation-gate/plan.json
      - persist_to_workspace: { root: ., paths: [.mutation-gate/results] }
  mutation-verdict:
    docker: [{ image: <a PHP 8.5 image> }]
    steps:
      - checkout
      - attach_workspace: { at: . }
      - run: composer install
      - run: vendor/bin/mutation-gate verdict --plan=.mutation-gate/plan.json --results=.mutation-gate/results
      - run: '[ "$CIRCLE_BRANCH" = main ] || rm -rf .mutation-gate/ledger/refs/heads/main'
      - save_cache:
          key: mutation-gate-ledger-{{ checksum ".mutation-gate/branch" }}-{{ .Revision }}
          paths: [.mutation-gate/ledger]

workflows:
  mutation-store:
    when: &trusted
      and:
        - or:
            - equal: [webhook, << pipeline.trigger_source >>]
            - equal: [scheduled_pipeline, << pipeline.trigger_source >>]
            - equal: [api, << pipeline.trigger_source >>]
        - equal: [main, << pipeline.git.branch >>]
    jobs:
      - mutation-plan
      - mutation: { requires: [mutation-plan] }
      - mutation-verdict: { context: [mutation-gate-store], requires: [{ mutation: terminal }] }
  mutation:
    unless: *trusted
    jobs:
      - mutation-plan
      - mutation: { requires: [mutation-plan] }
      - mutation-verdict: { requires: [{ mutation: terminal }] }
```

`main` stands for your default branch. Add a weekly scheduled pipeline for the
full run. The verdict requires `mutation` with the status `terminal`, so it
runs, and says *cannot judge*, even when a shard failed.

Any branch can save a cache under any key on CircleCI, so a branch can plant
proofs the default branch then trusts. Keep the ledger in S3 with credentials
only the default branch's runs hold, and drop the cache steps. For an S3
store, set `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` in the context
`mutation-gate-store`, restricted by the expression
`pipeline.git.branch == "main"`. Only `mutation-verdict` uses it, in the
workflow `mutation-store`, which runs on a push, a schedule or an API trigger
of the default branch; every other run takes the workflow `mutation`, with no
context. A branch can edit the config, so the context's restriction, not the
workflow's condition, keeps the keys from other branches. The plan runs the
whole suite, so it never holds the keys, and reads the store through
`proofs.store.with.publicUrl`, as every other run's verdict does.
