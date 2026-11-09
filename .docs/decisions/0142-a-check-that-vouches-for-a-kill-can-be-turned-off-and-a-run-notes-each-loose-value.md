# ADR-0142: A check that vouches for a kill can be turned off, and a run notes each loose value

**Status:** Accepted
**Date:** 2026-11-09
**Grows:** [0112](0112-every-number-a-run-goes-by-is-a-setting.md): what a run does is a setting, by the verification steps that have none; [0104](0104-suggestions-can-be-turned-off-and-each-ci-has-a-context.md): the suggestions that can be turned off, by the note of a loose value

## Context

Four steps of a shard prove that a kill or an uncovered mutant is what it
seems, and none has a setting:
- **Kill controls.** Every kill a test is named for runs its killers again on
  the unmutated file (ADR-0014, ADR-0033;
  `src/Core/Control/KillControls.php:23-38`, called at
  `src/Cli/Flow/Invoking.php:243`), and a kill no test is named for is
  controlled by its covering tests
  (`src/Core/Control/UnnamedKillControls.php:18-28`, `Invoking.php:245`).
- **Vouching for narrowed kills.** A kill in a run that loaded only its
  covering tests' files is replayed in its order, or its files run alone, and
  a kill neither vouches for runs again with every test file (ADR-0036,
  ADR-0089; `src/Adapter/Pest/MutationRun.php:218-251`).
- **Held coverage.** Before a held unit is mutated, its holding tests run
  alone under coverage, and must cover every line the whole suite covers
  (ADR-0005; `src/Cli/Flow/HeldCoverage.php:19-27`).
- **Trials.** A mutant Pest leaves uncovered on a line that is not executable
  is judged by a trial (ADR-0004; `src/Adapter/Pest/Trials.php:19-23`, called
  at `MutationRun.php:354`).

On full runs of the gate's own CI the controls step took 152 to 207
runner-minutes (17% to 28%), most of it on files whose covering tests are too
many for a filter (ADR-0138); baselines 89 to 163 (13% to 19%), and 37% of
the seven measured shards of the default branch at 9d9d11b6b; trials 30 to 40;
held coverage 324 and 316 s on two pull request runs.

A setting may loosen soundness where its default does not:
`reach.lockMoves` keeps `everything` by default and documents each looser
value in bold in the configuration reference. A setting that affects results
is in a proof's key (`src/Core/Config/Effect.php:7-24`), so a proof made under
it stands only for runs under the same value.

## Decision

1. **Four settings, each defaulting to today's behaviour:**
   - **`controls.kills`**: `true` or `false`. `false` counts a kill without
     its unmutated control, named or unnamed. It **loosens**: a kill whose
     killers fail on the unmutated code too counts, such as one a narrowed
     selection, the served file or a flaky test made, or a crash before any
     test.
   - **`pest.narrowedKills`**: `vouch` or `trust`. `trust` counts a narrowed
     kill without its replay, its alone run or its run again with every test
     file. It **loosens**: a kill the narrowed order's own state made, or a
     fixture or helper a left-out file provides, counts.
   - **`holds.coverage`**: `measure` or `map`. `map` reads the holding tests'
     lines from the map the plan handed the shard instead of running them
     alone, and no longer checks that they pass alone. Under `uncovered:
     count`, the default, a line they reach only after another test's state
     leaves its mutants uncovered, which fail the run; under `uncovered:
     exclude` those mutants drop out, which **loosens**.
   - **`pest.trials`**: `true` or `false`. `false` leaves such a mutant
     uncovered rather than tried. Under `uncovered: count` that is
     **stricter**; under `uncovered: exclude` it **loosens**.
2. **A loose value is an opt-in, marked as one.** The configuration
   reference sets each loose value, and the condition under which it
   loosens, in bold, as it does `reach.lockMoves`'. The rules here hold for
   every loose value the gate offers, these four and those of other
   decisions:
   - `outbreak.cache.trust: repository` (ADR-0131);
   - `outbreak.graph: narrow` (ADR-0132);
   - `ci.mergedOwnScopeProofs: trust` (ADR-0139);
   - `ci.trustMergedPullRequests: true` and `delta` (ADR-0005 rule 5,
     ADR-0139): a pushed tree a merged pull request's verdict passed on is
     not judged again;
   - `reach.lockMoves: named` and `loaded` (ADR-0115): a moved package
     reaches less than every unit.
3. **A run notes each loose value in force.** Where the resolved config holds
   `controls.kills: false`, `pest.narrowedKills: trust`, under `uncovered:
   exclude` `holds.coverage: map` or `pest.trials: false`, or a loose value
   of decision 2's list, the verdict and the pull request's comment each
   note the key, its value and what it lets count or carry. It is a note
   (ADR-0111): it never blocks, and a release is not held by it.
   `config:show` marks each loose value on standard error, beside the
   config it prints, whatever the setting below.
4. **`suggest.looseValues`, `true` by default, keeps that note; `false`
   turns it off**, as `suggest.emptyHolding` does its own (ADR-0116), and
   `suggest.enabled` off turns it off with every other suggestion. It judges
   or reports only.
5. **Each keeps its own effect in the key.** The four settings here affect
   results and are in key item 3 (ADR-0007): a proof made under a loose
   value never stands for a run under the strict one, nor the other way, and
   a context block that sets one keys that context's proofs apart from the
   others' (ADR-0071). The settings of decision 2's list decide how the gate
   runs: they are in no key, and a change to one carries no proof across
   (ADR-0005).
6. **None of the four acts on a mutant a split stream judged,** which runs no
   control, replay, alone run or trial (ADR-0117 decision 6); each reference
   entry says so.

### Settings

A loose value is in bold.

| Setting | Values | Default | Effect |
|---|---|---|---|
| `controls.kills` | `true`, **`false`** | `true` | affects results (key item 3) |
| `pest.narrowedKills` | `vouch`, **`trust`** | `vouch` | affects results (key item 3) |
| `holds.coverage` | `measure`, `map` (**loosens under `uncovered: exclude`**) | `measure` | affects results (key item 3) |
| `pest.trials` | `true`, `false` (**loosens under `uncovered: exclude`**) | `true` | affects results (key item 3) |
| `suggest.looseValues` | `true`, `false` | `true` | judges or reports only |

### The trade

Each loose value counts what the default doubts, or carries what the
default runs again, for the minutes that costs. The gate's defaults stay
sound. A project that takes a loose value takes it in its config, where a
review sees it, and every run notes it unless the project turns the note
off, which is again a line of its config. The four keyed values' proofs are
their own: no strict run takes them.

### Proof before it ships

- For each of the four, a fixture whose mutant the default leaves
  unjudged, doubted or failing, and the loose value counts, with the run
  noting the value; and the same fixture with `suggest.looseValues: false`,
  noting nothing.
- A test that every loose value the reference sets in bold is on decision
  2's list, and the other way.
- The gate's own suite under the defaults gives the verdicts it gives today,
  mutant for mutant.

### Where today's path stays

The defaults: `controls.kills: true`, `pest.narrowedKills: vouch`,
`holds.coverage: measure`, `pest.trials: true`.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Only values as strict as today or stricter** | It leaves a project that accepts the risk no way to spend less, against the rule that what a run does is a setting. |
| **Loose values only in the `local` context** | A project's CI is where the minutes are spent; its config is where the choice is reviewed. |
| **No mention in the run** | A loose value is a property of every verdict made under it, and a reader of the verdict should see it. |
| **A note nothing silences** | A project that chose the value in its config can choose, there too, not to read it on every run. |
| **A warning** | A warning asks for action (ADR-0111), and a project that chose the value has nothing to do. |

## Consequences

- A project can trade any of the four checks for its minutes, and its
  verdicts note which unless it turns the note off.
- Every loose value the gate offers is offered and noted alike.
- Turning one on or off re-runs the units it reaches, since it is keyed.
- Under split streams the four settings matter only for mutants that fall
  back to their own run.

## Related

- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): trials
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): held units
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): key item 3
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): kill controls
- [ADR-0033](0033-a-files-kills-are-controlled-together-first.md): a file's kills controlled together
- [ADR-0036](0036-a-kill-only-errors-made-counts-where-its-control-passes.md): what vouches for a narrowed kill
- [ADR-0071](0071-a-config-holds-a-block-for-each-context-a-run-is-in.md): context blocks
- [ADR-0089](0089-one-replay-to-the-end-of-a-whole-order-vouches-for-its-prefixes.md): replays of narrowed kills
- [ADR-0104](0104-suggestions-can-be-turned-off-and-each-ci-has-a-context.md): `suggest.enabled`
- [ADR-0111](0111-warnings-ask-for-action-and-notes-never-block.md): notes and warnings
- [ADR-0115](0115-a-lock-move-reaches-what-loaded-the-package.md): `reach.lockMoves` and its loose values
- [ADR-0116](0116-an-empty-holding-list-is-allowed-and-noted.md): a note a `suggest.*` key turns off
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): what a stream runs
- [ADR-0131](0131-proofs-are-shared-across-scopes-by-their-keys.md): `outbreak.cache.trust`
- [ADR-0132](0132-a-static-graph-places-what-the-coverage-map-cannot-and-the-map-stays-the-check.md): `outbreak.graph`
- [ADR-0138](0138-a-covering-set-too-long-for-a-filter-is-selected-exactly.md): what a control selects
- [ADR-0139](0139-a-merged-pull-request-s-pass-reaches-its-push-as-an-artifact-of-its-run.md): `ci.mergedOwnScopeProofs`, `ci.trustMergedPullRequests` `true` and `delta`
