# ADR-0139: A merged pull request's pass reaches its push as an artifact of its run

**Status:** Accepted
**Date:** 2026-11-09
**Amends:** [0005](0005-what-a-change-reaches-is-what-is-mutated.md): rule 5, where a pushed commit reads a merged pull request's pass, what a verdict that used its own scope's proofs is trusted for, and a tree that differs; [0049](0049-an-owner-is-mentioned-once-and-a-merge-group-trusts-its-pull-request-as-a-merged-one.md): decision 6, where a merge group reads it

## Context

ADR-0005 rule 5 spares a commit on the default branch a re-check where
`ci.trustMergedPullRequests` is true (false by default): its tree is the tree
of the head of a pull request merged into the default branch, the latest
check-run under `ci.check` on that head concluded in success, and the pull
request's own ledger records that head as `passed` with no proof of its own
scope used (`src/Adapter/GitHub/MergedHeads.php:23-35`). Where the run cannot
read the pull request's ledger, it proves nothing, and the range is mutated
in full. The setting decides how the gate runs
(`src/Core/Config/Definition/CiKeys.php:34`), and turning it on reads each
pull request's ledger from the run's proof store
(`src/Cli/Flow/Wiring.php:342-351`).

Under the package's reusable workflow that ledger is out of reach. With the
default directory store, a pull request's ledger lives in its own Actions
cache scope, and a push to the default branch restores only its own ref's
entries and the default branch's (ADR-0013). With a remote store, a pull
request's run holds no key to write it (ADR-0007, ADR-0029). The setting
therefore takes effect in no setup the package ships.

What the push re-judges, measured over a window of the gate's own CI and
the companion's:
- the gate: 58 of 144 pushes had their pull request head's exact tree, and
  spent 15,708 shard-minutes, about 7,450 a week;
- the companion: 41 of 51 pushes had the exact tree, 3,435 shard-minutes.

Pull request run 42485819353 took 641 shard-minutes; the push of the same
tree, run 42493050329, ran its 19 shards again, 561 shard-minutes.

## Decision

1. **A pull request's verdict leaves its pass as an artifact of its run.** On
   `pull_request`, the reusable workflow's verdict job uploads
   `mutation-gate-passed`, holding the `passed` record the verdict wrote into
   its scope's ledger (ADR-0007: the head, its tree, the check, `at`,
   `ownScopeProofs` and `ownScopeCoverage`), where the verdict passed, with
   the units the verdict took from its own scope's proofs (decision 6), and
   the scope's ledger as the verdict wrote it (decisions 6 and 7). It is
   kept for the repository's own artifact retention.
2. **A push reads it through the REST API.** On a push to the default branch
   with `ci.trustMergedPullRequests` `true` or `delta`, `MergedHeads` finds,
   for each pull request merged into the default branch whose head tree is
   the pushed commit's, or under `delta` each one the pushed range merged,
   the newest completed `pull_request` run of the workflow that runs the gate
   at that head, and reads its `mutation-gate-passed`. The record stands
   only where it names that head and its tree and passes every check of
   ADR-0005 rule 5, the equal tree excepted under `delta`. The plan job asks
   for `actions: read`, which only reads.
3. **A merge group reads the same artifact** in place of its pull request's
   ledger, under the same setting (ADR-0049 decision 6).
4. **The proof store stays as it is.** A pull request's ledger is still
   written to its own scope alone. The default branch reads it only from the
   run's artifact, where a push carries some of the pull request's results
   (decisions 6 and 7), and takes from it only the results those decisions
   allow.
5. **The gate's own config turns it on.** `mutation-gate.json` sets
   `ci.trustMergedPullRequests: true`. For every other project it stays
   `false` by default. **`true` loosens**: the push does not judge the tree
   the pull request's verdict passed on. It is an opt-in under ADR-0142's
   rules, so the gate's own runs note it unless `suggest.looseValues` is
   off.
6. **`ci.mergedOwnScopeProofs` says what a verdict that used its own
   scope's proofs is trusted for:** `reach`, the default, `refuse` or
   `trust`. `proofs.ownScope` is `true` by default (ADR-0081), so a pull
   request pushed more than once usually ends with such a verdict.
   - **`reach`**: the push carries the pull request's results for the units
     its verdict computed itself or took from the default branch, and reaches
     the units the record lists as taken from its own scope, which an earlier
     commit of the pull request's may have written. A record that lists none
     spares its whole tree, as ADR-0005 rule 5 does today.
   - **`refuse`**: ADR-0005 rule 5's guard as it stands: a verdict that used
     any own-scope proof spares nothing.
   - **`trust`**: the record is taken whole, own-scope proofs included.
     **`trust` loosens**: a result an earlier commit's code wrote, which no
     reviewer of the head may have seen, reaches the default branch. It is
     an opt-in under ADR-0142's rules: set in bold in the reference, marked
     by `config:show`, and noted where in force unless
     `suggest.looseValues` is off.
7. **`ci.trustMergedPullRequests` takes a third value, `delta`.** It is
   `false`, the default, `true` or `delta`. Under `delta`, where the landed
   tree differs from the tree the pull request's verdict passed on, the push
   reads its change from the pull request's judged commit to the landed one,
   and a unit that change does not reach carries the pull request's result
   from the ledger in its artifact, as `ci.mergedOwnScopeProofs` allows.
   **`delta` loosens**: an interaction between two merged pull requests that
   reach does not see is left to the scheduled full run, as reach already
   leaves it within one change. It is an opt-in under ADR-0142's rules. The
   gate's own config keeps `true` until `delta`'s effect is measured.
8. `ci.mergedOwnScopeProofs` decides how the gate runs and is in no key, as
   `ci.trustMergedPullRequests` is.

### Settings

A loose value is in bold (ADR-0142).

| Setting | Values | Default | Effect |
|---|---|---|---|
| `ci.trustMergedPullRequests` | `false`, **`true`**, **`delta`** | `false`; `true` in the gate's own config | decides how the gate runs |
| `ci.mergedOwnScopeProofs` | `reach`, `refuse`, **`trust`** | `reach` | decides how the gate runs |

### The trade

Turning the setting on trusts a pull request's own computed result, as
ADR-0005 rule 5 already says. Anyone who can open a pull request whose run
writes the record can make the default branch skip re-checking its tree once
it is merged. The artifact moves that record where a push can read it; it
does not widen whose record is read, nor what it must say.
- The check-run is still a hint: the record must also name the head and its
  tree, and come from a `pull_request` run of the workflow that runs the gate.
- The guards are the nightly `unproven` run, which takes no proof
  (ADR-0084), and the scheduled full run ADR-0005 rule 6 requires.
- Under `reach`, the default, nothing an earlier commit of the pull request
  wrote is carried onto the default branch: the units it proved are reached
  and run. Only `trust` and `delta` carry more. Each of `true`, `delta` and
  `trust` is noted wherever it is in force, as ADR-0142 says.

### Proof before it ships

- A contract test of the workflow: a pull request's run uploads the record,
  a push of its tree with the setting on reaches nothing and says which pull
  request proved it, and a push of another tree runs its range.
- A record whose head or tree differs from the run it came from, or that a
  failed later run at that head outweighs, proves nothing.
- A pull request pushed twice, whose last verdict took units from its own
  scope: under `reach` the push runs exactly those units, under `refuse` its
  whole range, under `trust` none.
- Under `delta`, a merge behind the default branch: the push runs what the
  change from the judged commit reaches, and carries the rest.

### Where today's path stays

- With `ci.trustMergedPullRequests: false`, the default, every merged range
  is mutated in full.
- A record that cannot be found, read or matched, an expired artifact among
  them, proves nothing, and the range is mutated in full.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Read the pull request's cache scope** | GitHub lets a push restore only its own ref's entries and the default branch's. |
| **Give pull request runs a store key to write their scope** | A key that writes, in a job that runs the pull request's code. |
| **Trust the check-run alone** | Any workflow at that commit can make a check-run under any name (ADR-0005). |
| **Keep refusing a verdict that used own-scope proofs** | With own-scope proofs on by default, it spares mostly pull requests pushed once; `refuse` keeps it selectable. |
| **Skip the push's jobs in the caller's workflow** | The gate's verdict is the check the default branch's next runs read `passed` from. |

## Consequences

- A tree-equal merge costs its push one plan and one verdict, in the gate's
  own CI and in any project that turns the setting on.
- The plan job of a push holds `actions: read`.
- The pull request's ledger is read through its run's artifact; the store
  holds nothing new.
- A pull request pushed many times still spares its push all but the units
  it took from its own scope.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): rule 5, the trust and its checks; rule 6, the scheduled full run
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the `passed` record
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): cache scopes
- [ADR-0029](0029-a-caller-passes-the-store-secrets-by-name.md): a pull request's run holds no store key
- [ADR-0049](0049-an-owner-is-mentioned-once-and-a-merge-group-trusts-its-pull-request-as-a-merged-one.md): merge groups
- [ADR-0081](0081-a-new-push-mutates-only-what-its-earlier-runs-do-not-prove.md): own-scope proofs
- [ADR-0084](0084-the-gate-is-keyed-by-the-code-a-verdict-depends-on.md): the nightly `unproven` run
- [ADR-0142](0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md): how a loose value is offered and named
