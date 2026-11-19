# ADR-0240: A metered runner plans for the fewest billed minutes

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the target a shard is cut to where none is written
**Amends:** [0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): what a run's cost says

## Context

The gate cuts its shards for wall time: as many shards as fit
`shards.target`, ten minutes by default, so a pull request hears back soon.
On a public repository GitHub's runners cost nothing, so that is the right
trade. A private repository pays for every runner minute, or spends a
monthly quota of them, and GitHub bills each job's time rounded up to a
whole minute. There, each shard's fixed overhead, its setup and its opening
run, is paid once per shard, and a plan cut for a ten-minute wall pays it
many times over.

## Decision

1. **`costs.mode` says what a plan spends least of.** `wall`, the default,
   plans as before. `minutes` plans for the fewest billed runner minutes.
   It is opt-in: it trades wall time for minutes, and only the team knows
   which one it pays for.
2. **In `minutes` mode a shard is cut to thirty minutes.** Where neither
   `shards.target` nor `shards.seconds` is written, the target is thirty
   minutes in place of ten, so a run cuts about a third as many shards and
   pays a third of their overhead; each job also stops there. A target or
   size the config writes still decides.
3. **Each package takes the count its jobs bill the fewest minutes at.**
   From the count that fits the target, each package's count may rise, while
   `shards.max` and `shards.cutAt` leave room, to the count whose jobs bill
   the fewest whole minutes, each job's overhead plus its share rounded up.
   A tie goes to more shards, which costs no minute and returns sooner. A
   count `--shards` asks for is cut as asked.
4. **Every run says the minutes its CI bills.** In both modes, a run on a
   CI reports the minutes its jobs bill, each rounded up to a whole minute,
   beside what the plan expected, in the step summary's cost section and in
   the JSON report's `cost` (`billedMinutes`), priced at
   `costs.perRunnerMinute` where the team gives one (`billedPrice`). The
   action reads them from the CI's own job times where its API gives them,
   and the gate estimates them from its timings and `shards.setup`
   otherwise. A run on no CI bills nothing and says nothing of it.

## Why no verdict moves

The mode changes how the work is cut into jobs and what the cost section
says; every unit is still judged, and every proof is keyed as before.
`costs.mode` reaches no key: a run in either mode reads and writes the same
proofs.

## Alternatives considered

- **`auto`, picking `minutes` for a private repository**: whether a
  repository's minutes are billed depends on its owner's plan, which the gate
  cannot read; a private repository on a plan with spare minutes would wait
  three times as long for nothing.
- **Sixty minutes in `minutes` mode**: about half the overhead of thirty
  again, but an hour's wait for a pull request; a team that wants it writes
  `shards.target`.
- **Ten minutes, optimising only the rounding**: keeps the wall time, but
  the overhead paid per shard, the larger cost, stays.
- **Billed minutes only in `minutes` mode**: a team on free runners could
  not see what moving to a metered plan would cost.

## Consequences

- A team on metered runners writes one line, `"costs": {"mode": "minutes"}`,
  and pays for fewer, fuller jobs; a run then takes up to thirty minutes of
  wall time.
- Every run's cost says what it billed, so a team on free runners sees what
  the same run would cost on a metered plan.

## Related

- [ADR-0196](0196-a-job-stops-at-its-target-less-what-it-measured.md): a job stops at its target less what it measured.

## Follow-ups

These belong to the workflow rather than the plan, and are left to the
pipeline's workflow:

- ARM runners where they are cheaper per minute than x64.
- A monthly budget of minutes, past which a run falls back to judging only
  what changed.
