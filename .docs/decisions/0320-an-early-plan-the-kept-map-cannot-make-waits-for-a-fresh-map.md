# ADR-0320: An early plan the kept map cannot make waits for a fresh map

**Status:** Accepted
**Date:** 2026-11-21
**Amends:** [0135](0135-a-full-split-stream-run-starts-without-waiting-for-a-fresh-map.md): what a plan that may start early does where the kept map cannot plan the run

## Context

A full split-stream run that may start early plans against the map kept
beside the default branch's ledger (ADR-0135). The plan admits that map as
it admits a fresh one: each hold written in a holding suite (ADR-0005) must
run a line of what it holds there, or the run cannot be judged.

The kept map is the one the last passing run on the default branch kept. On
the gate's own repository that run was at f033341f1 (2026-11-19); 19 test
files that hold a path from `Process` or `Contract` were added after it, among
them `tests/Process/Adapter/Git/StatusLookTest.php`, which holds
`src/Adapter/Git/StatusLook.php`. The kept map has no line of their tests. The
scheduled run 44895804699 on a5525f28a, under `unproven`, which always starts
early, stopped in `mutation-early / plan` with exit code 2:

    holds:src/Adapter/Git/StatusLook.php in the holding suites runs no line of src/Adapter/Git/StatusLook.php, so it judges none of its mutants.

The hold is sound: the plans that read a fresh map since, such as
`mutation / plan` of run 44861579327, admitted it. Since the early plan
failed, `mutation` did not run, no run on the default branch could pass, and
no newer map could be kept: every scheduled run would stop the same way.

## Decision

Where the kept map cannot plan a run early, for any reason the plan gives, the
run takes the path it takes when no map is kept:

- a plan asked to plan early alone (`--early-only`) plans nothing, as it does
  where the run cannot start early, and says why the kept map could not plan
  it; the caller that hands a fresh map runs the run;
- any other plan measures a fresh map and plans by it.

## Why no verdict moves

The plan made by the fresh map admits, keys and cuts the run as every plan
that does not start early does, and a hold that runs no line of what it holds
in the fresh map still stops it with the same message.

## Alternatives considered

- **Leave the holds unchecked against the kept map**: the map an early run's
  streams record is checked for held units (ADR-0135), but nothing would check
  a hold from the holding suites, and a hold that judges none of its mutants
  would pass unnoticed.
- **Refuse only a stale kept map**: the plan cannot tell a stale map from a
  hold that runs nothing without measuring a fresh map, which is the path this
  decision takes.

## Consequences

- The scheduled run on a5525f28a would have planned nothing early and left
  the run to `mutation`, which plans by the map the `coverage` job hands it.
- A run that waits starts its shards after the fresh map, as a run that does
  not start early does.
