# ADR-0138: A covering set too long for a filter is selected exactly

**Status:** Accepted
**Date:** 2026-11-09
**Amends:** [0004](0004-pest-and-infection-behind-one-runner-port.md): a mutant's own run under Pest, where its filter cannot be passed; [0033](0033-a-files-kills-are-controlled-together-first.md): what a file's control runs of kills whose runs left their filter out

## Context

Under `pest.patch`, a mutant's own run passes its covering tests to
PHPUnit as one `--filter` regex. An argument of 100,000 bytes or more, or one
PCRE does not compile, is left out (`src/Adapter/Pest/Ceiling.php:20`,
`src/Adapter/Pest/Selection.php:92-95`, the patch's
`src/Adapter/Pest/Patch.php:243-257`), since the operating system bounds a
command line in bytes. The run then executes every test of the test files it
loaded. Its kills are controlled "as that run selected its tests, by the test
files it loaded" (`src/Core/Control/KillControls.php:23-38`; `ownOf`
`:144-157`, `forFiles` `:168-190`, `loadedByFile` `:218-230`), so a control
re-runs every test of every loaded file. A control whose killers' own filter
does not fit falls back to the tests that judge the request, in the gate's
own run the whole suite (`src/Adapter/Pest/UnmutatedRuns.php:183-191`).

The same ceiling bounds the test files a narrowed run loads: where the paths
joined pass it, `CoveringFiles::of` returns none, and the run loads every test
file (`src/Adapter/Pest/CoveringFiles.php:64-75`, `:147`).

A "hot" file here is one whose covering tests' ids sum to 100 KB or more,
read from the map. Each of three full runs of the gate's own CI killed
mutants in 283 to 288 hot files. A least-squares fit of each shard's control
seconds on its killed hot and other files:

| Run | Controls | Per hot file | Per other file | Hot share |
|---|---:|---:|---:|---:|
| 42493050329 | 9,092 s | 27 s | 1.2 s | 87% |
| 42494457581 | 11,590 s | 34 s | 1.2 s | 83% |
| 42493881214 | 12,423 s | 37 s | 0.6 s | 84% |

On the default branch at 9d9d11b6b, which controls one file's unfiltered
kills together, seven measured shards of run 42509457471 spent 3,068 of
18,110 s in controls, and the fit puts 51 s on each hot file and about 93% of
control time on them. In shard 10 of run 42494457581, the killers of its 34
hot files take 2.7 s of the map's time in all, and the test files holding
them 11,079 s; a killed mutant's killers are a median of one test.

Controlling hot files' kills as every other file's are, at about 2 s each,
saves 121, 152 and 165 runner-minutes on those three runs, 19% to 22%, and
takes their slowest shard from 60 to 37, 55 to 40 and 140 to 106 minutes.

## Decision

1. **`pest.longFilter` says what a run and its control select where the
   covering tests' filter cannot be passed:** `set`, `until-killer` or
   `files`.
   - **`files`** is today's: the run leaves its filter out and runs every test
     of the files it loaded, and its kill's control runs them again.
   - **`set`**: the patched plugin writes the run's covering test ids, one a
     line, to a file beside the run's results, and starts the mutant's run
     with PHPUnit's `--test-id-filter-file` naming it, which selects exactly
     those ids, each row of a data set alone, as the filter would. It applies
     where the PHPUnit Pest runs reads such a file (13.2 and later) and reads
     every id back from it; elsewhere the run is as under `files`. A kill is
     then controlled by its killers alone, as a filtered run's kill is
     (ADR-0014, ADR-0033), and a control whose killers' filter does not fit
     selects them the same way, in place of the tests that judge the request.
   - **`until-killer`**: the run is as under `files`; its kill's control runs
     the loaded files' tests in the run's own recorded order (ADR-0089) and
     stops once its last named killer has finished. A file's control of
     several such kills keeps each run's order.
2. **Under `set`, the same file carries the test files a run loads.** Where
   the files a narrowed run needs, joined, pass the ceiling, they are written
   to the file and named, so the run loads those files and not every test
   file.
3. **`pest.longFilter` is `files` until the proof below is done, and `set`
   by default from then.** It affects results and is in key item 3 (ADR-0007).
4. **The ceiling stays for what goes on a command line.** A filter that fits
   is passed as a filter, as today, under every value.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `pest.longFilter` | `set`, `until-killer`, `files` | `files` until the proof is done, then `set` | affects results (key item 3) |

### Why verdicts stay sound

- **Under `set`, a hot file is selected as every other file is.** Its run
  executes exactly its covering tests, and its kills are controlled by their
  killers, as the rest of the gate already does. Narrowed kills keep their
  replays, alone runs and confirmation (ADR-0036, ADR-0089).
- **A verdict can differ only where the map has a gap.** A mutant killed
  today by a test that covers no line of its file is not run against that
  test under `set`; it survives, and fails the run rather than passing it. A
  kill today left unjudged because another, unrelated test of a loaded file
  fails unmutated stands under `set` where its killers pass unmutated, as it
  would in any other file.
- **Under `until-killer`, a test after the last killer never ran in the
  mutant's run**, which stops at its first failure (`--bail`,
  `src/Adapter/Pest/Patch.php:255`). A control that stops there reproduces
  everything the run executed. It differs from today only where a test after
  the last killer fails unmutated, which today leaves the kill unjudged.

### Proof before the default changes

1. A property test: for random sets of test ids, the set selects exactly what
   the regex filter selects wherever the regex fits.
2. An A/B of the gate's own run on one commit, `files` against `set`,
   comparing each mutant's status and killers. Every difference is listed and
   explained.
3. On one run, a count of the kills `until-killer` lets stand that `files`
   leaves unjudged.

### Where today's path stays

- `files`, and every run whose filter fits.
- A set file that cannot be written leaves its run to `files`, and says so.
- Without `pest.patch`, Pest selects as it does.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Raise the ceiling** | The operating system bounds each argument and the whole command line; a hot file's covering ids pass any bound a process can start under. |
| **Pass the filter through the environment** | The environment shares the command line's bound. |
| **A group per run, written into the test files** | It edits the project's tests for a run's selection. |
| **`until-killer` alone** | Its gain depends on where the killer falls in the loaded order, and the run itself still executes every loaded test before it. |

## Consequences

- A hot file's kills cost a control of their killers, about 2 s, in place of
  minutes of whole files.
- A narrowed run whose test files are too many to name on a command line
  still loads only those files.
- `files` and `until-killer` stay selectable for a suite whose map misses
  tests that kill.

## Related

- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): a mutant's own run under Pest
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): key item 3
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): kill controls
- [ADR-0033](0033-a-files-kills-are-controlled-together-first.md): a file's kills controlled together
- [ADR-0036](0036-a-kill-only-errors-made-counts-where-its-control-passes.md): what vouches for a narrowed kill
- [ADR-0089](0089-one-replay-to-the-end-of-a-whole-order-vouches-for-its-prefixes.md): a run's recorded order
- [ADR-0136](0136-a-first-pass-stream-may-run-only-the-tests-that-killed-before.md): a first pass that selects killers by set
