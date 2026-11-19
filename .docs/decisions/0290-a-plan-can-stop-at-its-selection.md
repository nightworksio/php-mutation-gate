# ADR-0290: A plan can stop at its selection

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0067](0067-the-newest-pull-requests-are-planned-again-so-a-plan-that-widens-says-why.md): replay plans the selection alone, in a clone of its own

## Context

`replay` plans the newest merged pull requests again and reads, from each
plan, only its selection: the units it runs and the reasons it reached
them. A plan does far more after the selection. It measures the held units'
coverage, hands each shard its part of the map, takes the opening, first-run
and start-up timings, and cuts the shards. Measured on one replayed commit,
that work was about 160 s of a 257 s plan. Eleven such plans outlasted the
job's 45 minutes. The job printed nothing until the timeout.

## Decision

1. **`plan --selection-only` stops after the selection.** It selects the
   units to run as a plan does: the reach, the proofs, sampling and pruning.
   It writes them with their reasons as one uncut shard. It measures no
   held coverage, hands nothing off and takes no timings.
2. **A selection is never run.** `plan.json` marks it `selectionOnly`, and
   `run` and every shard command refuse a plan so marked, saying so. With no
   timings, a selection holds no mutant time limit.
3. **Replay plans the selection alone, in a clone of its own.**
   `replay.py` runs `plan --selection-only` in a `git clone --local` under
   a temporary directory, so the job's checkout and its branches stay as
   they are. It prints one line per plan as it ends, with its seconds and
   its units, ends a plan that outlasts its limit after printing the plan's
   processes, and the job's timeout is 20 minutes.

## Consequences

- A replayed plan costs its selection: the keys, the reach and the proofs.
- The replay table has no shard column, since a selection cuts none.

## Related

- [ADR-0067](0067-the-newest-pull-requests-are-planned-again-so-a-plan-that-widens-says-why.md): replay.
