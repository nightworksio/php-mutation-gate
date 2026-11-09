# ADR-0114: A shard is cut to fit its job, and stops before it

**Status:** Accepted
**Date:** 2026-11-07
**Amends:** [0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): how a package's units are cut; [0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): what bounds a shard

## Context

A hosted GitHub Actions job is cancelled at 360 minutes, and a cancelled shard
leaves no result, so everything it judged is lost. Three things let a shard get
there:

- The cut filled every run up to the costliest unit's cost, so one unit's
  estimate sized every shard of its package, and fewer shards were cut than
  `shards.max` allows. `shards.seconds`, `shards.target` and `--shards` all
  reach the cut through that bound.
- A plan expected past a job's limit printed the figure and nothing else.
- A shard had no deadline unless `budget` set one.

## Decision

1. **A unit bounds its own run alone.** A unit that costs more than the bound
   takes a run by itself, and the bound falls until the units fill the count
   asked for. The costliest run costs what it did, and the other shards share
   the rest evenly. A unit that costs nothing starts no run.
2. **`shards.jobLimit` says how long a shard's job can run**, `5h40m` unless
   given, under a hosted job's 360 minutes by the time a job needs to set up
   and to keep what it judged.
3. **The cut keeps each shard inside the limit** while `shards.max` leaves
   room. A count asked for with `--shards` is cut as asked.
4. **The plan says where the longest shard cannot fit**, with what to change.
5. **A CI shard stops at the limit**, or at `budget` where that comes first.
   The runner stops as it does at a budget: the shard keeps what it judged, what
   it left is unjudged, and the verdict cannot pass on it. Each unit it finished
   keeps its proof, so the next run does not run it again.
6. **The limit turns no unit away on its estimate.** A budget starts nothing its
   estimate says will not fit; the limit starts every unit and stops the runner,
   because an estimate can be far past what the unit takes.

7. **A first estimate expects a kill, not every test, and a shard's other
   work.** A run stops at the first test that kills, so a mutant is expected to
   spend its start-up, `costs.testsBeforeAKill` of its covering tests at their
   average time, and a `costs.surviving` share of all of them for those that
   survive. A shard is expected to take `costs.shardOverhead` times its mutants'
   own runs, for its controls, baselines and static checks. The defaults, 5,
   0.02 and 2.5, are fitted to one companion run and one of this gate's, whose
   mutants' own runs were 53% and 51% of their shards and which have few
   survivors; another project sets its own, and each applies only until a shard
   has timed the unit.
8. **A round of a heavy mutant's parts names what it runs in the log**: the
   mutant, its file and line, the part and how many remain, so a slow phase can
   be read even from a cancelled job. A shard's result times those rounds as
   their own step.
9. **A round fills its pool with parts ahead**, under `timeouts.heavyFill`, on
   unless turned off. It runs what each heavy mutant waits on, then each one's
   next part ahead in turn while the pool has a free slot and the part fits in
   the time left. Two parts of one mutant can run at once on every runner: each
   is its own process with its own files, served the same text. What a part
   ahead finds is kept until its turn and read in the order of the parts, so a
   mutant is judged as it is one part after another. No part runs past one
   found to fail; a part already running when one before it fails is spent, in a
   slot that was idle.
10. **`timeouts.heavyParts` caps a heavy mutant's parts, off unless set.** A
    heavy mutant no part has settled by the cap is unjudged, which fails the
    verdict: a test that was never run proves nothing either way. A timeout is
    not counted as a kill to avoid this. The cap trades a red verdict for the
    minutes the later parts take, so a project that sets it accepts that a run
    stays red until a higher cap judges those mutants. A unit with a capped
    mutant keeps no proof, so it runs again on every run. The cap is in a proof's
    key, since it changes what a mutant comes to.

## Consequences

- A shard runs under a deadline even where no budget is set, and runs its units
  as it does with none, in one invocation.
- A run on one machine, with no shard named, has no such deadline: it has no
  job to outlive.
- A CI whose jobs are cancelled sooner sets `shards.jobLimit` below that.
- A round's slowest part sets how long it takes, so a part ahead with a longer
  limit than those waited on can lengthen the round; that part's run is spent
  either way unless a part before it kills the mutant.
