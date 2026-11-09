# ADR-0136: A first-pass stream may run only the tests that killed before

**Status:** Accepted
**Date:** 2026-11-09
**Amends:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): decisions 8 and 9, what a stream of the first pass runs, where `splitStream.killerStreams` is on

## Context

Under split streams with `splitStream.killersFirst` (ADR-0117 decision 8), a
mutant forks first at the test the ledger last saw kill it, and one its
killer did not kill runs in a second pass, forking at every covering test.
The streams themselves run every test file of their part in the suite's
order: a stream is unnarrowed by design (ADR-0117, "No narrowing").

The ledger keeps a killer history for every mutant that learned one, with no
cap on how many mutants keep theirs (`proofs.keep.killers`, `all` by default;
`src/Core/Proof/LedgerRetention.php:43-44`), and each killed mutant's proof
names its killers (ADR-0007). On the map and shard results of run
42494457581:
- the map holds 14,155 tests, 662.0 s of test time;
- the union of every mutant's killers is 7,502 tests, 409.3 s (61.8%);
- one killer per mutant is 6,758 tests, 296.8 s (44.8%).

So a first-pass parent that ran only killers would run 38% to 55% less test
time. Streaming the killers' files instead gains nothing: they hold 1,520 to
1,597 of 1,794 test classes, 92% to 94% of the suite's time.

In the model of a split-stream full run, the parents are about 1,000 of
about 2,300 core-seconds of the shards; this takes 16% to 24% off the
shards' compute.

## Decision

1. **`splitStream.killerStreams` makes the first pass stream only killers.**
   Where it is on, a part's first-pass parents run, in the suite's order, only
   the tests the ledger names as a killer of an undecided mutant of the part,
   selected by an exact set of test ids (ADR-0138), and each mutant forks at
   its killer as ADR-0117 decision 8 says. The second pass is unchanged: every
   mutant the first pass did not kill streams the test files that cover it,
   forking at every covering test.
2. **It is `false` until the A/B below lists no mutant whose status differs,
   and `true` by default from then.**
3. **It affects results and is in key item 3** (ADR-0007): a proof made with
   it never stands for a run without it.
4. **It does nothing for a mutant that runs its own run** (ADR-0117 decision
   7), and its reference entry says so.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `splitStream.killerStreams` | `true`, `false` | `false` until the A/B is clean, then `true` | affects results (key item 3) |

### Why a kill it makes is a kill

- **The parent is still the control.** A first-pass kill is a test the child
  fails and the parent passes at the same point (ADR-0117 decision 4), so it
  needs no control run.
- **A mutant it does not kill is judged as today,** in the second pass, by
  every covering test in whole files.
- **It can differ only toward a kill.** A killer that kills a mutant after
  only the earlier killers ran, where it would not after every earlier test
  of its file, is a real test failing in a real, shorter order that passes
  unmutated. That is the class of kill a narrowed run already counts after its
  verification (ADR-0036). The gate's own `test files alone` job shows every
  test file passing on its own (ADR-0034), and a test the parent fails kills
  nothing.

### Proof before the default turns

An A/B of the gate's own run on one commit: whole-file first passes against
killer-only first passes, listing every mutant whose status differs, each
with the test and the order that made the difference.

### Where today's path stays

`splitStream.killerStreams: false`, a part with no killer in the ledger, a
ledger that cannot be read, and any mutant a stream falls back on run whole
files, as ADR-0117 decides.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Stream the killers' test files** | They hold 92% to 94% of the suite's time. |
| **On by default at once** | A kill in a shorter order can differ from the whole-file stream's; the A/B shows whether any does first. |
| **Leave it a setting, off for good** | It would leave 16% to 24% of the shards' compute in place on every run once the A/B shows nothing differs. |

## Consequences

- A first pass costs the killers' test time, not the part's.
- A project's first run on an empty ledger has no killers, and streams whole
  files.
- Until the A/B is clean, the setting exists and is off.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): key item 3
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the killer history
- [ADR-0034](0034-a-suite-whose-test-files-pass-alone-need-not-confirm-survivors.md): `test files alone`
- [ADR-0036](0036-a-kill-only-errors-made-counts-where-its-control-passes.md): a narrowed kill's verification
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): split streams and killers first
- [ADR-0138](0138-a-covering-set-too-long-for-a-filter-is-selected-exactly.md): exact sets of test ids
