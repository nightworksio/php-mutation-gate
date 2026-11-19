# ADR-0230: Every run says where its minutes went

**Status:** Accepted
**Date:** 2026-11-19
**Amends:** [0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): the closed set of a shard's steps, and what the JSON report and the step summary say of a run's cost
**Amends:** [0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): what the step summary holds

## Context

A run says what it cost and what it saved (ADR-0016, ADR-0017): its wall
and runner time, planned and measured. It does not say where that time
went. The gate's own last full run on its default branch spent 731 shard
runner-minutes on 46,988 mutants. A fork alone takes about 0.03 s of a
core (ADR-0091), which is about 25 core-minutes for those mutants. The
test suite takes 1,595 s of a core, test after test, so a full run should
cost two to three times that.

What a run spends beyond that floor is spread over the ways a mutant can
be judged, its routes:

- forked by a split stream in its first pass (ADR-0117, ADR-0187) or its
  second (ADR-0196);
- a fresh run, a process of its own for a mutant whose file was loaded
  before its tests began (ADR-0148);
- its own run, in a process of its own;
- rejected by static analysis (ADR-0020).

A shard names the steps its time went to (ADR-0016). The first and second
passes and the static check each have a step, but a fresh run's time is
named `mutation`, as an own run's is. A shard's result says, of each
mutant, whether a split stream judged it, but not in which pass and not
whether it ran fresh. Nothing records how many cores a shard's machine had,
so wall seconds cannot be turned into what the runner minutes pay for.

The most expensive mutants and tests are found today by reading the JSON
report by hand.

## Decision

1. **A mutant's route is recorded where it is judged.** `Route` has five
   cases: `stream first pass`, `stream second pass`, `fresh run`,
   `own run` and `static check`.
   - A runner names a mutant's route on its evidence,
     `Evidence::routed(Route)`. The shard result writes it in the mutant's
     record as `route`. A record without one reads as no route named.
   - The Pest adapter routes a streamed mutant to the first pass, or to
     the second where its run carries the record event `second-pass`. A
     fresh run's verdict names `fresh run`.
   - The verdict reads a mutant's route as:
     - `static check` for a kill static analysis made;
     - otherwise, the route its runner named;
     - otherwise, the first pass for a streamed mutant;
     - otherwise, its own run.
   - Only a mutant some route ran to its verdict has a route: killed by a
     test or by static analysis, survived, timed out, errored, out of
     memory or failing unmutated.
2. **Fresh runs have a step of their own**, `fresh runs`, beside
   `mutation`. A shard's time in a route is:
   - the route's own step: `first pass`, `second pass`, `fresh runs` or
     `static check`;
   - for own runs, the shard's `mutation` steps less the passes within them.

   The shard's time in no route is its seconds less its time in every
   route.
3. **A shard records its machine's cores** as `measured.machineCores` in
   its result, counted as pest-plugin-mutate counts them. A result of an
   earlier gate is taken to have the verdict's machine's cores.
4. **The verdict reports where the run's minutes went.** The report holds:
   - for each route, its mutants, its wall seconds over every shard, its
     core-seconds (each shard's seconds times that shard's cores), and its
     core-seconds per mutant, beside the fork floor of 0.03 s;
   - the shards' seconds in no route;
   - the costliest mutants the run judged, by the seconds their runner
     gave them, each with its route;
   - the costliest tests: each test's own seconds, as the coverage map timed
     it, times how many of the mutants the run judged sit on a line it runs.
     Each of those mutants can run the test once, so this is the most the
     test can have cost the run;
   - the run's wall and runner time;
   - its jobs: the plan where it was timed, every shard, every job of a
     second wave (ADR-0190), and the verdict;
   - its rounds: none, one, or two where a second wave ran;
   - how many mutants the verdict left unjudged;
   - its core-seconds: each shard's seconds times its cores, and the rest
     of its runner time times the verdict's machine's cores;
   - one run of the test suite, the coverage map's timed tests added, and
     the run's multiple of it, where the map timed any test.
5. **The JSON report holds it as `costReport`**, beside `cost`, `run` and
   `savings`, written as `{"forkFloorSeconds", "routes": [{"route",
   "mutants", "seconds", "coreSeconds", "coreSecondsPerMutant"?}],
   "outsideRoutesSeconds", "mutants": [{"id", "file", "line", "mutator",
   "seconds", "route"?}], "tests": [{"test", "ownSeconds", "mutants",
   "atMostSeconds"}], "wallSeconds", "runnerSeconds", "jobs", "rounds",
   "unjudged", "coreSeconds", "suiteSeconds", "suiteMultiple"?}`.
   `resources/report.schema.json` describes it.
6. **The step summary shows it collapsed**, as *Where this run's minutes
   went*, before the link to the run:
   - one line of the run's time, jobs, rounds and unjudged mutants;
   - one line of its core time against the test suite;
   - a table of the routes;
   - the fork floor and the time in no route;
   - a table each of the costliest mutants and tests.
7. **Two settings.**
   - `outputs.costReport`, `true` by default, turns the report off.
   - `outputs.costliest`, 10 by default, says how many of the costliest
     mutants and tests it names.
   - `outputs.costs: false` leaves the report out with the rest of the
     run's cost.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Route seconds from each mutant's own seconds** | A streamed mutant's verdict is a fork's, and its runner gives no seconds for it, or the parent's. Steps are what every runner already times. |
| **Wall seconds without cores** | A runner minute pays for every core of its machine. Wall seconds alone hide idle cores, which are most of the gap to the fork floor. |
| **A test's cost measured inside each stream** | That needs a timer in every child of every stream, so the measuring costs time of its own. Own seconds times the mutants on its lines bounds the cost from data every verdict already holds. |
| **The report in the pull request's comment** | The comment is written for a reviewer, and its cost section is already collapsed (ADR-0016). The step summary and the JSON report are where a run is examined. |
| **Counting a mutant in every route it passed through** | A mutant the first pass left and the second judged would be counted twice, and core-seconds per mutant would fall below what a verdict costs. |

## Consequences

- Every run says how far each route is from the fork floor, and how many
  runs of the test suite it cost, so each change to the engine shows its
  effect in the run that carries it.
- Until the Pest adapter's streams mark the second pass and its fresh runs
  name their route and step, those mutants read as first pass and own
  run, and fresh runs' time as own runs'.
- The shard result format stays at 1: `route` and `machineCores` are
  optional, and a result without them reads as before.

## Related

- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): a run's cost, its timings and the steps of a shard
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): what a run saved
- [ADR-0091](0091-a-files-mutants-are-compiled-into-one-schema.md): the fork floor
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): split streams
- [ADR-0148](0148-a-mutant-of-a-file-pest-loads-first-is-judged-in-a-fresh-run.md): fresh runs
- [ADR-0190](0190-a-shard-hands-what-it-left-to-a-second-wave.md): the second wave
- [ADR-0196](0196-a-job-stops-at-its-target-less-what-it-measured.md): the second pass's step
