# ADR-0140: The gate's own run is judged by main's build

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0084](0084-the-gate-is-keyed-by-the-code-a-verdict-depends-on.md): item 7, for a run a judge judges
**Amends:** [0051](0051-the-action-runs-the-release-s-signed-phar-where-the-project-has-no-copy.md): item 4, for an archive whose classes are scoped
**Amends:** [0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md): which project the PHAR refuses

## Context

In the gate's own run the gate is project source, run from the pull
request's checkout. So a change to the code that runs inside a mutant's own
process, such as Pest's warm worker, its recorder or the patch, must reach
every unit (ADR-0084, item 7), or a result an older runner recorded would
carry. A pull request that touches the runner re-runs about 60,000 mutants.

The runner a pull request changes is proven by other jobs: the runner
contract legs run every runner over its fixtures, and the end-to-end jobs run
the gate whole. What the gate's own mutation run needs is a judge that does
not change with the pull request.

A PHAR of main's build cannot simply run instead. Part of the gate runs in
each mutant's process, its Pest plugin, recorder and warm worker among it,
beside the pull request's own classes, which the project's autoloader
declares under the same names. Two copies of one class cannot coexist, so
the PHAR refuses a project that declares the gate's classes (ADR-0022), and
it refuses Pest, whose plugin only the Composer package registers
(ADR-0051).

## Decision

1. **A judge is the gate with every class under a namespace of its own.**
   `composer phar:judge` copies the tree, writes every name of the gate's
   namespaces under `MutationGateJudge\`, in the code and in every string that
   names one, the code-writing templates included, and builds
   `mutation-gate-judge.phar` as `composer phar` builds the PHAR. Its scoped
   dependencies take a prefix of their own, and the archive an alias of its
   own. Each rewrite that names a file must change it, so a moved anchor stops
   the build rather than building a judge that is not scoped.
2. **The PHAR refuses a project whose autoloader declares its own classes,
   not one that installs the gate.** It reads the project's manifest and every
   installed package's: one whose autoload would find a class of the
   archive's own is refused, the gate's own repository among them. A judge's
   classes are no copy's, so it runs beside either.
3. **Under an archive, the first-party extensions are the archive's.** The
   extensions the gate's package and its mutator sets declare in the project's
   manifests are left for the ones the archive's own manifest declares. An
   extension of the project's own implements the gate's interfaces, not a
   judge's, so it does not run under one.
4. **Pest runs under an archive where the project's Pest lists a plugin of a
   copy of the gate.** Every PHP process of a Pest run scans a directory whose
   ini file prepends a script. The script loads the archive and its
   autoloader, then gives the archive's Pest plugin the name of each plugin
   of a copy of the gate that the project's `pest-plugins.json` lists, before
   Composer's autoloader, which would find the copy's, is registered. The
   contracts the plugin implements are read from Pest's own source in the
   project, and no other code of the project's is loaded. A project whose Pest
   lists no such plugin is refused, as before. The classes a patched
   pest-plugin-mutate calls in a mutant's own process name the project's
   Composer and Symfony classes, so the archive leaves them unscoped.
5. **The reusable workflow's `judge` input names the judge.** It is empty by
   default, which runs the project's copy. For the gate's own repository, whose
   history alone holds these builds, it takes:
   - `auto`, which the gate's own run names: main's build, at the commit the
     change branched from, a pull request's merge base with its base, a merge
     group's with its base, any other run's with the default branch, which is
     the commit itself on the default branch; or at its base where that commit
     holds no judge's build. It takes the change's own tip instead where the
     change touches what calls, configures or builds the judge: the reusable
     workflow, the action and its scripts speak the command line of their own
     commit, `mutation-gate.json` may set a setting of its own commit, a change
     that repairs the judge's build cannot be judged by the build it repairs,
     and the change that brings the build has no earlier one to be judged by;
   - `merge-base`: main's build always, where the change branched or else at
     its base. Where the change touches what `auto` lists, or neither commit
     holds a judge's build, the job refuses and the verdict is cannot-judge,
     saying why and naming this setting; a run never passes without the judge
     it asks for;
   - `head`: the change's own tip always.

   For any repository it takes:
   - `release`: the newest signed release's `mutation-gate-judge.phar`,
     verified by its signature and its attestation as its PHAR is;
   - a commit or tag of the gate's repository: built there.

   A `judge` job builds it once a run, cached by commit, from the commit's
   own lock, running none of its Composer scripts, and the plan, shards,
   survivors and verdict run it. Each puts back the release of
   pest-plugin-mutate Composer installs, with no script run, and has the
   judge patch it where `pest.patch` is on. The action takes the judge's
   PHAR as its `judge` input. A release publishes the judge beside its PHAR,
   signed and attested alike. Where the job fails, the plan fails fetching
   what it left and the verdict is cannot-judge, saying why, so the check a
   branch protects is never skipped.
6. **The gate's own run is judged by `auto`, and keeps no
   `reach.everything` list.** A unit carries as its source and its key say;
   the judge's code is in every key, through `VerdictCode` (ADR-0084), which
   digests the judge's files. A local run of the gate on its own repository
   runs its own copy and is advisory, as every local run is.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **A parent-side judge only** | Main would plan, key and record, but the code in each mutant's process would stay the pull request's, so the globs would stay and a runner change would still re-run everything. |
| **Not building a judge** | Keyed by its verdict code (ADR-0084), the gate re-runs every unit for every change to its runner, which is the code it changes most. |
| **Main's build alone, for the gate's own run** | A change to a flag and the workflow that passes it, or a setting and the config that sets it, could never pass; each would have to land in two pull requests, and a broken judge's build could not be repaired. `merge-base` offers it as a choice. |
| **The newest release's judge alone** | It lags main by up to a release; a change that relies on main's runner would be judged by an older one. |
| **Dropping the pull request's plugin from `pest-plugins.json`** | It changes the project's vendor directory for good, so a Pest run without the archive would fail on a class it cannot find. |

## Consequences

- A pull request that changes the runner re-judges only its own units and
  what its change reaches; its runner is proven by the runner contract and
  end-to-end jobs.
- A runner bug that changes verdicts, and that those jobs miss, shows on the
  default branch's next run, judged by main's build of the merged commit, and
  on the release's and the nightly `--unproven` runs, not on the pull request.
- Under `auto`, a change to what calls a judge is judged by its own build. Where it changes
  no code of the gate's, its verdict code is the merge base's and that re-runs
  no unit; where it changes both, as a new flag and the workflow that passes it
  do, it re-runs every unit, which landing the flag first avoids.
- A judge job adds a build of the gate at a commit once for each commit a
  cache does not hold, about two minutes.
- A project of its own can be judged by a release's judge, where it installs
  the gate with Composer and registers no extension of its own.

## Related

- [ADR-0084](0084-the-gate-is-keyed-by-the-code-a-verdict-depends-on.md): the verdict code
- [ADR-0051](0051-the-action-runs-the-release-s-signed-phar-where-the-project-has-no-copy.md): the PHAR the action runs
- [ADR-0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md): the signed PHAR
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): pest:patch
