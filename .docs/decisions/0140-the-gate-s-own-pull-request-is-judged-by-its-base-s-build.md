# ADR-0140: The gate's own pull request is judged by its base's build

**Status:** Accepted
**Date:** 2026-11-09
**Amends:** [0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): decision 1, which build gates a pull request of the package; [0084](0084-the-gate-is-keyed-by-the-code-a-verdict-depends-on.md): item 2, whose verdict code a judged run's key reads, and item 7, the self-gate's `reach.everything` under a judge; [0051](0051-the-action-runs-the-release-s-signed-phar-where-the-project-has-no-copy.md): decision 4, the Pest runner under a judge's archive

## Context

The gate gates itself with itself (ADR-0011), so in its own run the gate is
project source, run from the pull request's checkout. A unit a change does
not reach carries its newest result by its source alone (ADR-0060), so a
change to the code that runs inside a mutant's process must reach every unit,
or an older runner's results would carry. The gate's config lists that code
in `reach.everything`, 13 globs: the warm worker and its client, the
recording, grouping and ordering of a mutant's tests, Pest's plugin and the
patch, the forked child's reset and each mutant's limit (ADR-0084 item 7;
`mutation-gate.json`).

Of 26 runs of the gate's own CI planned between 2026-11-06 22:12 and
2026-11-08 15:22, at least 5 named such runner code as the first reason they
reached every unit. The two of them measured, pull request runs 42494457581
and 42493881214, cost 11.7 and 14.6 shard-hours.

A pull request's runner code is held by other jobs too: the runner contract
suite runs every runner against its fixtures, and the end-to-end jobs run the
gate on fixture projects; both are required.

## Decision

1. **The reusable workflow takes `judge`**: empty, the default, `merge-base`,
   `release`, or a git ref.
   - Empty judges with the project's own copy, as today.
   - `merge-base` judges with the gate built at the merge base of the pull
     request and its base branch, and with the newest signed release where
     that build fails.
   - `release` judges with the newest signed release's judge archive.
   - A ref judges with the gate built at that ref.
2. **The judge is a scoped archive.** `composer phar:judge` builds
   `mutation-gate-judge.phar`, every namespace of the gate, its code-writing
   templates and its scoped dependencies prefixed `MutationGateJudge\`, so it
   loads beside a project that declares the gate itself. A `judge` job builds
   it, or downloads the release's, checks it as ADR-0051 checks a release's
   archive, keeps it in the Actions cache by commit, and hands it to the
   plan, the shards, the survivors re-check and the verdict as an artifact.
   `release.yml` publishes it with its signature and attestation.
3. **Under a judge, every process the gate starts runs the judge.** Each PHP
   process loads the archive's autoloader first, through an ini directory
   that prepends it; the archive's Pest plugin replaces, in the project's
   Pest plugin list, any plugin the gate's packages declare; and extension
   discovery skips the gate's own packages' declarations in the project's
   manifests, since the archive reads its own. The Pest runner runs under the
   judge's archive, which ADR-0051 decision 4 refuses for the release's
   archive.
4. **A judged run's key reads the judge's verdict code.** Key item 2
   (ADR-0084) digests the verdict sets of the build that judges, so a proof
   made by one judge stands only for that judge.
5. **The gate's own CI passes `judge: merge-base` on pull requests once the
   base holds a judge build** (`scripts/judge/scope.py`). Until it does, and
   no release's judge exists to fall back to, `merge-base` refuses, so the
   gate's own pull requests pass no `judge` and are judged by their own
   build. A push to the default branch is judged by its own build, the
   merged commit, as today.
6. **Under a judge, a `reach.everything` glob that matches only the gate's
   own files reaches nothing by itself.** Where the run's judge is another
   build than the checkout's, and every file a glob matches is a file of the
   package the judge replaces, that glob is left out of reach: those files do
   not run the mutants, the judge does, so they are source like any other.
   A glob that also matches any other file reaches everything as it does
   today. No key says so, and the gate's config keeps its list, so a run with
   `judge` empty reaches every unit on a runner change, exactly as today.
   The plan names each glob it left out and why.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| reusable-workflow input `judge` | empty, `merge-base`, `release`, a git ref | empty; `merge-base` on the gate's own pull requests | the caller's |

### The trade

A pull request's change to the gate's runner code is mutated as source and
tested by its own tests, but it does not run the mutants of its own verdict.
A bug in it that would change verdicts shows on the default branch's run of
the merged commit, which its own build judges, not on the pull request. The
guards are:

- the required runner contract and end-to-end jobs, which run the pull
  request's runner code on every runner and fixture;
- the default branch's run after the merge;
- the release's run and the nightly run, which take no proof (ADR-0084).

### Proof before it ships

- On one pull request that changes no runner code, a run judged by its own
  copy and one judged by `merge-base`: identical verdicts, mutant for mutant.
- On a pull request that changes only runner code, the plan under
  `merge-base` leaves out the globs of the gate's list and reaches the units
  the change reaches as source; with `judge` empty it reaches every unit.
- A pull request that plants a verdict-changing fault in the runner code:
  the runner contract suite fails on it, and the default branch's run after
  it would fail.

### Where today's path stays

`judge` empty judges with the project's own copy, as today, for every
project and for any run of the gate's own CI that does not pass it. A judge
that cannot be built or downloaded fails the `judge` job, and with it the
run, rather than judging with another build.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Install the base's gate into a second vendor directory** | Two copies of one namespace cannot load in one process; the project and the judge both declare `NightWorksIO\MutationGate`. |
| **Keep reaching every unit** | A runner change costs a full run, 11.7 to 14.6 shard-hours measured, on every push of its pull request. |
| **A key saying which `reach.everything` globs a judge leaves out** | The gate can tell them itself: a glob that matches only the replaced package's files; a key would only repeat that. |
| **Remove the runner globs from the gate's config** | A run with `judge` empty would then carry an older runner's results, so today's reach would no longer be selectable. |
| **A config key in place of the workflow input** | The judge has to be chosen before any gate runs, and so before any config is read. |
| **Judge with the newest release only** | The default branch moves ahead of releases, and its verdict code is what the pull request merges into. |

## Consequences

- A pull request of the gate's own that changes its runner code judges the
  units it reaches, judged by its base's gate.
- The gate's own release ships a second archive, the judge.
- A verdict-changing runner bug fails the default branch's run, not the pull
  request that brought it; the required contract jobs are where it shows
  first.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): `reach.everything`
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the self-gate
- [ADR-0051](0051-the-action-runs-the-release-s-signed-phar-where-the-project-has-no-copy.md): the signed archive and its checks
- [ADR-0060](0060-a-result-carries-only-for-the-code-it-is-of.md): what an unreached unit carries
- [ADR-0084](0084-the-gate-is-keyed-by-the-code-a-verdict-depends-on.md): verdict code, the self-gate's list and `unproven`
