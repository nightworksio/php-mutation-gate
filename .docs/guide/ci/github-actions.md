# GitHub Actions

> **The workflows `init --ci=github` writes do not run yet.** They use this
> repository as a GitHub Action (`--single`) or call its reusable workflow
> (`--sharded`), and neither is on `main`. Run the three commands of
> [any other CI](other.md) in a workflow of your own instead.

## Sharding with a matrix

`plan --ci=github` appends two step outputs to the file `GITHUB_OUTPUT`
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
verdict reports under `ci.check`, `mutation / verdict` by default.

## Proofs

On GitHub, cache scoping keeps a pull request from writing what the default
branch reads, so the Actions cache can keep the ledger. A store in S3, Cloud
Storage or Azure Blob Storage needs credentials only the default branch's
runs hold: see [proofs and trust](../concepts/proofs-and-trust.md).
