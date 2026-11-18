# ADR-0190: A shard hands what it left to a second wave

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0114](0114-a-shard-is-cut-to-fit-its-job-and-stops-before-it.md): what becomes of the mutants a shard leaves at `shards.jobLimit`

## Context

A shard stops at `shards.jobLimit`, or at `budget` where that comes first,
and what it left is unjudged, which fails the verdict (ADR-0114). The limit
was `5h40m`, so a shard ran until it ended, however long its tail took.

On the gate's own speed probes every shard reached 95% of its mutants
within six minutes of its job starting, then spent eight minutes and more on
its last 1,000 to 1,500, in the second pass of its split streams
(ADR-0117). Those are survivors: each forks a child at every one of its
covering tests, a child that lives under a second, and its verdict lands
once the last part holding one of those tests ends. Nothing in a shard's
job waits for anything else; the tail is work, and one job does it alone
while every other job of the run has ended.

## Decision

1. **A shard's left mutants are cut into shares** by `mutation-gate
   leftovers`, after every shard, each share listing its mutants by the
   gate's id, the runner's own and the file each mutates: the mutants of each shard's result that
   the deadline left unjudged (`OutOfTime::left`). A share is priced as a
   stream runs it: the seconds the plan's coverage map timed every test of
   the test files covering any of its mutants' lines, to each mutant's last,
   each file once, a test's class standing for its file. A shard's mutants
   go in groups of one source file each, costliest first, each to the share
   it adds the fewest seconds to that it still fits the shard's capacity in,
   the first of those, else to a new share; a group over the capacity alone
   is a share of its own. A share's capacity is `shards.jobLimit` less the
   time its shard took to start judging, the `since` of its first mutation step, so a share's job
   is expected to end inside the limit as its shard's job would have. The
   shares are written to `results/leftovers.json`, with the digest of the
   plan they were cut from, and the command prints the matrix of the second
   wave's jobs: each share's shard and number. GitHub makes no job of a
   matrix over 256 entries, so where every shard's shares come to more,
   every capacity grows by a quarter and the mutants are cut again, until
   they come to 256 or fewer, or each shard has one share. No cap stops the
   cut short: a share never leaves work unjudged by design.
2. **The verdict judges a shard's left mutants by its shares' results**, each
   share's job leaving `results/<shard>.leftover-<share>.json`, a result of
   that shard read with every check a shard's own result gets. Each share's
   verdicts stand in place of the mutants it lists, with the mutants its job
   found flaky.
3. **The shares must be exact**, or the verdict cannot judge: a share's
   result holds no mutant its share does not list; a shard's shares list
   exactly the mutants it left; no mutant is listed twice. A share whose job
   left no result, or a mutant its share lists and its result lacks, stays
   as the shard left it, unjudged, which fails the verdict as any mutant left
   unjudged does. Shares cut from another plan are not read.
4. **A share's job runs its shard's whole pipeline over the share**: `run
   --plan --shard=<s> --leftover=<k>` reads share k from `leftovers.json`,
   mutates only the shard's units that hold its mutants, makes only them
   (the request's `MadeMutants`, which a patched Pest honours through its
   list of native ids, and which any runner that makes every mutant of its
   files meets by the result keeping the listed ones alone), and runs every
   step a shard's run takes after making: held coverage, static checks
   before and after, controls of kills and timeouts, survivor confirmation.
   Its result keeps the shard's keys, so it is read with every check a
   shard's own result gets, and it lands in `<shard>.leftover-<k>.json`.
   A share's job never pulls work (ADR-0134): it names its shard.
5. **The reusable workflow runs the second wave**: a `leftovers` job after
   every shard cuts the shares and uploads `leftovers.json` beside the
   results; a `leftover` matrix job, one job per share, runs the action with
   `leftover` set and uploads the share's result under the results'
   artifact pattern; the verdict waits for both and reads every result. Where
   no shard left a mutant the matrix is empty and no share's job runs.
6. **`shards.jobLimit` is `8m` by default**, so a shard's job, with its
   set-up, ends within ten minutes, and its tail goes to the second wave.

## Why no verdict moves

A share's job judges its mutants as the shard's own job would have, by the
same plan, map and runner, and its result passes the checks a shard's own
result passes. The verdict reads a share's verdict only for a mutant the
shard left unjudged, and only where the shares cover exactly what it left,
so no mutant is judged twice and none is dropped. A mutant a share's job
also leaves unjudged stays unjudged.

## Alternatives considered

- **Raise the limit and wait**, as before: the tail is bounded by the
  slowest shard's survivors, which grows with the suite, and leaves every
  other runner idle.
- **Cut more shards at plan time**: each shard pays its set-up and its
  making again for mutants that a cheap first pass judges in minutes; the
  tail is in the survivors, which the plan cannot tell apart before the run.
- **Price each mutant at the seconds of all its covering tests** and pack
  mutants alone: that is a mutant's own run, but a stream runs each covering
  test once for every mutant of its part, so a heavily covered mutant
  outgrew a share alone. On the gate's own probe 10,096 left mutants made
  1,625 shares, and GitHub made no job of the matrix.
- **Cap the shares at the plan's concurrency** (20 jobs on the Free plan):
  jobs past it queue, so the cap buys nothing, and a share over its capacity
  leaves work unjudged by design.
- **Split a source file's group into its mutants** where the group is over
  the capacity: a mutant of a heavily covered file is covered by nearly the
  same files as the group, so each becomes a share as long as the group's;
  on the probe's leftovers that made 732 shares where groups made 159.
- **Steal work between running shards** (`shards.pull`, ADR-0134): items
  are whole parts, and a part's tail is still judged by the job that holds
  it.

## Consequences

- A run's mutation takes about two job limits, not its slowest shard's
  whole tail, where every share ends inside its limit.
- The verdict job waits for the second wave as well as the shards.
- `results/` holds `leftovers.json` and each share's result beside the
  shards' own.
- A run whose shards all end inside the limit pays for the `leftovers` job
  alone, which cuts nothing.
- A CI other than the reusable workflow runs `leftovers` after its shards
  and `run --leftover` for each share in the matrix it prints, or raises
  `shards.jobLimit` so its shards leave nothing.

## Related

- [ADR-0114](0114-a-shard-is-cut-to-fit-its-job-and-stops-before-it.md): the limit, and what a shard keeps when it stops.
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): split streams, whose second pass makes the tail.
- [ADR-0134](0134-shards-pull-the-work-left-instead-of-owning-a-fixed-part.md): work pulled between running jobs.
