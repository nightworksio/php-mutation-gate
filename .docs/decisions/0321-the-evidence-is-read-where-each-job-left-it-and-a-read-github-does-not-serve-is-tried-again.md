# ADR-0321: The evidence is read where each job left it, and a read GitHub does not serve is tried again

**Status:** Accepted
**Date:** 2026-11-21
**Amends:** [0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): how the artifact `evidence` is gathered from each job's evidence

## Context

Every job leaves its evidence as an artifact `evidence-<job>`, and the
composite action `.github/actions/evidence` gathers them into the one
artifact `evidence` the bot reads (ADR-0019). It merged the run's
`evidence-*` into `attempt-<attempt>-evidence` with `upload-artifact/merge`,
deleted the merged ones, and downloaded the merged artifact by its name to lay
it over an earlier attempt's `evidence`.

GitHub lists an artifact before it serves it. In run 44895804699 the merged
artifact was finalized at 12:14:51.0 and its download by name failed at
12:14:52.2 with "Artifact not found for name: attempt-1-evidence"; in run
44899966712 the same happened 1.4 s after finalizing. A merge that lists an
artifact a job finalized a moment earlier fails in the same way, with "No
artifacts found for ID". The job then failed, and since the merge had deleted
every `evidence-*`, running it again found nothing to gather: attempts 2 and 3
of run 44899966712 failed with "No job of this run left evidence".

## Decision

1. **Each job's evidence is read where it lies.** The action downloads
   `evidence-*`, the newest artifact of each name, into one directory, and
   lays it over an earlier attempt's `evidence`. Nothing is merged into an
   artifact of its own or deleted, so a job run again replaces its own
   evidence by name, and the action run again gathers every job's.
2. **A read GitHub does not serve is tried again.** A download of `evidence-*`
   that fails is tried again after 5 s, 10 s and 20 s, each into the same
   directory; the fourth that fails fails the job with GitHub's own message.
   A download that finds no `evidence-*` succeeds with nothing, so only a
   listed artifact that is not served is tried again.

## Alternatives considered

- **Keep the merge and retry it and its download**: a merge that uploaded and
  then failed to delete cannot be tried again under the same name, and the
  download of what it uploaded is the read that races.
- **Download through the REST API in the overlay script, retried in Python**:
  every caller's job would need `actions: read` and the token in a script
  step.
- **Retry once without waiting, as the reusable workflow's downloads are
  (ADR-0072)**: the failures above lasted over a second.

## Consequences

- The jobs that gather evidence in `ci.yml`, `pr.yml`, `local-mode.yml` and
  `canary.yml` no longer fail on an artifact a job finalized a moment
  earlier, unless GitHub still does not serve it after 35 s.
- Each `evidence-<job>` is kept for its own retention, 7 days, beside
  `evidence`.
