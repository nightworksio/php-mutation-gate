# ADR-0134: Shards pull the work left, instead of owning a fixed part

**Status:** Accepted
**Date:** 2026-11-09
**Amends:** [0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): who runs each part of a plan, for a run that pulls; [0032](0032-a-pull-requests-shard-stops-in-chunks-of-fifteen-minutes.md): chunks on every run that pulls, not only a pull request's; [0040](0040-a-verdict-stands-only-on-what-it-can-stand-behind.md): decision 4, a result's place, as one per item

## Context

The plan cuts a run once, contiguously by path and sized by learned cost
(`src/Core/Plan/Cut.php:24-37`), and the reusable workflow starts one job per
shard from the plan's output (`.github/workflows/mutation-gate.yml:495-508`).
Each shard uploads one result under its own number (`:573`). The verdict
requires one result per planned shard, holding exactly the units the plan
gave it (`src/Cli/Flow/Results.php:37-46`, `:70-73`). A pull request's shard
runs in chunks of `plan.chunkMinutes`, 15 by default (ADR-0032); a push's
shard runs as one invocation.

The cut is static, so a shard that finishes early idles while another runs
on, and a shard that starts late, queued under the 20 concurrent jobs of
GitHub's Free plan, still owns its whole part. Measured on four full runs of
the gate's own CI (minutes):

| Run | Shards | Shard-min | Mean shard | Slowest | Start spread | Shard phase |
|---|---:|---:|---:|---:|---:|---:|
| 42493050329 (push) | 19 | 570 | 30.0 | 60.3 | 21.4 | 60.3 |
| 42494457581 (PR) | 19 | 712 | 37.5 | 55.9 | 64.3 | 120.2 |
| 42493881214 (PR) | 13 | 884 | 68.0 | 140.7 | 5.8 | 140.7 |
| 42485819353 (PR) | 16 | 641 | 40.0 | 70.0 | 14.0 | 70.0 |

A model that gives each shard its measured start, 60 s of setup and the same
divisible work, ending when the work is spent, takes the shard phase of
these runs from 60.3, 120.2, 140.7 and 70.0 minutes to 33.3, 70.9, 69.2 and
41.7: 40% to 51% less. With hot files selected exactly (ADR-0138), it takes the
first three from 37, 94 to 104, and 106 minutes to 26.9, 62.6 and 56.5.
Runner minutes move by about nothing: the same work, plus one claim per item.

`upload-artifact` from v4 refuses a second artifact of one name in one
workflow run, and an artifact is readable through the REST API as soon as it
is uploaded. Whether two simultaneous creates of one name always fail one of
them is not documented.

## Decision

1. **`shards.pull` says how a shard finds its work:** `auto`, the default,
   `claim`, `steal` or `off`.
   - `off` keeps the static cut: each shard runs its own planned part.
   - Every other value has the plan cut each package's work into items, its
     chunks of `plan.chunkMinutes` or, under split streams, its test parts
     (ADR-0117 decision 9), and list each shard's items longest first. On a
     run that pulls, a push's shard runs in chunks too.
2. **Under `claim`, a shard claims each item before it runs it.** A shard
   works through its own list, then through the other shards' lists from
   their backs, until no item is left. It claims item `k` by uploading an
   empty artifact named `mutation-gate-claim-<plan digest>-<attempt>-<k>`. An
   upload refused for its name means another shard holds the item. The plan's
   digest keeps the names of two runs of the gate in one workflow run apart,
   and the attempt keeps a re-run's apart.
3. **Under `steal`, a shard takes items from the back and marks what it
   finished.** It works through its own list, then through the longest
   remaining list from its back, skipping an item for which any shard
   uploaded a done marker,
   `mutation-gate-done-<plan digest>-<attempt>-<k>-<shard>`.
   Two shards may run one item; the verdict keeps one result for it
   (decision 6).
4. **`auto` claims where the claim has passed its race test, and steals
   elsewhere.** The gate ships the list of services on which its own CI's
   race test passes, by the server URL a run reports (`GITHUB_SERVER_URL`),
   github.com first. The gate's CI runs the race test every night, and a
   failure is a red check, so a service leaves the list in the release after
   it fails. A run on a service not on the list steals.
5. **Any failure of the artifact service falls back to the static cut.** A
   claim or a done marker that cannot be written, or read, or a run with no
   runtime token, makes the shard run its own planned items, as with `off`,
   and say so once.
6. **The verdict merges by item.** Each shard's result lists the items it
   judged. The verdict requires exactly one result for every planned item,
   whichever shard left it: an item with none cannot be judged, as a missing
   shard cannot today. Where two results of one item agree, the first stands;
   where they differ, the mutants that differ are flaky (ADR-0008), as two
   results under one key are (ADR-0007).
7. **The claim uses the job's runtime token, not `GITHUB_TOKEN`.** The
   reusable workflow hands the gate's step the token every `upload-artifact`
   step uses, through a step of the gate's own action that exposes it. The
   shard job asks for no new permission.
8. **Only the verdict job computes the verdict.** A shard runs the
   project's code, so nothing it leaves is taken as the verdict, the
   delivery or what is published: the verdict job computes them from the
   shards' results, and the shard job holds no token beyond `contents:
   read`.
9. **The setting judges or reports only,** as the other `shards` keys do
   (ADR-0007): `shards.pull` is in no key, and a change to it reaches
   nothing, since which shard runs an item changes no result.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `shards.pull` | `auto`, `claim`, `steal`, `off` | `auto` | judges or reports only |

### Why no verdict moves

- **Every item is judged by the same code, from the same booted state,
  whichever shard runs it.** A shard runs an item exactly as the shard the
  plan gave it would.
- **A lost claim moves an item, it never drops one.** An item with no result
  fails the verdict as cannot-judge, and two results of one item are compared
  as two runs of one key are.
- **The race test proves the primitive before `auto` trusts it:** twenty
  jobs of one run uploading the same names at once, each name won by exactly
  one. Where a service fails it, `auto` steals, which tolerates two winners.

### What the token exposes

Today only the upload step, after the project's code has run, holds the
runtime token. With claims, the gate's step holds it while it runs the
project's tests, so the project's code can read it.

- On a pull request, that code could already forge its own shard's result,
  since it runs on the machine that uploads it. With the token it can also
  claim items it never runs, which leaves them without a result and fails the
  verdict, and it can write the Actions cache of the pull request's own
  scope, which its own run writes anyway.
- On a push to the default branch the code is merged code, and no credential
  moves.

### Proof before it ships

- A property test of the verdict's merge: any assignment of items to shards,
  any duplicates among them, yields exactly one result per item or
  cannot-judge.
- The race test above, run by the gate's own CI every night.
- A self-gate run on one commit with `shards.pull: off` and with `claim`,
  comparing every mutant's status and killers.

### Where today's path stays

`shards.pull: off`, a service without artifacts of one name per run, and any
failure of the claims (decision 5) keep the static cut.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Git refs as claims, created if absent through REST** | It needs `contents: write` in jobs that run a pull request's code, against ADR-0019's line. |
| **Only sort each shard's own work longest first** | It balances within a shard; a shard that starts late still owns its whole part. |
| **More shards, cut smaller** | Each shard pays its setup, and under the job cap more shards queue longer. |
| **The shard that finishes the last item computes the verdict** | That shard runs the project's code, so a verdict, delivery or publish material it leaves can be forged by the change under judgement, and listing the run's jobs for it needs `actions: read` in that job. It would save only the verdict's compute and install, not the verdict job's wait to start. |
| **A coordinator job handing out items** | It holds a runner for the whole shard phase and a token to talk to every shard. |

## Consequences

- A run's shard phase ends when its work is spent, not when its slowest part
  is; a shard that starts after the work is gone costs its setup.
- A push's shard runs in chunks where it pulls, paying an opening run per
  chunk.
- Each item leaves an empty artifact behind it, kept for a day as results
  are.

## Related

- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): a plan cut once
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): two results under one key
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): flaky triage
- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): no write token where a pull request's code runs
- [ADR-0032](0032-a-pull-requests-shard-stops-in-chunks-of-fifteen-minutes.md): chunks
- [ADR-0040](0040-a-verdict-stands-only-on-what-it-can-stand-behind.md): a shard's result in its own place
- [ADR-0106](0106-carried-survivors-are-judged-again-and-their-re-check-is-bounded-and-shared.md): the survivors job
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): test parts
- [ADR-0138](0138-a-covering-set-too-long-for-a-filter-is-selected-exactly.md): hot files select exactly
