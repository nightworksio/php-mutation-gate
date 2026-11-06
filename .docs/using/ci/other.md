# Any other CI

```sh
vendor/bin/mutation-gate plan --ci=json            # prints the shards; writes .mutation-gate/plan.json
vendor/bin/mutation-gate run --plan=.mutation-gate/plan.json --shard=<id>   # once per shard, in parallel
vendor/bin/mutation-gate verdict --plan=.mutation-gate/plan.json --results=.mutation-gate/results
```

Check out the branch by name (`git checkout -B <branch>`) before `plan`, because
the JSON plan takes the run's ref from git's current branch, and `run` and
`verdict` take it from the plan. A plan made on a detached `HEAD` writes no
ledger and publishes nothing
([ADR-0006](../../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md)).
The JSON plan never treats a run as a pull request, so there the new-code floor
applies only in pre-push and watch. Pass `--changed-since` with a change's base,
or `last-passed` on the default branch, and leave it out of a weekly scheduled
full run. Run the verdict even when a shard job failed, so a missing result is
judged *cannot judge*. Carry `.mutation-gate/` from job to job, and keep
`.mutation-gate/ledger` between runs with whatever cache your CI has, keyed by
branch. Proofs can also live in S3, R2, Cloud Storage or Azure Blob Storage
([ADR-0007](../../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)).
To publish the badge and trend, restore the published files into
`.mutation-gate/publish` before the verdict on your default branch, and publish
that directory after it.
