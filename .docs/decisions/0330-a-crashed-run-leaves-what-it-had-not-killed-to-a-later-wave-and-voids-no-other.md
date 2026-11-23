# ADR-0330: A crashed run leaves what it had not killed to a later wave and voids no other

**Status:** Accepted
**Date:** 2026-11-23
**Amends:** [0190](0190-a-shard-hands-what-it-left-to-a-second-wave.md): a shard whose run crashed hands on what it left
**Amends:** [0300](0300-a-kill-left-unvouched-by-the-time-is-handed-on.md): a crash is a reason a mutant is left

## Context

Pest's mutation run can die while it judges its mutants: its own process
out of the memory cap, or ended by a signal. A run that died held only
"Pest's mutation run failed"; its job succeeded, and the verdict could not
judge the run: it wrote no report and no ledger, and every other job's
judgements were lost to the next run.

A cut that folds a wave to the pool gives every shard a part of every
unit (ADR-0303), so a shard whose units are unjudged leaves every unit of
the project unjudged.

Before it runs any mutant, the plugin writes every mutant the run made.

## Decision

1. **A crash is told apart.** Pest's mutation run that fails without
   reaching its end, after the plugin wrote every mutant it made, other
   than by the issues of its opening run, crashed. A run that failed
   before it made its mutants, an opening run's issues and records that do
   not add up stay as they were: the runner cannot judge the shard.
2. **A crashed run hands on what it had not killed.** Each mutant the run
   never told of, and each it told of as survived, is unjudged with the
   reason `crashed`, a reason a mutant is left by, like a budget's. The
   wave after cuts those mutants into shares as it cuts a stopped shard's
   (ADR-0190); a mutant no later wave judges stays unjudged, saying its run
   crashed.
3. **A kill stands and a survival does not.** A kill is a test that
   failed. A survival is a test that passed, and a child a stray signal
   ended reads as one, so a crashed run's survivals are judged again.
4. **The result warns, and the job fails.** The result carries a warning
   with what the runner said, its process's exit code and the signal where
   one ended it. The job of a shard or share whose run crashed fails,
   naming the shard; the jobs after it run, and the verdict merges, reports
   and delivers every job's judgements.
5. **The run does not pass on unjudged mutants**, as before.

## Consequences

- One mutant that ends its runner costs the wave after it a rerun of what
  that run had left, and at most the mutants of one share stay unjudged.
- A share of the last wave that crashes leaves its unkilled mutants
  unjudged; the run's next cold or warm run judges them.
- "Pest's mutation run failed" says how the process ended.
