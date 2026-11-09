# ADR-0141: A failed run on the default branch still stands for the units it fully judged

**Status:** Accepted
**Date:** 2026-11-09
**Amends:** [0060](0060-a-result-carries-only-for-the-code-it-is-of.md): rule 1, the commit a change off the default branch is read since; [0005](0005-what-a-change-reaches-is-what-is-mutated.md): decision 2, what a push to the default branch reads its change since; [0062](0062-a-change-that-reaches-no-unit-keeps-the-verdict-it-is-read-since.md): decision 1, the verdict a change read since a judged run stands on; [0059](0059-every-run-on-the-default-branch-finishes-and-writes-its-ledger.md): decision 2, what the ledger every default-branch run writes records of a failed run
**Grows:** [0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): decision 3, the ledger, by the record of its scope's last judged run

## Context

Every run on the default branch finishes and writes its ledger (ADR-0059).
The verdict writes a proof of every unit that ran to the end whether it
passed or failed (`src/Cli/Flow/Recorded.php:39-51`, `:181-200`), and the
reusable workflow's `deliver` job writes the ledger after a failed verdict as
after a passed one (`.github/workflows/mutation-gate.yml:1194-1200`). What a
failed verdict does not move is `passed`: a commit is recorded as passed only
where its verdict passed with nothing unjudged
(`src/Cli/Flow/Judging.php:555-566`).

Off the default branch, a run reads what the default branch changed since the
commit its ledger last passed at, besides its own change (ADR-0060 rule 1;
`src/Cli/Flow/Mode.php:143-152`). Each such file reaches what it would reach
in the run, with the reason "On the default branch since its ledger last
passed, at `<commit>`: …" (`src/Core/Reach/Reach.php:30`). So while the
default branch is red, every pull request reaches whatever the default branch
changed since its last pass, however many later runs judged those changes.

A reached unit runs unless a proof under its present key stands; an unreached
unit carries its newest result of its source as it is now (ADR-0060 rule 2).
Wider reach therefore costs runs for units whose key the pull request's own
change moves without reaching them: a unit whose tests execute a source file
the pull request changed (key item 10), or a held unit, or one whose values
are judged by reference, after any test change, since their keys hold every
test file (ADR-0007). Unreached, such a unit carries its result; reached, it
runs.

Pull request plans of the gate's own CI from 2026-11-06 22:12 to 2026-11-07
04:34 read "On the default branch since its ledger last passed, at
25413d54e: `.github/workflows/ci.yml` decides how the gate runs, so every unit
is reached", while the default branch's push run 42322297592 had failed. How
many runs this costs is not measured.

## Decision

1. **Every default-branch verdict that writes the ledger records the run it
   judged**, passed or failed: `judged`, holding the commit, the check it
   reported under, when it ended, and the units it considered and left open.
   A run that drew a sample, or judged one suite or the security set alone,
   records none. A unit
   is left open where the run holds no proof of it under its key at that
   commit: it was left unjudged or flaky, could not be judged, was stopped by
   a budget, or was sampled out. Every unit the run considered and proved, by
   running it to the end or by a proof under its present key, is fully
   judged there.
2. **`reach.defaultBranchSince` says what the default branch's changes are
   read since:** `judged`, the default, `judged-off-branch` or `passed`.
   - **`judged`**, for every run. Off the default branch, a run reads what
     the default branch changed since the newest `judged` commit, for every
     unit, and, for the units that run left open, also what it changed since
     `passed`. A push to the default branch reads its own change the same
     way: since the newest `judged` commit for every unit, and since
     `last-passed` for the units that run left open. Where no `judged` record
     is newer than `passed`, both read since `passed`.
   - **`judged-off-branch`**: as `judged` off the default branch; a push to
     the default branch reads its change since `last-passed`, as today.
   - **`passed`** is today's, for every run: what the default branch changed
     since `passed` reaches.
   - Each reason names its commit: "On the default branch since its last
     judged run, at `<commit>`: …".
   - It decides how the gate runs and is in no key, as the other `reach`
     settings are.
3. **`passed` keeps its meaning.** It is still the newest commit whose
   verdict passed: the base `last-passed` names (ADR-0005), what a change
   that reaches nothing stands on (ADR-0062), and what a merged pull
   request's trust reads (ADR-0005 rule 5).
4. **A change read since a judged run that reaches no unit stands on that
   run's verdict** (ADR-0062). A failed run's verdict therefore fails the
   next run again, until a change reaches the units that failed it; it never
   stands on `passed`'s verdict across a failure.
5. A ledger without `judged`, written by an earlier gate, reads as one whose
   last judged run is its last passed one.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `reach.defaultBranchSince` | `judged`, `judged-off-branch`, `passed` | `judged` | decides how the gate runs |

### Why a carried result is still of its code

- **A fully judged unit's result is of the judged commit's code.** It holds a
  proof under a key of everything its result could depend on, as the code
  stood at that commit (ADR-0007). What the default branch changed before
  that commit is in that key, so only what it changed since can make the
  result stale, and that still reaches.
- **A unit the run did not consider carried across every change since
  `passed`**, because nothing that changed reached it; its result is of the
  judged commit's code too.
- **A unit left open has no result of that commit's code,** so it reads
  since `passed`, as every unit does today.
- **A failure stays a failure.** A unit whose survivor failed the run is
  fully judged: its proof holds the survivor, and a run that carries it
  carries the survivor into its own verdict. A run that reaches nothing
  stands on the failed verdict, not on the last pass.

### Proof before it ships

A fixture repository whose default branch passes at one commit, then changes
a test file that moves a held unit's key and fails at the next on another
unit's survivor. A pull request at the second commit that changes neither:
- under `passed`, the held unit is reached and runs;
- under `judged`, it carries the failed run's proof, and the survivor's unit
  carries its survivor;
- a unit the failed run left unjudged is reached under both;
- a push to the default branch that changes only documentation fails again
  under `judged` and `judged-off-branch` alike, standing on, or carrying,
  the failed run's survivor.

### Where today's path stays

`reach.defaultBranchSince: passed`, a ledger with no `judged` record newer
than `passed`, and every unit a run left open read since `passed`.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Move `passed` on a failed run** | `passed` also decides what a change that reaches nothing stands on, and what a merged pull request's trust reads; a failed verdict cannot stand for either. |
| **Read since the newest `lastRun`** | `lastRun` is written only where a run judged every unit it considered; one unit left open would keep every unit reading since `passed`. |
| **Per-unit commits taken from each proof** | A proof records its commit only where the working tree held nothing more, and git may no longer hold it (ADR-0060). |
| **Let failed runs write no proofs** | They already write them; the cost is in what reach reads since. |

## Consequences

- A red default branch no longer widens every pull request's reach to all it
  changed since its last pass: only the units its last run left open read
  that far back.
- The ledger holds one more record, of the newest judged run, with the list
  of the units it left open.
- `reach.defaultBranchSince: passed` restores today's reach.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): `last-passed`, and the trust of rule 5
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the key, the ledger, `passed` and `lastRun`
- [ADR-0059](0059-every-run-on-the-default-branch-finishes-and-writes-its-ledger.md): every default-branch run writes its ledger
- [ADR-0060](0060-a-result-carries-only-for-the-code-it-is-of.md): what changed since the ledger, and what an unreached unit carries
- [ADR-0062](0062-a-change-that-reaches-no-unit-keeps-the-verdict-it-is-read-since.md): the verdict a change that reaches nothing stands on
- [ADR-0106](0106-carried-survivors-are-judged-again-and-their-re-check-is-bounded-and-shared.md): carried survivors
