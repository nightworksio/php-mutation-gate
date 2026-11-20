# ADR-0300: A kill the time left unvouched is handed on

**Status:** Accepted
**Date:** 2026-11-20
**Amends:** [0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): what a time budget ran out before
**Amends:** [0142](0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md): a kill whose vouching had no time
**Amends:** [0197](0197-a-job-takes-what-fits-and-halts-what-runs-past.md): a control the time left had no room to start

## Context

A shard hands the second wave each mutant its time left unjudged, by the
reason the mutant carries (ADR-0190): one that records what a time budget
ran out before. A narrowed run's kill stands only once a replay of its run,
unmutated, or a run again with every test file and its control, vouches for
it (ADR-0142). Where the time left had no room for those, the kill was
unjudged in plain words: "no time was left to replay its run unmutated", "no
time is left to run them all", "no time was left for it", or "no time was
left for the same run unmutated". None of them records the budget, so no
share held the mutant, no later job judged it, and the verdict failed on
it. On the gate's own run of 89b3bdbf6, 3,821 of the 74 finished shards'
mutants were left so: 2,761 with no time for the replay and 1,060 with no
time for the run again.

## Decision

1. **A mutant the time left unjudged says so, whatever step it was in.** Its
   own run, its check, its control, the replay or run again that vouches for
   its kill: where the deadline, or the time left, is why it has no answer,
   its reason records what the budget ran out before. The reason is made
   where the cause is known, and `OutOfTime::left` stays the one test of
   which mutants are handed on.
2. **A kill with no time to vouch for it is `before-vouching`.** A replay
   with no time to start, or stopped at the time left, a doubtful kill with
   no time left to run again, and one whose run again the deadline stopped
   before Pest made or wrote its mutants, are unjudged `before-vouching`:
   the time budget ran out before its kill could be vouched for. Before,
   that last run again left the whole item unjudgeable. A run again stopped
   for any other cause, such as its silence limit, still cannot be judged:
   the run's records say which cause stopped it.
3. **A control the time left had no room to start is halted.** A control
   asked with no time left, or never started because the time to start it
   in had run out, ends as one halted past the deadline: it found nothing,
   and its kill is unjudged `before-controlling`, as a halted control's
   is. So is a fresh run's kill whose unmutated run never started. A
   control that never ran for another cause, such as a file that cannot be
   served unmutated, stays unrun, in its own words, and is not handed on.
4. **`before-controlling` says what it judges.** Its words become "before
   its tests could run unmutated to judge it", since it stands for a kill's
   control as well as a timeout's.

## What the verdict does differently

- A handed-on mutant is judged from scratch by the job of the second wave
  that takes it: its own run, its replay or run again, its control. Its
  shard's unvouched kill counts for nothing; the later job's answer is the
  mutant's status. So a mutant goes from never judged to the status the
  shard would have given it with time, and no other status moves.
- A mutant left unjudged for any other reason stays as its shard left it:
  a replay in which a test fails unmutated, another order or count, a file
  loaded before the mutant was in place, a control whose file cannot be
  served. A later job would find the same, so none is handed on.
- Where no later job judges such a mutant, as in a run with a `--budget` on
  one machine or a share that itself runs out, its unit fails as unfinished
  (ADR-0008), as one whose own run had no time does. Before, it counted only
  as not killed, and a unit whose score still met its floor passed with a
  kill nothing vouched for.

## Alternatives considered

- **Hand on every unjudged mutant**: a later job finds the same failing
  replay or unservable file, and the second wave pays for it again.
- **Widen `OutOfTime::left` to the plain words**: the test would read prose,
  and the next reason worded otherwise would be dropped again.
- **`before-controlling` for a kill's replay too**: a replay is no control of
  the covering tests; the report would say which run had no time wrongly.
- **Hand on without failing the unit where nothing judges it later**: two
  meanings of a budget's reason, and a pass on a kill nothing vouched for.

## Consequences

- On the gate's own run of 89b3bdbf6 the second wave would have held 3,821
  more mutants, and the verdict could have judged every mutant.
- A result records `before-vouching`; a result an older gate wrote reads as
  before, its plain reasons unjudged and not handed on.

## Related

- [ADR-0190](0190-a-shard-hands-what-it-left-to-a-second-wave.md): the second wave.
- [ADR-0196](0196-a-job-stops-at-its-target-less-what-it-measured.md): a job stops at its target less what it measured.
