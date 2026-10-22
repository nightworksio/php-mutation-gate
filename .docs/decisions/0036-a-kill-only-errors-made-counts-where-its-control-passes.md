# ADR-0036: A narrowed kill only errors made counts where its control passes

**Status:** Accepted
**Date:** 2026-10-21

## Context

ADR-0004 lets a mutant's own run under Pest load only the test
files its covering tests need. Such a kill counts only where the run can
vouch for it. A kill no test is named for, or one only tests that errored
made, did not count, "as a helper a test calls by a name built at run time
leaves". It ran again with every test file loaded, in the confirmation step.

Every other narrowed kill already had a check for that case. Its control runs
the tests of the files the run loaded, alone, on the unmutated code, with the
file served through Pest's override as the mutant's copy was. A helper a
left-out file declares is missing there too, so the control fails and the
kill is doubted. A kill with a known order has a replay instead, which fails
the same way, but a replay that fails leaves its kill unjudged rather than
doubted.

That doubt was most of the confirmation step. In the gate's own full run on
`main` after ADR-0035 (run 39476018879), confirmation took 4.86 of
12.28 shard-hours and re-ran 8,124 kills: a quarter of all kills. In two
benchmark shards (run 39437343869) the slow kills were mostly
InstanceOfToTrue (138 of 180), TernaryNegated (105 of 206) and
InstanceOfToFalse (77 of 187). In strictly typed code these mutants end in a
TypeError or a call on the wrong class, an error rather than a failed
assertion. On 2026-10-20 the `test files alone` job found all 1,399 test files
a narrowed run can load passing alone, so the controls have nothing to
catch from the files themselves.

## Decision

1. **A narrowed kill only tests that errored made counts where its control
   passes**, the check of its set of files on the unmutated code. It is
   doubted, and runs again with every test file, only where its control
   fails. It is never replayed, even where its order is known: a failed
   replay would leave it unjudged, where the run again judges it.
2. **A kill stays doubtful where its records cannot vouch for it at all:**
   where no test is named as its killer, or where the run left no readable
   record of it.
3. **A kill only tests that errored made in a run that loaded every test file
   counts**, as such a kill under the PHPUnit runner or Infection does. Its
   kill control (ADR-0014) still runs its killers on the
   unmutated code.
4. This amends ADR-0004's rule that a kill made only by tests that
   errored does not count. The rest of that rule stands.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep running every errors-only kill again** | It re-runs a quarter of all kills with the whole suite, to catch a case the control already catches. |
| **Also count a kill no test is named for where its control passes** | With no killer named, nothing says which test failed: a crash before any test, or a run that died while loading, looks the same. The run again names one. |

## Consequences

- The confirmation step runs only for kills the records cannot vouch for and
  for those whose control or replay fails.
- A kill that a helper missing from a narrowed run made fails its control,
  so it is still never counted on the narrowed run alone, and the run again
  with every test file judges it.
- An errors-only kill with a known order runs its control, where any other
  such kill runs a replay.

## Related

- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the narrowed run and its doubt
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): kill controls
- [ADR-0034](0034-a-suite-whose-test-files-pass-alone-need-not-confirm-survivors.md): the `test files alone` job
