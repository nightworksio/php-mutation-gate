# ADR-0112: A push spends only what its pull request did not

**Status:** Accepted
**Date:** 2026-11-07
**Amends:** [0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): where a schedule is set
**Amends:** [0005](0005-what-a-change-reaches-is-what-is-mutated.md): rule 5, how a merged pull request's record reaches the push
**Amends:** [0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): what a plan hands a shard
**Amends:** [0037](0037-a-value-is-covered-by-the-tests-that-read-it.md): where the reads are applied
**Amends:** [0054](0054-a-pull-request-runs-the-gates-its-change-can-move.md): item 1, which gates a push runs

## Context

A push to the default branch re-ran work its pull request had just done on the
same tree, each shard prepared the same coverage again, and a project had to
remember to add the scheduled full run that reach relies on.

## Decision

1. **`ci.schedule` is the full run's schedule.** `init --ci` writes it, weekly
   unless the config says. A reusable workflow cannot hold a trigger, so the
   schedule belongs to the workflow that calls it, and `doctor` advises one
   where none exists.

## Related

- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): what a run says it saved
