# ADR-0196: A job stops at its target less what it measured

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0114](0114-a-shard-is-cut-to-fit-its-job-and-stops-before-it.md): what a shard's job stops at
**Amends:** [0195](0195-a-streamed-job-is-priced-at-its-wall-time.md): how a share's second pass is priced, and its factor learned
**Amends:** [0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): a survivor the time left had no room to check

## Context

A shard's gate stopped `shards.jobLimit` after it started, eight minutes,
however long its job took to set up and however long the gate went on for
once it stopped. On the gate's own probe one shard's job took 10m12s: 53 s
of setup, a gate step of 9m11s and 8 s after it. Its gate went on for 64 s
past its stop. The mutation run was given the time left when its
invocation began, not when Pest started, so it stopped 13 s late; then
the trials of mutants on lines that are not executable read the whole
coverage map and scanned the code for 23 s, and on another shard a static
check of five survivors started 41 s before the stop and ran 90 s: neither
looked at the clock before it began. The wave that judges what shards left
priced a second pass only for the mutants the ledger named no killer of:
a share of 1,075 mutants was priced at 72 s for its second pass, which ran
for more than five minutes and left 816 of them unjudged.

## Decision

1. **A job's gate stops at its target less what it measured.** The stop
   is `shards.target`, ten minutes where unset, less the setup before the
   gate started, less the wind-down a gate takes once it stops, less
   `shards.margin`, 45 s by default; no later than `shards.jobLimit`
   after the gate started, where the config sets it, and at the limit
   alone where it sets the limit and no target. The setup is measured
   from when the job began, which the workflow's first step hands the
   gate as `job-started`, in seconds since the epoch; where it hands
   none, the longest setup recent jobs on the platform measured, or else
   `shards.setup`. The wind-down is the longest recent gates on the
   platform took past their stop, or 35 s where none was measured. The
   plan cuts its shards, and the second wave its shares, to the same stop.
2. **Nothing starts past the stop.** The runner's process is given the
   time left as it starts, not as its invocation began, and is stopped
   with every process under it at the stop; each mutant its children did
   not finish is unjudged. No trial starts once the time is out, and no
   map is read for one: each mutant Pest left uncovered is unjudged. A
   static check starts only where the time left has room for the
   analyser's run over the originals, at the time the plan measured it;
   each survivor it has no room for is unjudged, `before-checking`, for the
   second wave to check, where before it stayed a survivor.
3. **Each job records what it measured.** Its result's `measured` holds
   the platform its runner ran on, as a digest, its `setup`, its
   `windDown` past the stop, where it stopped, and `passTwo`, how many
   times their bound its second passes took: each pass of split streams
   is a step within the mutation step, `first pass` and `second pass`,
   with the seconds it took, the mutants it forked, and, as `bound`, the
   wall seconds it would take were every mutant it streamed forked at
   each covering test. The verdict keeps the newest 400 jobs in the
   ledger, under `jobTimes`, a second pass's factor only of a job that
   judged a share of the second wave; the plan reads, for its platform,
   the longest setup and wind-down and the median factor, and hands them
   to every job in its briefing.
4. **A share's second pass is priced at every covering test.** Each left
   mutant's covering tests' seconds count towards its second pass whether
   or not the ledger names its killer, scaled by the factor the plan
   learned, 1 where none was learned; a named killer adds its fork to the
   first pass. The cut names the shards that left no result; none of
   their mutants is in a share, and the verdict fails on them as before.

## Why no verdict moves

A stop decides only which job judges a mutant: every mutant a job did not
finish, trial or check is unjudged and goes to the second wave, which
judges it as the shard would have. A survivor no static check had room for
is no longer counted a survivor before the check that may kill it runs.
Prices and factors decide only how many shares there are.

## Alternatives considered

- **A fixed `shards.jobLimit`**: the probe's shard ran 12 s past ten
  minutes on a limit of eight.
- **Setup and wind-down from the CI's API**: the verdict would read every
  job's start and end; a job knows its own start without a token.
- **The second pass priced by its mutants without a named killer**: the
  share of 1,075 mutants was priced at a twentieth of what it took; the
  ledger's killers are least right for the mutants a shard could not finish.
- **The factor learned from every job**: a shard's first pass settles most
  of its mutants, so its factor is far below a share's, whose mutants are
  the ones its shard left.
- **Survivors left unchecked counted as survivors**: a stop would fail a
  run that the check passes.

## Consequences

- On the probe's numbers a shard's gate stops after about 470 s in a
  ten-minute job, and its job ends near nine minutes.
- The second pass's factor measured on the probe's shares was about 0.7 of
  their bound; until the ledger holds a run's, shares are priced at 1.
- The plan's part count keeps the factor of 1: a shard's part is priced by
  the plan's cost model, not by its stream's bound.

## Related

- [ADR-0190](0190-a-shard-hands-what-it-left-to-a-second-wave.md): the second wave.
- [ADR-0194](0194-a-first-pass-gives-what-it-settled.md): a first pass gives what it settled.
