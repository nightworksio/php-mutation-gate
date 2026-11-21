# ADR-0303: A job runs its own tail where its wave outnumbers the pool

**Status:** Accepted
**Date:** 2026-11-20
**Amends:** [0195](0195-a-streamed-job-is-priced-at-its-wall-time.md): §2, the most shares a second wave is cut into, and §4, the most shards a run that cuts its test files makes
**Amends:** [0196](0196-a-job-stops-at-its-target-less-what-it-measured.md): what a job's gate stops at
**Amends:** [0240](0240-a-metered-runner-plans-for-the-fewest-billed-minutes.md): §2, where a job stops in `minutes` mode

## Context

A job's gate stops at its target less what it measured (ADR-0196), and
hands what it left to a second wave (ADR-0190). That buys wall time only
where every job of a wave runs at once. On the gate's own run of 89b3bdbf6
the plan cut 75 shards to stop at about 460 s each. The default branch's
last ten runs had run at most 19 shards at once (`shards.max` learned), so
the 75 shards ran in waves from 19:32 to 21:51 anyway. Each shard settled
most of its mutants in its first 100 s of judging, then spent about 345 s
on a slow second pass and settled about 25 more. Its stop then handed
38,368 mutants to a second wave of 231 shares. A share's job pays about
68 s of fixed cost (setup, services, a 282 MB coverage download, the map,
the parent test-file runs) and starts its second pass again from nothing.
Job 1.2 judged 1 of its 250 mutants. The run was on pace for about 2,400
runner-minutes in 391 jobs, against 824 runner-minutes in 65 jobs for the
run of a9505f98, whose shards ran their own tails.

## Decision

1. **`shards.tail` says how a job stops: `auto`, the default, `stop` or
   `run`.**
   - Under `stop`, a job's gate stops at its target and hands what it left
     to a second wave, as ADR-0196 says.
   - Under `run`, a job runs its own tail to its job's limit.
   - Under `auto`, a wave of jobs runs its own tail where it comes to more
     jobs than run at once, or where `costs.mode` is `minutes`. Otherwise
     it stops.
2. **The pool is `shards.max`, written or learned.** The plan
   reads the jobs that run at once from `shards.max`: the number the config
   writes, or the most shards the default branch's last ten runs ran at
   once where it is `learned`, 20 before any run was learned (ADR-0073). No
   other setting names the pool.
3. **A job's limit** is how long its gate may run where it runs its own
   tail:
   - `shards.jobLimit`, where the config sets it;
   - otherwise, the job's own timeout less its setup, its wind-down and
     `shards.margin`. The workflow hands the plan job its `shard-timeout`
     as `MUTATION_GATE_JOB_LIMIT`, in minutes. Where it hands none, the
     timeout is six hours, GitHub's own.
4. **A wave that runs its own tail and outnumbers the pool is cut to the
   pool, and never a job past its limit.** A run whose shards come to more
   than the pool at their target is cut into the larger of two counts: the
   shards the target asks for, bounded by the pool, and the cost divided by
   the room a job's limit leaves, its overhead taken off. Where the pool's
   shards fit their limit, the run is cut into them. Where they do not, it
   is cut into as many shards as hold the cost within the limit, and the
   pool runs them in waves. No shard is made larger than its limit holds.
   This reverses ADR-0195 §4's bound of one matrix of jobs for that case.
   A count `--shards` asks for is kept as asked; under `auto` its jobs run
   their own tail where it is more than the pool.
5. **The plan carries the decision to its jobs.** The plan's briefing
   holds:
   - the job's limit, as `tailLimit` in seconds;
   - the pool, as `tailPool`;
   - `ownTail: true` where its shards run their own tail.

   Each job reads its stop from them, a job that pulls its work included:
   it takes a further item where the item fits the time left to that stop
   (ADR-0197). A plan file without the fields stops its jobs at their
   target, and cuts its second wave as before. A run whose plan was written
   before an upgrade keeps its behaviour to the end.
6. **The second wave decides its tail in the same way.**
   - The cut first asks whether the shards' results decide the verdict
     (ADR-0301). Where they do, it cuts nothing, and nothing is folded.
   - Where the plan's shards ran their own tail, only a job that reached
     its limit left mutants. The shares are cut to that limit, and their
     jobs run their own tail. That makes at most the pool's shares where
     the pool's jobs hold what was left. Where they do not, it makes as many
     shares as the limit holds, run in waves. No share is made larger than
     its limit to fit the pool.
   - Where the plan's shards stopped at their target, the shares are cut to
     that stop, as before. Where they come to more than the pool and
     `shards.tail` runs such a wave, they are cut again: at most the pool's
     shares, each sized to the job's limit, each job running its own tail.
     The share carries `"ownTail": true` in `leftovers.json`, and its job's
     deadline reads it. A share without the field stops at its target.
   - A share never holds two shards' mutants, so where more shards left
     work than the pool holds, each shard keeps one share.
   - Each shard's shares are priced at the second pass's factor its own
     job of the first wave measured. Where it measured none, they are
     priced at the median of the factors the run's jobs measured. Where
     none did, at the factor the plan learned of recent jobs
     (`learnedPassTwo`). Where it learned none, at 1.0: every mutant forked
     at each covering test.
7. **One home for the stop.** `PlanLearned::stop` reads every job's stop
   from the shard settings, what the plan learned of its jobs and how its
   jobs stop: a shard's deadline, the second wave's capacity and the
   plan's estimates all read the same one.

### How the settings combine

`costs.mode` says what a plan spends least of (ADR-0240); `shards.tail`
says how its jobs stop. They are one decision, and no combination is
refused:

| `shards.tail` | `costs.mode` | shards at the target | first wave | second wave |
|---|---|---|---|---|
| `auto` | `wall` | at most the pool | stops at the target | cut to the stop. Where the shares outnumber the pool: folded into at most the pool's shares that run their own tail |
| `auto` | `wall` | more than the pool | the pool's shards, or more where the cost needs them within the job's limit, in waves; each running its own tail | only past the job's limit: shares within the limit, at most the pool's where they hold what was left, more in waves where not |
| `auto` | `minutes` | at most the pool | the count `minutes` cuts, each running its own tail | only past the job's limit, as above |
| `auto` | `minutes` | more than the pool | the pool's shards, or more within the job's limit, in waves; each running its own tail | only past the job's limit, as above |
| `stop` | either | either | stops at the target, up to one matrix of jobs | cut to the stop, up to one matrix of jobs, as before |
| `run` | either | at most the pool | the count cut, each running its own tail | only past the job's limit, as above |
| `run` | either | more than the pool | the pool's shards, or more within the job's limit, in waves; each running its own tail | only past the job's limit, as above |

`minutes` with `stop` is a team that pays by the minute and still wants
each job back within thirty minutes: its jobs stop there and hand on, as
ADR-0240 §2 said.

## Why no verdict moves

The tail decides only which job judges a mutant. A mutant a job did not
finish by its limit is handed on as ADR-0300 says, by the reason that
records what the time ran out before. Where nothing later judges it, the
verdict counts it unfinished. It is never silently left unjudged. A
second wave is cut from the same mutants as before, only into fewer,
longer shares. A runner lost with its shard leaves no result, and the
verdict cannot judge the run; ADR-0301 starts no second wave for it. A
shard of a pool-sized cut holds a larger share of the work, so a lost
runner takes more with it. Containing what loses a runner is a separate
decision.

## Alternatives considered

- **A pool setting of its own:** `shards.max` already says how many
  shards run at once, written or learned.
- **Stop early and keep the second wave whatever the pool:** on the run of
  89b3bdbf6 the second wave's 231 jobs cost about 1,736 runner-minutes and
  judged 656 of 3,233 mutants in the 26 jobs that left a result.
- **Always run the tail:** a wave that runs at once buys wall time by
  stopping at its target and spreading its tail over a second wave; `wall`
  keeps that where the pool runs the wave at once.
- **Cap the second wave at the pool only where the plan's shards run their
  tail:** under the default `wall`, with shards that fit the pool, the
  second wave would keep up to 256 jobs that each start again from nothing.

## Consequences

- On the run of 89b3bdbf6 the plan would cut 19 shards in place of 75, each
  running its own tail; no second wave would have been cut unless a job
  reached its limit.
- A job's tail costs runner time inside the shard instead of in a second
  wave, without the second wave's setup, coverage download and parent
  test-file runs for each share.
- `shard-timeout` bounds the work a shard is given as well as the job.
- The gate's own config sets `shards.jobLimit` to `8m`. With a job's setup
  and wind-down, each of its jobs stays within ten minutes. On its run of
  60,063 mutants, the plan predicted 111,425 s of cost over a pool of 19
  and four streams. That cuts 65 shards of at most 1,716 s of cost each,
  where the pool's 19 would each have been up to 53 minutes.
- On that run, the second wave was cut into 206 shares, priced at a second
  pass's factor of 1.0: 968,668 s, 934,482 s of it second passes. The
  first wave's jobs measured factors of 0.002 to 0.018. Priced at each
  shard's own, the same leftovers come to 41,316 s, 7,130 s of it second
  passes.

## Related

- [ADR-0073](0073-a-limit-can-be-learned-from-what-earlier-runs-measured.md): a learned `shards.max`.
- [ADR-0190](0190-a-shard-hands-what-it-left-to-a-second-wave.md): the second wave.
- [ADR-0197](0197-a-job-takes-what-fits-and-halts-what-runs-past.md): a job takes what fits its stop.
- [ADR-0300](0300-a-kill-left-unvouched-by-the-time-is-handed-on.md): what the time left unjudged is handed on.
- [ADR-0301](0301-no-second-wave-once-the-shards-decide-the-verdict.md): no second wave once the verdict is decided.
