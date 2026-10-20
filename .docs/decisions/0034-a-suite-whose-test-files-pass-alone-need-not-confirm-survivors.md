# ADR-0034: A suite whose test files each pass alone need not confirm its survivors, and the gate's own does not

**Status:** Accepted
**Date:** 2026-10-20

## Context

ADR-0008, decision 3 runs each survivor once more, alone, before it counts,
and calls one killed the second time flaky. A flaky mutant counts as not
killed, as a survivor does, keeps its unit out of the ledger, and names its
judging tests as suspects. So the second run changes no score. It finds a
test that gives two answers.

In the gate's own runs on 2026-10-20 it found almost none:

- A full run on `main` (run 39373341555) ran 1,348 survivors again in
  1.13 shard-hours, and none was killed the second time.
- The full run of a pull request (run 39378485071) ran 1,350 again in 2.84
  shard-hours, and one was killed.

Under Pest with the gate's patch, each mutant's own run is already a process
of its own that loads only the test files its covering tests need
(ADR-0004, decision 9). Where every such file passes alone, which
`test files alone` checks of the gate's own suite on every change, a survivor
of that run has met the conditions the second run would give it again.

## Decision

1. **`flaky.confirmSurvivors` stays `true` by default.** ADR-0008, decision 3
   is unchanged for every project that does not say otherwise.
2. **A project whose CI runs each test file alone may turn it off.** The guide
   says so beside the setting: the second run then never happens, a survivor
   counts as it did, and no mutant is reported flaky by it. Proofs that
   disagree still make mutants flaky (ADR-0008, decision 3).
3. **The gate's own config turns it off**, since its `test files alone` job
   holds every test file of the suites a narrowed run loads to passing alone.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Confirm only the survivors whose own run was not narrowed** | Whether the runner narrowed a run is the runner's, and under Pest with the patch every run is narrowed. The setting already says what a project wants. |
| **Keep confirming the gate's own survivors** | 1.1 shard-hours of a full run, to find one flaky mutant in two runs. |

## Consequences

- A full run of the gate spends no time on second runs of survivors.
- A flaky test in the gate's own suite shows up as a survivor that a later run
  kills, which the ledgers of the two runs then report as flaky.

## Related

- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): decision 9, the narrowed run
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): decision 3, survivor confirmation
