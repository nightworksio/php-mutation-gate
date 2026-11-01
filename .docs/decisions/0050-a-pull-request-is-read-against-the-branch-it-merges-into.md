# ADR-0050: A pull request is read against the branch it merges into

**Status:** Accepted
**Date:** 2026-11-01

## Context

What the gate reads a pull request against, read at source:

- **The base branch in ADR-0003.** A pull request that lowers a floor has
  to carry `lowered` against the base branch's baseline.
- **The default branch in the code.** The lowered-floor check (ADR-0040's
  decision 5), the base CODEOWNERS is read at (ADR-0022), the fallback of
  `last-run` off the default branch, and the lines a pull request's new
  code is judged on where the run does not know them all read
  `origin/<default branch>`.
- **The CI templates fetch the target.** The Bitbucket, Jenkins, Azure and
  Buildkite templates fetch the branch a pull request merges into and pass
  it as `--changed-since`, and the GitHub action passes the pull request's
  base (ADR-0005). So a pull request into `release/2.x` is mutated for its
  change against `release/2.x`, but its floors, its owners and its new code
  are read against the default branch, which the checkout may not have
  fetched.
- **What each CI names.**

  | CI | The branch a pull request merges into |
  |----|---------------------------------------|
  | GitHub Actions | the event payload's `pull_request.base.ref`, and `GITHUB_BASE_REF` |
  | GitLab | `CI_MERGE_REQUEST_TARGET_BRANCH_NAME` |
  | Bitbucket Pipelines | `BITBUCKET_PR_DESTINATION_BRANCH` |
  | Azure DevOps | `SYSTEM_PULLREQUEST_TARGETBRANCH`, as `refs/heads/<branch>` |
  | Jenkins | `CHANGE_TARGET` |
  | Buildkite | `BUILDKITE_PULL_REQUEST_BASE_BRANCH` |
  | CircleCI | none |

## Decision

This amends ADR-0022's decision on the CODEOWNERS base and ADR-0040's
decision 5.

1. **A run on a pull request knows the branch it merges into**, where its
   CI names it, as the table above reads it. The plan records it as
   `target`, so every shard and the verdict read the same branch.
2. **A pull request is read against that branch,** fetched from `origin`:
   - the baseline a lowered floor is checked against, in the file that
     branch's own config names;
   - the CODEOWNERS file its survivors are routed by;
   - the lines its new code is judged on, where the run does not know them;
   - the change `last-run` falls back to.
3. **Every other run is read against the default branch,** as before, and
   so is a pull request whose CI names no branch it merges into.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **The default branch for every pull request** | A pull request into a release branch is held to another branch's floors and owners, and read against a branch its checkout may not have fetched. |
| **A config key naming the branch to read against** | One key for every pull request, where each names its own target. |
| **The merge base of `HEAD` and the target, computed by git** | The baseline and CODEOWNERS are read as the target holds them now, which is what the merge lands on. |

## Consequences

**A pull request into a release branch is judged against that branch's
floors and owners.**

**A CircleCI pull request is read against the default branch,** since
CircleCI names no target.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): the base branch's baseline, which this reads
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): the change a pull request is mutated for
- [ADR-0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md): the CODEOWNERS base, which this amends
- [ADR-0024](0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md): the CI plans
- [ADR-0040](0040-a-verdict-stands-only-on-what-it-can-stand-behind.md): the lowered-floor check, which this amends
