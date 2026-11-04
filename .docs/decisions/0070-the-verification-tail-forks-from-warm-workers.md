# ADR-0070: The verification tail forks from warm workers

**Status:** Accepted
**Date:** 2026-11-04
**Amends:** [0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): its rule 14, `runner.workers` and survivor confirmation in a fresh process

## Context

ADR-0023 forks each mutant's run from a warm worker. The runs that verify the
mutants after that still each start a fresh process: the unmutated controls
(ADR-0008, ADR-0014, ADR-0036), the baselines and replays of narrowed kills
(ADR-0004), the trials of unexecutable lines, the confirmation of doubtful
kills, and the survivors' runs again (ADR-0008). Each of these pays PHP's
start, and, under the PHPUnit runner, the autoloader and the bootstrap, and
under Pest with Laravel's `RefreshDatabase`, a migration.

In the gate's own full run on `main` (run 41443050939), the controls took
10.3% of shard time: 126 fresh processes in one shard. In the benchmark,
survivors took 497 s and baselines 221 s of pinkary's 1,891 s, a Laravel
suite that migrates in each fresh process, and survivors took 204 s of
symfony/demo's 401 s.

## Decision

1. **`runner.workers` takes `fork`, `mutants` or `fresh`.** `fork`, the
   default, forks each mutant's run and each run of the tail from a warm
   worker. `mutants` forks each mutant's run and starts each run of the tail
   fresh, as ADR-0023 had it. `fresh` forks nothing. It stays in key item 3
   (ADR-0007), so results of one setting never stand for another's.
2. **Under the PHPUnit runner, the controls fork as mutants do.** A control
   is a mutant that changes nothing (ADR-0023), so it is handed to the warm
   workers ADR-0023 rule 12 starts, behind the same guard, and each control
   no child ran runs fresh. The worker reads the most memory each child held
   from what `wait4` reports of it, which the control's peak is, as the
   launcher's is for a fresh one (ADR-0004).
3. **Under Pest with `pest.patch`, the gate's own process starts the tail's
   workers.** For each set of tail runs it starts side by side, one worker
   per place, as Pest's parent does for a mutation run (ADR-0023 rule 15),
   and ends them once the runs are done. Each run goes through the client
   of the worker its slot names, so a run its worker forks no child for, or
   whose worker is gone, starts fresh in the client's place. The client
   writes the most memory its child held where the control's peak is read,
   which takes the larger of that and the launcher's.
4. **The survivors' runs again and the confirmation of doubtful kills are
   mutation runs in the tail's pool.** Under `fork` they fork; under
   `mutants` and `fresh` they start fresh.

### Why a forked control is as fresh as a fresh one

A control vouches for a kill when it runs the same tests on the same code
under the same limits as the mutant's run, and finds whether they pass
without the mutant (ADR-0036, ADR-0008). A child forked from a warm worker
runs exactly that:

- **The same code.** The child serves the unmutated file through the same
  override a fresh control does. The worker holds no file the run mutates:
  the PHPUnit worker's guard refuses a boot that loaded one (ADR-0023 rule
  13), and the Pest worker loads nothing of the project and starts fresh a
  run of a file it declared. Opcache is off in the worker, so no child is
  served what another compiled.
- **The same state at its start.** The worker never runs a test. Each run is
  a copy of the worker as it stood after its boot, so nothing one run does
  reaches the next. That boot is what a fresh process runs before its first
  test: the autoloader, the configuration and the bootstrap under PHPUnit,
  and nothing under Pest. The guard refuses a boot that holds a socket or
  started PHPUnit's events, which a copy would share.
- **The same limits.** The child is stopped at the control's limit, and at
  its silence limit, with every process under it, and is judged by how it
  ended and what it printed, as a fresh process is.
- **A generator of its own.** Each child seeds PHP's generator afresh
  (ARCHITECTURE B2), so no two children draw the same numbers, as no two
  fresh processes do.
- **The same database.** Under Pest, a child readies its test databases from
  the baseline its worker keeps, checked and set back first, as ADR-0023
  rule 15 has a mutant's child do.

`WarmControlsTest`, in the runner contract, runs the same controls forked
and fresh with a real PHPUnit and a real patched Pest, and fails where they
differ in end or failing tests. Under PHPUnit the test the controls run fails
where an earlier run in its process left anything behind, and the worker
boots once where each fresh control boots for itself.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep the tail fresh, as ADR-0023 had it** | Each control, replay, trial and survivor pays the start and the boot again, which is most of the tail in a framework app. It stays available as `mutants`. |
| **Fork the tail but keep survivor confirmation fresh** | ADR-0023 kept it fresh so a survivor a warm boot made would show as flaky. The guard and key item 3 already keep a boot's state from standing for a fresh run's, and `mutants` keeps the cross-check for a project that wants it. |
| **A key of its own for the tail, beside `runner.workers`** | Two keys for one choice, one of whose combinations, fresh mutants with a forked tail, does nothing anyone asked for. |
| **Keep the mutation run's Pest workers for the tail** | They are Pest's parent process's, and end with it. The gate's own process starts the tail's. |
| **Measure a forked control's peak through the launcher** | The launcher counts only the processes it waited for, and a forked child is the worker's. |

## Consequences

- A run whose worker forks it no child starts fresh. The tail warns of
  nothing of its own: the mutation run warns of the reasons its workers gave.
- A project that wants every tail run fresh sets `runner.workers: mutants`.
- Under Infection, under Pest without `pest.patch`, under the PhpSpec,
  Codeception and Testo runners, and on a PHP that cannot fork, the tail
  starts fresh, as their mutants do.

## Related

- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): narrowed kills, their baselines and the launcher's peak
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): controls and survivor confirmation
- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): warm workers
- [ADR-0036](0036-a-kill-only-errors-made-counts-where-its-control-passes.md): what a control vouches for
