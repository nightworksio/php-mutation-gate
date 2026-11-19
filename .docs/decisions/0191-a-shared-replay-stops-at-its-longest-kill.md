# ADR-0191: A shared replay stops at its longest kill

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0089](0089-one-replay-to-the-end-of-a-whole-order-vouches-for-its-prefixes.md): how far the one replay of a whole order runs

## Context

Under ADR-0089, the narrowed kills of one file whose runs were to take one
whole order share one replay, which runs that order to its end. A whole
order holds every test the run selected, so a file covered by many tests
replays them all, even where every kill came from one of the first few.
Before ADR-0089 no replay ran a test past its kill's last killer.

## Decision

1. **The one replay of a whole order stops at the last killer of the kill
   that reached furthest.** Of the replays that share a whole order, the
   first of those that reach furthest runs, under its own key, allowed the
   longest limit of theirs. Where it stands, it vouches for each of them;
   where it does not, each runs on its own, as before.

## Why no verdict moves

- **Every kill's prefix is a prefix of the longest.** The runs were to take
  one whole order, by its digest, and each took its tests in it up to its
  last killer. The shorter kills' prefixes are therefore prefixes of the
  longest kill's prefix.
- **It checks what each prefix replay checks.** A prefix replay stands where
  every test up to its killer passes, unmutated, after the tests before it.
  The longest replay that stands has every test up to the furthest killer
  pass after the tests before it, and that covers every shorter killer.
- **The tests past the last killer judge nothing.** No kill's replay ran
  them before ADR-0089. A failure among them only sent every kill to its own
  replay, and that replay ends before them.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep the replay to the end of the whole order** | It runs tests no kill reached, which only sends every kill to its own replay where one of them fails. |
| **Keep the end of the whole order as a setting** | Both values give the same verdicts, so the setting would choose only the cost. |

## Consequences

- A shared replay costs the tests up to the furthest killer, not the whole
  order.
- Where the furthest killer is the order's last test, the replay is the
  same as before.

## Related

- [ADR-0089](0089-one-replay-to-the-end-of-a-whole-order-vouches-for-its-prefixes.md): one replay for a whole order
- [ADR-0143](0143-a-kills-control-replays-its-order.md): a kill's control replays its order
