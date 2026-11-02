# ADR-0053: CI runs the suite on Linux alone

**Status:** Accepted
**Date:** 2026-11-02
**Amends:** [0042](0042-the-gate-runs-on-php-8-4-on-linux-and-macos.md): its `macos` job, for where CI runs the suite

## Context

ADR-0042 supports Linux and macOS, and has a `macos` job run the suite on
macOS against the lock, so that a developer on macOS runs the gate on a
tested platform. The job ran on GitHub's `macos-latest` runners. On
2026-11-02, GitHub cancelled it three times, on two pull requests, after 15
to 17 minutes in the queue with no runner taking it up, so the job no longer
runs to an answer.

## Decision

1. **CI runs the suite on Linux alone.** The `macos` job, its entry in
   `.github/gates.json`, and its place among the jobs whose evidence the
   `evidence` job gathers are removed.
2. **macOS stays supported for local use, untested by CI.** The code that
   tells the two platforms apart stays, with its unit tests: the peak memory
   macOS reports in bytes, and the socket the warm worker's guard counts
   before the boot (ADR-0042, item 7). The README says that CI tests Linux.

This amends ADR-0042's item 6, whose `macos` job runs the suite on macOS.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep the job, not required** | A job GitHub cancels before it starts is red on every pull request, and no pull request merges with a red check. |
| **A self-hosted macOS runner** | A runner that runs pull requests' code and outlives the job keeps what one job left for the next (ADR-0039). |
| **Drop macOS support** | The platform-specific code and its unit tests still hold, and the gate's maintainer develops on macOS. |

## Consequences

- A change that breaks the gate on macOS alone is not caught by CI, only by
  someone running the gate on macOS.
- Every pull request's checks finish without waiting on a macOS runner.

## Related

- [ADR-0042](0042-the-gate-runs-on-php-8-4-on-linux-and-macos.md): the supported platforms, which this amends
- [ADR-0039](0039-the-jobs-that-hold-a-credential-run-on-a-runner-of-their-own.md): what a persistent runner keeps between jobs
