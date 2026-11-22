# ADR-0330: A crashed share leaves its mutants unjudged and voids no other

**Status:** Accepted
**Date:** 2026-11-22
**Amends:** [0190](0190-a-shard-hands-what-it-left-to-a-second-wave.md): a share whose runner crashed
**Amends:** [0301](0301-no-second-wave-once-the-shards-decide-the-verdict.md): a crashed shard does not decide the verdict

## Context

On the gate's second full run on main (run 45040003552), Pest's mutation
run in leftover 20.3 died after 267 s, with 702 of its 920 mutants judged
by its progress. The share's result held only "Pest's mutation run failed",
its job succeeded, and the verdict could not judge the run: it exited 2 and
wrote no report and no ledger. About 30,000 judgements of the other 90
jobs were lost to the next run.

A lost job already only leaves its mutants unjudged (ADR-0190). A runner
that crashed inside a job that went on to succeed voided the whole run.

## Decision

1. **A crash is told apart.** Pest's mutation run that fails after the
   plugin wrote every mutant it planned, other than by the issues of its
   opening run, crashed: its own process out of the memory cap, or failed
   otherwise. Its result keeps the reason it gave before and says it
   crashed (`"crashed"` beside `cannotJudge`, true or false). A run that failed
   before it made its mutants, an opening run's issues, records that do not
   add up and every other reason a runner cannot judge stay as they were:
   the verdict cannot judge the run.
2. **A crashed share's mutants stay unjudged.** Each mutant a crashed share
   of the second wave lists stands as its shard left it, unjudged, for the
   reason "Share N of the mutants shard M left crashed before it judged
   this mutant." The other shares' results stand.
3. **A crashed shard's units stay unjudged.** A shard of the first wave
   whose runner crashed left no mutant: each of its units is unjudged, as a
   unit its budget ran out before is, and the other shards' results stand.
   No second wave is cut from it, since nothing says which mutants it left.
4. **The verdict says which job crashed.** It warns "Share N of the mutants
   shard M (label) left crashed, so its K mutants are unjudged. Its runner
   said: …", or "Shard M (label) crashed, so each of its units is
   unjudged. Its runner said: …". The unjudged mutants fail the verdict as
   any unjudged mutant does, so a run with a crashed share does not pass,
   and every other job's judgements are merged, reported and delivered to
   the ledger.
5. **The job is red.** A job whose result holds a runner's cannot judge,
   crashed or not, fails with "The runner could not judge shard M in this
   job, so the job fails:" and the reason, after its result is written, as
   a doomed shard's job does (ADR-0103).

## Why no record of a crashed run is kept

Pest's plugin writes one line per mutant as each finishes, and a line cut
short at the end is no record (`Records`). A run the gate stopped at its
deadline is read that way (ADR-0300): the gate stopped a healthy process at
a time it chose. A run that ended on its own is read only once its records
add up to Pest's own summary. A crashed run has neither: the process that
wrote the records died of a cause nothing names, in a state nothing
checks, and no summary vouches for what it wrote. A kill in those records
could stand on a corrupt process's word. So none of the 702 is kept: all
920 stay unjudged.

## What changes for a verdict

Only a run with a crashed share or shard. It went from "cannot judge" (exit
2, no report, no ledger) to a failing verdict that names the crash, with
the crashed job's mutants or units unjudged and every other result kept. A
run whose runner cannot judge for any other reason is unchanged.

## Alternatives considered

- **Keep the records a crashed run wrote**: the reading a deadline stop
  gets (ADR-0300) rests on the gate having stopped a healthy process; a
  crash gives no such ground.
- **Leave every runner's cannot judge unjudged**: a setup or config fault
  fails every job alike, and the verdict saying "cannot judge" with what to
  fix is the right answer there.
- **Hand a crashed share's mutants to a third wave**: the workflow has two
  waves; a crash is rare, and the next run judges what this one left.

## Consequences

- Run 45040003552 would have failed with 920 mutants of leftover 20.3
  unjudged and the warning naming it, its report and ledger delivered.
- A crashed job shows red in the run, so it is seen where it happened.
- Pest's runner is the one that marks a crash: it runs a shard's mutants in
  one process whose death takes them all. The native PHPUnit runner runs
  each mutant in its own process, so a crash there is that mutant's outcome.
