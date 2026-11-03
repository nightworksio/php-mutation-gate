# ADR-0062: A change that reaches no unit keeps the verdict of the commit it is read since, and runs no survivors

**Status:** Accepted
**Date:** 2026-11-03
**Amends:** [0005](0005-what-a-change-reaches-is-what-is-mutated.md): its rule 12 and its rejected skip wherever the change reaches nothing, for a plan that runs no unit
**Amends:** [0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): its survivors re-checked first, for a plan that runs no shard

## Context

ADR-0005's rule 12 lets a plan run nothing, and its verdict stand, only where
the change since the scope's last passed commit touches no PHP, no test and
nothing that decides how the gate runs. A pull request reads its change since
the commit its merge checkout merges it onto, not since its own last passed
commit, so the rule never applies to one.

A pull request whose change reaches no unit still runs the whole verdict over
every carried result. #475, #494, #505, #506, #508, #515 and #516 each cut no
shard, and their mutation jobs took 80 minutes of job time, 115 billed: per
run, the verdict 6.1 to 6.6 minutes, the survivors re-check 0.4 and its
delivery 0.2, beside a plan of 1.8. On #515 the verdict step took 355 seconds,
for 2,220 carried units and 0 run. Profiled locally on the same plan, ledger and map,
judging the carried results took 4% of that step and reporting the 39,017
carried mutants the rest: the kept JSON report, the JSON report again, the
step summary and the console.

ADR-0005 rejected skipping wherever the change reaches nothing, because the
verdict also reads the ignores' days, the baseline and the reports' config,
which no reach rule counts. A carried result is the default branch's own, so
where that branch's verdict passed on exactly these results, and nothing else
the verdict reads moved, that verdict is the one the pull request's would
give.

## Decision

1. **A plan that runs no unit stands on the verdict of the commit its change
   is read since, where all of these hold:**
   - the run is a standard one (no `--security`, no `--suite`, first
     killers), reading its change since a ref, takes no result of its own
     scope, and has a config that reads no file it cannot name;
   - the default branch's ledger records that commit as passed, under the
     run's own `ci.check`, with when it passed, and holds no result a run
     left after then, so every result it holds is one that verdict judged;
   - what changed from that commit itself is what changed since its merge
     base with HEAD, so the change is read since that commit, not since a
     commit it does not descend from;
   - no path that change touched, as it is and, for a rename, as it was, is in
     a tree, so no unit came or went, is the baseline, or holds the config:
     a file by any name the gate finds its config by, the file the run names,
     or a file the config reads beside itself. A change to the config that
     moves only what judges, such as a tree's floor, an ignore or the floor
     for new code, reaches no unit (ADR-0005, rule 1), and still moves the
     verdict;
   - no ignore that applied when that commit passed has expired since, judged
     on the days of the clock that reads now; the verdict checks this again;
   - for a pull request or a merge group, the baseline does not require each
     raise to be committed with it (`baseline.improvement` is not `require`),
     since a raise is judged on a proposed change alone.
2. **The plan says so, and the verdict takes the standing path.** The plan
   file records the commit under `reachedNothingSince`, beside rule 12's
   `unchangedSince`, and the reach reasons as ever. The verdict passes, says
   `The default branch's earlier verdict at <commit> stands.` in place of the
   project's score and why it stands, and records the commit the plan was
   made on as passed, with the earlier commit's `ownScopeProofs` and
   `ownScopeCoverage`. It writes no proof and no lesson, and reads no shard's
   result and no coverage map. Anything else, and the verdict judges the
   carried results as ever.
3. **`plan --ci=github` says whether a verdict stands.** It writes
   `stands=true` beside `shards` and `plan` where one does, and
   `stands=false` elsewhere. The reusable workflow's plan job outputs it.
4. **A plan that runs no shard runs no survivors re-check.** The survivors
   job of the reusable workflow, and the action's re-check step, run only
   where the plan's `shards` is not `[]`: every unit's result is carried or
   proved, so a re-check signals nothing the verdict does not already hold.
5. **The verdict job reads only what it judges.** It downloads the shards'
   results, and removes those an earlier attempt left, only where the plan
   cut a shard, and downloads the coverage maps only where no verdict stands.
   The repository's own `ci.yml` and the release workflow run the gate
   through the reusable workflow, so the same gating holds for both.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Judge the carried results without the coverage map** | Reporting, not judging, takes the verdict's time, and a verdict without the map loses its suite scores and weak-test findings. |
| **Stand wherever the change reaches nothing** | A result a later failing run left, a removed unit, a changed baseline or an expired ignore each moves the verdict without reaching a unit. Each is checked. |
| **Compare the settings that only judge, at the commit that passed and now** | Reading and comparing every floor, ignore and baseline setting at two commits adds a second reading of the config to every plan that cuts no shard, for a change that is rare in one that reaches no unit. Refusing any change to the config is never wrong. |
| **Stand on any passed commit the change descends from** | The default branch's commits between it and the commit the change is read since were never reached by this change, so a config or lock they moved would go unread. |
| **Copy the default branch's results into the pull request's ledger** | The pull request's runs read the default branch's ledger already, and the copy would grow every pull request's ledger by the whole project's results. |
| **Report the default branch's scores** | The ledger holds results, not scores, and judging them to report the scores is the cost this removes. |

## Consequences

- A pull request that reaches no unit, read since a commit the default branch
  passed, spends a fetch, a plan, a short verdict and the deliveries: no
  shard, no survivors and no delivery of them.
- Its verdict names no tree, scores nothing and leaves the `scores` output
  empty, as rule 12's does: every report names the commit whose verdict
  stands where it would say the project's score.
- A default branch whose newest verdict failed, or whose newest run left
  results after its last passed commit, keeps every pull request on the full
  verdict until a run passes again.
- Under `baseline.improvement: require`, the default, a pull request judges
  what it carries as ever; a push to the default branch still stands.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): rule 12, which this amends
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the ledger's `passed` record and its results
- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): survivors re-checked first, which this amends
- [ADR-0050](0050-a-pull-request-is-read-against-the-branch-it-merges-into.md): the commit a pull request's change is read since
