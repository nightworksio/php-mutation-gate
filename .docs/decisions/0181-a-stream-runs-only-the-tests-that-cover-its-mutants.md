# ADR-0181: A stream runs only the tests that cover its mutants

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): what a stream of covering tests runs; [0136](0136-a-first-pass-stream-may-run-only-the-tests-that-killed-before.md) and [0138](0138-a-covering-set-too-long-for-a-filter-is-selected-exactly.md): selection by ids under Pest

## Context

A split-stream pass of covering tests (ADR-0117) collects the tests that
cover its mutants, finds the files that declare them, and starts each
stream on its part of those files. Each stream then ran every test of its
files, though only covering tests can fork a mutant the pass judges, and a
verdict reads only covering tests (`StreamVerdicts`). Parts are balanced by
the covering tests' times, so a stream whose files hold many other tests
ran far longer than its part was sized for.

On the gate's own shard 9, the second pass held 890 sites and was still
running 6 of its 8 streams at the 25-minute cap. Its parent time was led by
whole files: `MutantRunTest` 457 s, `WiringTest` 269 s, the runner
contracts' `RunnerTest` 265 s, `CoveringFilesTest` 191 s.

Selecting tests by their ids (`--test-id-filter-file`, ADR-0138) is what
the killers-only pass (ADR-0136) and a long covering set's own run already
ask for. Pest overrides PHPUnit's filter of its suite and ships that
override without the id filters, so under Pest those runs ran every test
they loaded.

A stream that a signal ended also failed the whole Pest run: Symfony's
`Process::wait()` throws where a signal ended the process.

## Decision

1. **`splitStream.coveringTestsOnly`, on by default: each stream of a pass
   of covering tests runs only the covering tests of its part**, named by
   their ids in a file of its own, beside its files. Where an id cannot be
   written so it reads back, that stream runs its whole files.
2. **Off, each stream runs every test of its files**, as before.
3. **The killers-only pass is unchanged:** it already selects its killers
   by their ids. A run planned without a fresh map (ADR-0135) streams every
   test file of its part, since no map names what covers a mutant. A quick
   round whose streams record each test's lines for the kept map (ADR-0147)
   runs whole files, so it remeasures every test of them, as before.
4. **`pest:patch` makes Pest's filter of its suite take the id filters**,
   `--test-id-filter-file` and `--test-id-filter`, as PHPUnit's own does.
   That makes ADR-0136 and ADR-0138 select as they say under Pest.
5. **A stream that a signal ends leaves what it wrote.** The test it was
   running, and any after it, have no parent result, so no stream
   decides a mutant they cover, and those mutants go to their own runs.
6. **The setting is in the Pest runner's option, so it is in key item 3**
   (ADR-0007).

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `splitStream.coveringTestsOnly` | `true`, `false` | `true` | affects results (key item 3) |

## Why the verdicts are the same

- **The reference is the mutant's own run, which runs exactly its covering
  tests** (the patch's `Selection::covering`), each row of a data set alone.
  A stream that runs only covering tests runs a subset of the whole-file
  stream's tests, and a superset of each of its mutants' own-run tests.
- **The parent is still each child's control.** A kill is a covering test
  the child fails and the parent passes at the same point (ADR-0117
  decision 4). A test the parent does not pass decides nothing, and its
  mutants go to their own runs.
- **A verdict reads only covering tests.** A test that covers none of the
  pass's mutants forks nothing the pass judges, and its result is read for
  none of them.
- **A covering test that depends on a test the selection leaves out** is
  skipped in the parent, which passes nothing there, so its mutants go to
  their own runs, which select it the same way.
- The runner contract test `StreamedTest` judges one mutant both ways. Its
  test file also holds a test that covers nothing and ends any stream that
  runs it. With the setting on, the stream judges the mutant. Off, the
  stream ends, and the mutant's own run judges it. The status and killer
  are the same both ways.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep whole files | A stream runs tests no verdict reads, and its part's balance is sized from tests it does not run alone. |
| One ids file for every stream of a pass | PHPUnit tests every test against the list in order (`in_array`), so each stream would scan the whole pass's ids. |
| Select by `--filter` | A covering set is often too long for a filter to pass or for PCRE to compile (ADR-0138). |
| Leave Pest's filter unpatched and select by files | Files are what the pass already selects. |

## Consequences

- A stream's parent time is its covering tests' time, which is what its part
  was sized from.
- Under Pest, the killers-only pass and a long covering set's own run select
  exactly their tests.
- A stream that a signal ends, such as one killed for memory, leaves its
  undecided mutants to their own runs instead of failing the shard.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): key item 3
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): split streams
- [ADR-0135](0135-a-full-split-stream-run-starts-without-waiting-for-a-fresh-map.md): runs planned without a fresh map
- [ADR-0136](0136-a-first-pass-stream-may-run-only-the-tests-that-killed-before.md): the killers-only pass
- [ADR-0138](0138-a-covering-set-too-long-for-a-filter-is-selected-exactly.md): exact sets of test ids
- [ADR-0179](0179-a-mutant-whose-killer-the-first-pass-fell-back-on-runs-its-own-run.md): the second pass
