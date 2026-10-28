# ADR-0033: A file's kills are controlled together first

**Status:** Accepted
**Date:** 2026-10-20

## Context

ADR-0014 gives each kill a test is named for an unmutated
control: its killers, run with its file served as its mutant was, once for
each file and set of killers. The control guards against three things: a
killer that fails on its own, one that fails only while the runner serves a
file, and one that runs out of its limit.

A full run of the gate on `main` (run 39373341555) ran 7,419 such controls,
2.8 shard-hours, about 1.3 seconds each, most of it a runner starting. The
coverage map times 7,535 of the 7,538 sets of killers as passing on the
unmutated code. Only the served file is new to them: #402 was a test that
failed only while Pest's override served a file.

## Decision

1. **Each file's kills are first controlled together.** One control runs
   every test that killed any mutant of the file, with the file served
   unmutated as its mutants were, allowed the standard mutant limit of their
   time. Where it passes, every kill of the file a test is named for stands.
2. **Where it does not pass, each kill is controlled on its own**, as
   ADR-0014 says, once for each set of killers, and stands only
   where its own control passes. A kill whose own control is the file's
   control is judged by that control's run.
3. **A file's control names no failing test.** Where it fails and each
   kill's own control passes, the tests fail together but not on their own,
   and every kill stands. Only a control that decides a mutant, a kill's own
   among them, makes the verdict *cannot judge* for tests that fail without
   any mutant (ADR-0008).
4. **The time budget leaves a kill unjudged** where the time left had no room
   for its file's control, or for its own where it needed one.

This supersedes "once for each file and set of killers" in ADR-0014, as
the first control of a kill.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **The coverage map as each kill's control** | The map's run never served a file through the runner, so it cannot tell a test that fails only then. |
| **One control per kill (ADR-0014 as it stood)** | It starts a runner for every set of killers, though nearly every set passes. |

## Consequences

- A full run starts about one control per file with kills, not one per set of
  killers. Where tests of a file fail together but pass apart, the per-kill
  controls still judge each kill as before, at the price of one more run.
- Every runner is held to it, as ADR-0014 holds them.

## Related

- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): the unmutated control
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): which this groups by file
