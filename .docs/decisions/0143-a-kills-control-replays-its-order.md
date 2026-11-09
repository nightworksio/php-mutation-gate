# ADR-0143: A kill's control replays its order

**Status:** Accepted
**Date:** 2026-11-09
**Amends:** [0014](0014-every-test-is-judged-by-what-it-kills.md): what a kill's control runs
**Amends:** [0108](0108-no-shortcut-stands-on-a-result-its-tests-or-its-evidence-no-longer-hold.md): decision 9's controls, which ran the killers alone

## Context

A kill's unmutated control ran the tests that killed it, alone (ADR-0014,
ADR-0033). A test that fails only after the tests before it then counts as a
kill: run alone, the killer passes on the unmutated code, and nothing shows
that it fails in its run's order whatever the mutant does. This held for the
native runners, for Pest runs that load every test file, and for Infection.
Pest's narrowed runs already replay their own order (ADR-0089).

## Decision

1. **A kill's control replays its order.** The native runners record the
   tests a mutant's run started, up to its last killer, in the kill's
   evidence, and its control replays them. Pest replays its own order for
   runs that load every test file, as it does for narrowed runs. Infection
   records no order, so its control runs every test the mutant's run
   selected.
2. **A replay to the end of an order vouches for every shorter one of its
   file**, and each other kill has its own replay, controlled on its own
   where the longer one does not pass.
3. **`flaky.killControls: alone` runs the killers alone instead**, as
   before: quicker, and without the proof. `doctor` advises against it
   (`kill-controls-alone`). A narrowed Pest run's replay stays under
   `alone`, since it vouches for the files the run left out.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `flaky.killControls` | `order`, `alone` | `order` | affects results |

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep the killers alone** | A kill that needs the tests before it to fail stands. |
| **Replay every test of the run** | Tests after the last killer cannot make it fail, and cost time. |

## Consequences

- Kill controls cost more outside Pest: a native run replays each distinct
  order of a file, and Infection each distinct selection of tests, where one
  control of a file's killers ran before.

## Related

- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): kill controls
- [ADR-0033](0033-a-files-kills-are-controlled-together-first.md): a file's kills controlled together
- [ADR-0089](0089-one-replay-to-the-end-of-a-whole-order-vouches-for-its-prefixes.md): Pest's narrowed replay
- [ADR-0108](0108-no-shortcut-stands-on-a-result-its-tests-or-its-evidence-no-longer-hold.md): no shortcut stands on evidence that no longer holds
