# ADR-0106: Carried survivors are judged again, and their re-check is bounded and shared

**Status:** Accepted
**Date:** 2026-11-06
**Amends:** [0025](0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md): item 1, which results pruning carries
**Amends:** [0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): items 19 and 21, how the last run's survivors are re-checked first

## Context

Pruning carries a unit's newest result, mutant by mutant, for the mutators
it leaves out (ADR-0025). Where that result let a mutant through, the
survivor was carried too. A test written to kill it was then judged by
nothing but the survivors re-check, which is a signal and never a proof
(ADR-0020), so the verdict listed the survivor again on every push until the
unit's own content changed.

The survivors re-check runs one file at a time, and each file's run makes
every mutant of its survivors' mutators, not only the survivors. With many
survivors in large files it ran past the job's 30 minutes, was cancelled,
and said nothing at all.

## Decision

1. **Pruning never carries a result that let a mutant through.** A unit
   whose newest result holds a survivor, an uncovered mutant or an unjudged
   one runs every mutator, so the shards judge each such mutant again and a
   test added to kill it counts. The rule is per unit: a unit with one
   survivor runs whole. Survived, uncovered and unjudged are the statuses
   that let a mutant through, in one place for the pruner and the reports.

2. **The re-check spends at most `survivorsFirst.budget`.**
   - A duration, `20m` by default: two thirds of the job the reusable
     workflow gave it, which leaves room for setting up.
   - Files run in the order the survivors were taken, those on changed lines
     first. Each file's run is held to the time left, and a file the budget
     has no time left for does not run.
   - Its survivors are reported as *not re-checked*, counted apart from those
     re-checked, in the log, the step summary and the comment. The command
     still exits 0: what it found is a signal, as before.

3. **The re-check is shared among `survivorsFirst.shards` jobs.**
   - A whole number from 1 to 20, `4` by default; 20 is as many jobs as
     GitHub's free plan runs at once.
   - `survivors --jobs` prints the jobs as `{"shares":[1,2],"minutes":30}`:
     one per file of the survivors taken, at most the setting, none where it
     takes none, and the minutes each may take, its budget and ten for
     setting up. Where it cannot tell which survivors it takes, it prints one
     job, which fails saying why, and says so aside.
   - `survivors --share=<i> --of=<n>` takes every n-th file of the order
     taken, from the i-th, so every share keeps the order, re-checks them
     within the budget and leaves the files it ran, those it did not reach
     and every mutant it made in `.mutation-gate/survivors/<i>.json`.
   - `survivors --from=<dir> --of=<n>` takes the survivors again, pairs them
     by id with what the n shares left, and writes the comment and the step
     summary. A survivor whose file no share ran is not re-checked; a share
     that left no file, or one that cannot be read, ran none.
   - Without these options the command re-checks every file in one process,
     within the same budget.

4. **The reusable workflow runs the shares as a matrix.** On a pull request
   whose plan runs a shard, the plan job runs `survivors --jobs` and outputs
   the shares and the minutes. The `survivors` job runs one share per matrix
   job, its timeout the minutes, and keeps each share as the artifact
   `mutation-gate-survivors-<attempt>-<share>`. `survivors-report` runs
   `--from` over them, whatever each share's job did, and leaves the
   comment's *survivors re-checked* state for `deliver-survivors`. Neither
   holds a token or a secret, as before. This amends ADR-0020 item 21.

## Alternatives considered

- **A longer job.** It only moves the limit, and a signal that takes an
  hour is no signal.
- **Carry a survivor and judge it in the re-check alone.** The re-check is
  never a proof, so the verdict would go on listing a survivor a new test
  kills.
- **A re-check narrowed to the survivors' own mutants.** It would cut each
  file's run to the mutants it re-checks, but each runner would need to make
  mutants by id, which none of the three can.
- **A fixed number of re-check jobs.** Each job installs the project, so
  jobs for files that hold no survivor cost runner minutes for nothing; the
  plan names only as many as there are files.
- **Shares that write the comment themselves.** Several writers of one
  comment race, and a share that was cancelled would leave its survivors
  unsaid; one report job reads every share, or the lack of one.

## Consequences

**A test written for a survivor counts on the next push.** The shards run
its unit whole, so the verdict judges the test and drops the survivor it
kills.

**The re-check always says something.** It ends within its budget, reports
the survivors it reached and counts the rest, and its jobs share the files.

**A unit with survivors costs a full run until they are killed.** Pruning
saves nothing on it, which is the point: its result is what a reviewer acts
on.

## Related

- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): a run within a budget, riskiest first
- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): survivors re-checked first
- [ADR-0025](0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md): pruning
