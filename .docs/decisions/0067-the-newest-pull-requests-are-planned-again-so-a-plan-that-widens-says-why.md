# ADR-0067: The newest pull requests are planned again, so a plan that widens says why

**Status:** Accepted
**Date:** 2026-11-03

## Context

The reach rules decide how much of the gate's own CI a pull request costs.
A pull request that reaches every unit when it need not costs about twenty
shards. Recent rules came from finding such plans after they ran: a CI
definition read whole, a manifest whose description changed, fixture specs
that run code when they load, and a test file reached whole for one changed
test. Each was found by replaying a plan by hand, with a ledger and a map
that expire within a day.

The in-suite replays (`tests/Fixtures/Replays`) pin the cases that were
found. They hold the inputs of those pull requests, so they cannot show what
a new change to the rules does to the pull requests that merged since.

## Decision

1. **A `replay` job of `ci.yml` plans the ten newest merged pull requests
   again.** It uses this checkout's gate, the map `tests` wrote, and main's
   ledger, restored read-only from the Actions cache. Each pull request is
   read from HEAD's first-parent history by the `(#n)` its squashed subject
   ends in.
2. **Each pull request is planned on top of HEAD.** Two commits are made
   that are never pushed:
   - the first holds every path the pull request changed as it stood
     before it;
   - the second holds each path as the pull request left it.

   The checkout moves to the second, on the branch `main` and outside CI, so
   the plan reads no change since the ledger (ADR-0060). The gate of HEAD
   runs `plan --changed-since=<first>` from a worktree of its own, so a file
   the pull request changed in `src` never runs as the gate. A plan of no
   change on HEAD gives the units that run whatever changed, and a pull
   request's reach is what it runs beyond them.
3. **The job fails where:**
   - a reason for reaching every unit of a package quotes no path the pull
     request changed;
   - a pull request of documentation alone reaches a unit.

   Its summary holds a row for each pull request: its paths, the units it
   reached, its shards and why it widened. A pull request this checkout's
   gate cannot plan, as where a file it changed no longer fits today's code,
   is listed as skipped, with the gate's last line.
4. **It runs every night and on each pull request that changes what decides
   reach, carry or the cut** (`scope.py`'s `REPLAY`), never on a push to
   main.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Plan each pull request at its own merge commit** | The ledger and map are of HEAD, so every unit main changed since would run, and the plan would run that commit's gate, not the one under review. |
| **Revert each pull request onto HEAD** | A revert conflicts wherever a later pull request touched the same lines, which is most of the recent ones. |
| **Run on every push to main** | Ten plans on every merge spend a runner for many minutes, where one night finds the same plan. |
| **Fail on any plan that reaches more than a number of units** | A change can rightly reach much, and the reason it gives is what says whether it should. |

## Consequences

- A change to the reach rules shows, on its own pull request, what it does
  to the newest ten that merged.
- A replay reads today's map for an older change, so a test the map does not
  hold is read as today's suite has it.
- A pull request that only removed what today's code still needs cannot be
  planned, and is listed as skipped.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): the reach rules
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the cut
- [ADR-0055](0055-each-gated-job-runs-for-its-own-inputs-and-each-runner-leg-for-its-own-test-support.md): the job inputs `scope` gives `replay`
- [ADR-0060](0060-a-result-carries-only-for-the-code-it-is-of.md): the change since the ledger, which a replay does not read
