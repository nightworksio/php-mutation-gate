# ADR-0103: A doomed shard fails fast

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): what a pull request's shard does once a survivor leaves its run no way to pass

## Context

A survivor of a floor of 100 makes a pull request's run certain to fail
(ADR-0008). Until now a shard only acted on that under a setting that
stopped it, off by default. Every other shard passed, and only the verdict
failed, after every shard ended. A reader saw green shards for a run that
could not pass, and a run that could stop early spent its runner minutes to
the end.

## Decision

1. **`run.failFast` says what a doomed shard does:** `off`, `mark`, `stop`
   or `cancel`.
   - `off`: nothing. The shard passes, and the verdict alone fails.
   - `mark`, the default: the shard notes the first survivor that dooms its
     run, once flaky confirmation and static analysis are through with it,
     and judges every unit, so the verdict names every survivor. Then its job
     fails, saying the survivor and that it judged every unit.
   - `stop`: the shard stops after the chunk that found the survivor, its
     other units unjudged, and its job fails.
   - `cancel`: as `stop`, and the CI cancels the run's other shards where it
     can.

2. **What dooms a run is what ADR-0008 says.** Only a pull request's or a
   merge group's run whose verdict holds its trees watches for one; a run of
   the security mutators or one suite alone, a run on a branch, a tag or a
   schedule, and a run whose trees or baseline cannot be read never do.

3. **`cancel` uses the CI's own matrix, never a token.** In the reusable
   workflow the plan job outputs the resolved `run.failFast`, and the shard
   matrix's `strategy.fail-fast` is on where it is `cancel` and the run is a
   pull request's or a merge group's. The doomed shard's job fails after its
   result is uploaded, and GitHub cancels the others. The verdict then reads
   the cancelled shards' units as stopped, as it does for `stop`. Elsewhere
   `cancel` acts as `stop`.

4. **The action reads the config as the run resolves it.** Each step that
   reads the effective config reads it with `--resolved`, so a context
   block, such as `contexts.pr.run.failFast`, decides what the action does as
   it decides what the gate does.

5. **A marking run says what stopping would have saved.** Each doomed
   shard records how far into its mutating it found its survivor. Where a
   shard judged on past one, the verdict warns how many runner minutes
   `stop` would have saved, each doomed shard's mutating after its survivor,
   and `cancel`, every shard's after the first survivor found.

6. **The gate's own run cancels on pull requests**, with
   `contexts.pr.run.failFast: cancel`.

## Alternatives considered

- **A switch to stop beside a switch to fail.** Two keys would say one thing
  in two places, and some of their four combinations would mean nothing.
- **Cancel the other shards through the CI's API.** The shard job runs the
  pull request's code, and a token that cancels runs would then be within its
  reach (ADR-0007). The matrix's own fail-fast needs none.
- **Fail a marking shard at the moment it finds its survivor.** A CI job
  turns red only when it ends; a notice mid-run would cost an API call per
  shard and show nothing a reader can act on sooner.
- **`stop` by default.** A pull request with several survivors would need a
  round trip through CI for each (ADR-0008).

## Consequences

**A pull request that cannot pass shows red shards.** Under the default
each doomed shard fails once it has judged every unit, so the checks say
which shards hold a survivor that dooms the run, and the verdict still names
every survivor.

**A project chooses how many runner minutes a doomed run spends.** `stop`
and `cancel` end it early; the warning on a marking run says what each would
have saved.

**A cancelled shard leaves no result.** The verdict reads its units as
stopped, as it reads those a stopping shard left, so a doomed run's verdict
names the survivor that doomed it and leaves those units unjudged.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): no token in a job that runs the project's code
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): what dooms a run
- [ADR-0071](0071-a-config-holds-a-block-for-each-context-a-run-is-in.md): context blocks
