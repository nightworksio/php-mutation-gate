# ADR-0109: Small CI jobs run as steps of one job

**Status:** Accepted
**Date:** 2026-11-06

## Context

Most of a small CI job's time is not its tool. A successful `hygiene` or
`security` job took 11.8 s, of which 5.4 s was set-up, checkout, upload and
evidence, and 3.3 s the tool. A leg of `runner contract` took 127 s, about
19 s of it fixed: job set-up, checkout, PHP, the root install, the cache and
the upload. Each also holds one of the account's concurrent jobs.

[ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md)
asks every CI job for structured evidence, and its evidence step reads each
step's outcome, so it does not need a job per tool.

## Decision

1. **`hygiene / tools` is one job with a step per tool:** actionlint, zizmor,
   typos, the line cap, lychee, gitleaks, osv-scanner and markdownlint. It
   replaces the eight `hygiene` and `security` jobs. A failing step leaves the
   next ones to run, and the job reports every tool's findings.
2. **`pr` is one job** for the commit subjects, the title and the body, and
   gathers its own evidence. It replaces `commitlint`, `description` and
   `pr evidence`.
3. **A runner's two legs run one after the other in one job.**
   `.github/scripts/leg.sh` gives each leg its own emptied fixture, install,
   suite, log and outcome, so `runner contracts` reads the same sixteen legs.
   A leg that fails leaves the next one to run.

## Consequences

- A required check covers several tools, and its findings and annotations say
  which one found what.
- `gates.json` holds one entry for each job, with one `reproduce` line per tool.
- Nine fewer jobs run for the tools and the pull request's text, and eight
  fewer for the legs. A run's legs take longer from end to end, off the
  critical path.
- The tools that read the tree run before npm installs markdownlint, and
  gitleaks and the other binaries stay outside the checkout.

## Alternatives

| Alternative | Why not |
|---|---|
| **Keep a job per tool** | Each pays its own set-up and holds a concurrent job for a tool that takes seconds. |
| **Run a runner's two legs in one step with a shared install** | One resolution's vendor directory and caches could reach the other's suite, so a leg would no longer prove its own resolution. |

## References

- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): every CI job leaves structured evidence
- [ADR-0055](0055-each-gated-job-runs-for-its-own-inputs-and-each-runner-leg-for-its-own-test-support.md): each leg's test support
