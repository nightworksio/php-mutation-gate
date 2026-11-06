# ADR-0084: The gate is keyed by the code a verdict depends on

**Status:** Accepted
**Date:** 2026-11-05
**Amends:** [0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): items 2 and 5 of the key, and its format

## Context

Item 2 of a proof's key was the gate's installed version and source
reference. In the gate's own run that is the pull request's commit, so no
proof of an earlier push, and none of the default branch, ever matched a
pull request's key: every unit a push reached ran again, however little of
it moved. A project that uses the gate paid the same on every bump of its
pin, whatever the release changed. Item 5 hashed
`vendor/composer/installed.json` whole, which names the gate's own version
too, so a pin bump moved every key a second way.

Most of the gate does not decide what a mutant's run records: its commands,
reports, deliveries, stores, doctor and CI providers, and the flows that
plan, carry and judge, which apply floors and ignores to raw records at
verdict time and are not in the key.

## Decision

1. **Item 2 is the gate's verdict code.** It is a hand-raised semantics
   number, `VerdictCode::SEMANTICS`, and the digest of each file, by its path
   and contents, of the sets of `VerdictSet` the run's runner reads, with the
   `src` of every installed `nightworksio/mutation-gate-*` package, the gate's
   own mutators. Every run reads the sets that record a mutant (`Records`:
   mutants, the native protocol, limits and processes' rules, controls,
   coverage, tests, holds, analysis, the kill matrix, ordering, pruning,
   removal, assertions, units, config, PHP, files, formats and time), the
   engine (`Mutators`), processes (`Processes`), analysers and opcache
   (`Analysers`), and the flows that build and check a shard's run
   (`Running`). Each runner adds its adapter: Pest with its patch, plugin and
   worker scripts; Infection with its patch; PHPUnit, PhpSpec, Codeception
   and Testo with the native runner's shared code. A runner the gate does not
   ship reads every built-in runner's sets.
2. **Every other file is listed outside the verdict, with why.**
   `OutsideTheVerdict` groups every other file of `src` and `bin` under the
   reason no result depends on it. An architecture test fails for a file in
   neither list, in both, or for a glob that names no file, so a new file
   lands classified, and its classification shows in the diff.
3. **A contract test checks the mutant side.** Under each runner, the
   fixture's mutants run with a script prepended to every PHP process, which
   lists the files it loaded. Every file of the gate a mutant's process
   loaded must be in a set the runner reads.
4. **Item 5 leaves out the gate's own packages.** It hashes each package
   `installed.json` lists, less `nightworksio/mutation-gate` and its mutator
   packages, whose code item 2 reads.
5. **The key's format is `mutation-gate proof 9`.**
6. **`--unproven`, and the `unproven` mode, take no proof.** A run that asks
   for it considers every unit and takes no result from any ledger, so every
   unit runs. The gate's release runs it, and so does its nightly run on the
   default branch: the backstop of a key that reads less than the whole gate.
7. **The gate's own run keeps its `reach.everything` list, but for one
   entry.** A unit the change does not reach carries its newest result by its
   source alone (ADR-0060, rule 3), and in the gate's own run the gate is
   project source, run from the pull request's checkout. So a change to the
   code that runs inside a mutant's own process, or decides what it executes,
   must reach every unit, or an older runner's results would carry:
   - `src/Adapter/Pest/Warm/**`, `bin/mutation-gate-pest-worker` and
     `bin/mutation-gate-pest-client`: the warm worker that forks each mutant's
     run, and its client;
   - `src/Adapter/Pest/Recording/**`: what records each test's result and
     time in a mutant's run;
   - `src/Adapter/Pest/Grouping/**` and `src/Adapter/Pest/Order/**`: which
     tests a mutant's run selects, and in what order;
   - `src/Adapter/Pest/Plugin.php`, `src/Adapter/Pest/Bridged.php` and
     `src/Cli/PestPlugin.php`: the plugin Pest loads into a mutant's run;
   - `src/Adapter/Pest/Patch.php` and `src/Adapter/Pest/Patching.php`: the
     rewrites `pest:patch` applies to pest-plugin-mutate;
   - `src/Adapter/Runtime/ForkedChild.php`: what a forked mutant's process
     resets;
   - `src/Core/Runner/MutantLimit.php`: each mutant's limit.

   `src/Adapter/Native/MutantFile.php` leaves the list: the gate's own run
   runs Pest, which never loads it.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Hash every gate file a name graph reaches from the verdict entry points** | From Pest's mutation run, the judge or the mutant record alike, the graph reaches 2006 of the 2076 files of `src`: signatures and values tie the gate into one component, so the key would move on nearly every change. |
| **Keep the version and reference, and skip it in the gate's own run** | A project that uses the gate would still re-run everything on every pin bump. |
| **Drop the `reach.everything` list, as the key now moves with verdict code** | The key moves only for the units a change reaches. The others carry by their source alone, so a change to how mutants run would carry an older runner's results. |
| **A semantics number alone, raised by hand** | A change that moves verdicts and is not noticed would carry wrong results until someone raises it. The digests catch every change to the sets; the number covers what lies outside them. |

## Consequences

- A push that changes no verdict code keeps the key of the push before, and
  of the default branch: a unit proved there stands, and only the units whose
  own key moved run. On the four pushes of #556, the second, third and fourth
  changed 0, 4 and 2 of the 40 source files the pull request changed.
- A project bumps its pin and re-runs only the units whose key the release's
  verdict code moved, or nothing where the release changed none of it.
- Every proof key moves once, with the format.
- A file added to the gate must be classified before its pull request passes.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the key
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): `reach.everything`
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the gate's own run
- [ADR-0060](0060-a-result-carries-only-for-the-code-it-is-of.md): what an unreached unit carries
