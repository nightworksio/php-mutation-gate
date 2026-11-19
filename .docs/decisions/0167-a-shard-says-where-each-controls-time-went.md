# ADR-0167: A shard says where each control's time went

**Status:** Accepted
**Date:** 2026-11-17
**Amends:** [0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): what a shard's measured steps hold

## Context

A shard's result names each step its time went to, when it started, how
long it took and how many it handled (ADR-0016). Its controls step says
how many unmutated controls ran side by side and how long they took
together, and nothing of any one of them. On the gate's own code, run
43934027017 spent 1,075 s of one shard's 2,177 s on 26 controls, three
quarters of them in steps where one control ran for three to six minutes
while every other had ended, and the result could not tell which control
that was, what asked for it, how many tests it ran or how it ended.

## Decision

1. **A step that runs unmutated controls lists each of them under
   `controls`:** what asked for it, `kinds` (`timeout`, `kills`, `kill`,
   `unnamed kill`, `memory`, `heavy part`); the tests it asked for,
   `tests`; the test cases its log names, `cases`, where it kept one; the
   `limit` it was allowed; the `seconds` its process lasted, whatever its
   end, where it ran as a process of its own; and its `end`. A control two
   kinds asked for names both.
2. **The JSON report's shards carry them as the result holds them.**

## Why no verdict moves

The controls run as before; the step only says more of each.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Log each control's command line | The progress line already cuts it short, and a log is read by a person; the result is read by the cost model and the report. |
| Time only the longest control of a step | The longest is the question, but which kind asked for it and how many tests it ran is the answer. |

## Consequences

- A result grows by a few lines for each control a shard ran.

## Related

- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md)
- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md)
