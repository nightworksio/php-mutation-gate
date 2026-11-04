# ADR-0072: Each artifact download is retried once after two minutes

**Status:** Accepted
**Date:** 2026-11-04
**Amends:** [0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the reusable workflow's jobs

## Context

Every job of the reusable workflow after `fetch` starts by downloading an
artifact an earlier job uploaded: the store's copy of the ledger, the coverage
map, the plan, the shards' results, a delivery. Each download is on its job's
critical path. In run 41856258556 the plan's 1.9 MB coverage map was
downloaded and its digest checked 11 s into the step, and the step then took
175 s more to end, with nothing left to do. Across 23 runs on the gate's own
repository the step took 0 to 2 s every other time.

## Decision

1. **Each download step of the reusable workflow has two minutes.** It carries
   `timeout-minutes: 2` and `continue-on-error: true`, and an `id` of its own.
2. **The step after it downloads the same artifact to the same path once
   more, where the first ran out of time or failed.** It is named
   `Retry downloading …`, so a run's log shows each retry, runs only where the
   first step's outcome is `failure`, and has two minutes as well.
3. **The steps are generated.** The reusable workflow is written from a
   template, `scripts/workflows/mutation-gate.yml`, by
   `scripts/workflows/generate.py`, where each download is one step. The
   generator names each retry by the artifact it downloads and refuses a
   download it has no name for. The line cap holds the template and the
   generator, not the file they write, and the `scripts` job fails where the
   file is not what they give.
4. **A retry that fails fails its job**, as the one download did before. The
   shards' results, which a verdict may find none of, keep
   `continue-on-error: true` on the retry as on the first step.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Retry only the plan's coverage map** | Every download runs the same action, and each one holds up its job where it hangs. |
| **One minute** | A coverage map of a large project is 100 MB or more, and a slow runner can take more than a minute over one that is downloading. The hang comes after the download is done, so two minutes still ends it sooner than it ended. |
| **Write the retries into the workflow by hand** | The workflow was at the line cap of 1,000 lines, and fifteen retries take it to 1,144. |
| **Split the reusable workflow into nested ones** | A caller's permissions and secrets would have to be passed one level further down. |
| **Download through the API with a timeout in the shell** | A caller's workflow would have to grant `actions: read` to every job that downloads. |

## Consequences

- A download that hangs after it is done holds its job up for two minutes and
  the retry's time, not as long as the action takes to end.
- A download that fails, as one of an artifact that is not there does, is
  tried twice before its job fails.
- `TheShippedWorkflowsHoldTest` holds each download to its two minutes and to
  a retry of the same artifact and path.

## Related

- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the reusable workflow
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the plan, and the map it is handed
