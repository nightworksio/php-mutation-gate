# ADR-0005: What a change reaches is what is mutated, package by package, judged by the tests that hold it

**Status:** Accepted
**Date:** 2026-09-29

## Context

Mutating everything on every pull request is the honest default and the
expensive one. The companion's full run is about twenty shards of ten minutes.
Most pull requests touch a few files, and most of those shards judge code the
change cannot have affected.

The companion narrows a run with `--changed-since` (`whatTheChangeReaches()`
in `scripts/mutation.php`, `WhatAChangeReaches` in the seed). The rules are:
- a changed source file is mutated;
- a changed test reaches every file it executes, read from the coverage map;
- changed test support reaches the tests that name it;
- a change to what decides how the gate runs reaches everything, unless all it
  did was move a workflow's action pins;
- anything else reaches nothing by itself.

On `main` it mutates what was reached since the last commit that passed. It
mutates nothing when every commit in between is the tree of a pull request that
already passed.

Two more needs came from the same repository.
- **Code that every test runs through.** The composition root and each module's
  service provider are covered by the whole suite. So every mutant there is run
  against the whole suite, and on a runner that made one shard take fifteen to
  thirty minutes, mostly in timeouts. The companion lets a test file declare
  `pest()->group('holds:<path>')`. That path is then mutated against its group
  alone, once the group is shown to cover all of it.
- **Monorepos.** The companion is a monorepo of modules, each with a manifest
  that declares its floor.

## Decision

1. **A unit is what a verdict and a proof are about.** It is either one source
   file in a tree, or a path a holding group judges (decision 9), mutated as one
   unit against that group.

2. **Two modes.**
   - **Full**: every unit is considered. This is the default for a local run,
     for `mutation-gate run` with no base, and for scheduled CI runs.
   - **Change-scoped**: only the units the change reaches are considered.
     - `--changed-since=<ref>` selects it.
     - The package's GitHub action and reusable workflow pass the pull
       request's base on `pull_request`, and `last-passed` on a push to the
       default branch.
     - `last-passed` means the newest commit on this branch whose verdict
       passed. The ledger records that commit (ADR-0007).

   In both modes the proof ledger then decides which considered units actually
   run (ADR-0007). A unit the change does not reach carries its base result
   into its tree's score (ADR-0003). A unit with no base result to carry is
   treated as reached, so a change-scoped run on an empty ledger is a full run.

3. **What counts as changed.**
   - **Paths**: the diff from the merge base of `<ref>` and `HEAD` to the
     working tree, with renames detected (`git diff --find-renames`). Using the
     merge base means a branch behind its base never counts the base's newer
     commits as its own.
   - **Changed lines**: the lines on the new side of `git diff --unified=0` that
     were added or modified, in PHP files inside a tree. The new-code floor
     judges the mutants on these lines (ADR-0003).
   - A pure rename reaches its unit and changes no lines.
   - A deleted source file leaves its tree, and has nothing to mutate.
   - Uncommitted changes count, so pre-push and watch mode (ADR-0010) see what
     is on disk.

4. **What a change reaches.** The first matching rule decides, and every
   decision is printed ("`phpunit.xml` decides how the gate runs, so every unit
   is reached").
   1. **A file that decides how the gate runs reaches everything in its
      package.** Those files are:
      - the gate's config and each package's `composer.json`;
      - the PHPUnit config and the test bootstrap it names;
      - Pest's `tests/Pest.php`, and the runner's own config (`infection.json5`);
      - the paths a preset lists, such as Laravel's `bootstrap/**`, `config/**`
        and `routes/**` (ADR-0008), and the `reach.everything` patterns.

      A root file reaches every package. The CI definition that runs the gate
      is one of these files, except when every line its change touched is an
      action pin (`uses: owner/repo@<40-hex sha>`, with or without a trailing
      comment). A pin move changes which revision of an action runs, not how
      the gate cuts, runs or judges.
   2. **A changed source file in a tree reaches that unit.**
   3. **A changed test reaches every unit its tests execute**, according to the
      coverage map.
      - It also reaches the trees a layout rule says it judges, such as a
        module's tests judging that module's code.
      - A test that asserts less changes no line of the code it judged. The map
        is the only way to find that code again.
      - A deleted test, or no map to read, reaches everything.
   4. **Changed test support reaches the tests that use it.** Test support is a
      file under a test directory that is not a file of test cases, such as a
      fake or a helper.
      - The gate finds the tests that name a class or function it declares,
        through any other support that names it in turn. Those tests reach what
        they execute, as in rule 3.
      - Support that runs code when it is loaded reaches everything, and so does
        support named by anything other than a test or support.
   5. **Anything else reaches nothing by itself**: documentation, templates,
      translations, the lock file, other CI files. The content key still covers
      these files (ADR-0007). A unit reached for another reason is therefore
      run again when they moved, and the scheduled full run (decision 6) catches
      the rest.

   Where git cannot say what changed, everything is reached.

5. **On the default branch, a tree a pull request already proved is not
   mutated again.** This follows the companion. On a push, the reach runs from
   `last-passed`. With the GitHub `ChangeSource`, a commit whose tree is the
   tree of a merged pull request's head, and whose run passed, reaches nothing:
   the branch was up to date, so its run judged exactly the tree that landed.
   The gate asks at most twenty commits back. A longer range, or one commit it
   cannot prove, is mutated in full.

6. **A scheduled full run is part of the design, not an extra.** Reach cannot
   see everything:
   - a template that decides what a screen test asserts;
   - a dependency the lock moved;
   - a module whose tests run the code another module changed.

   The documented GitHub setup includes a weekly scheduled full run on the
   default branch. Proofs keep that run to the units whose key moved (ADR-0007).

7. **Monorepos: modules share a suite, packages have their own.**
   - **A module** is a directory with its own `composer.json` inside one project,
     sharing that project's test suite, coverage map and `vendor`. The
     companion's `app-modules/*` are modules.
     - Each is one or more trees.
     - A tree's declared floor is read from the config or, failing that, from
       the nearest `composer.json` above it that declares
       `extra.mutation-gate.floor`, the way the companion reads
       `extra.lemonfiber.floors.mutation`.
     - A module can declare `extra.mutation-gate.newCodeFloor` too.
   - **A package** is a directory with its own `composer.json` and its own PHPUnit
     config. It is its own project: the runner runs in its directory, with its
     own coverage map, trees, floors and new-code floor.
     - Packages come from `packages` globs in the root config, or from the root
       `composer.json`'s `path` repositories that hold a PHPUnit config.
     - A shard never mixes packages.
   - **Reach follows the dependency graph.** A change that reaches everything in
     package A (rule 1) also reaches everything in each package that depends on
     A through a path repository, transitively, because A's manifest or test
     setup is part of how their tests run. A change to one of A's source files
     reaches that unit, as it would inside one project. A package that nothing
     reaches is not planned at all.

8. **The tree source for a monorepo is data.** `TreeSource` adapters read
   `phpunit.xml`, Composer autoload paths, or `composer.json` manifests matched
   by glob (ADR-0001). A layout the built-ins do not read is an extension, not a
   fork.

9. **Holding tests: a path can be judged by the tests that hold it.**
   - **How a test declares it:**
     - In Pest, `pest()->group('holds:<path>')` for a whole file, or
       `->group('holds:<path>')` on single tests.
     - In PHPUnit classes, the attribute
       `#[NightWorksIO\MutationGate\Attribute\Holds('<path>')]`, which is
       repeatable, on a class or a method. PHPUnit's own
       `#[Group('holds:<path>')]` works too.
   - **How the gate reads it.**
     - Groups come from the runner's own listing (ADR-0004), so the answer is
       the groups the runner will actually select by.
     - `#[Holds]` is read from test files by their tokens, without loading them,
       so reading it runs no test code.
   - **What the path must be.** It has to be a tree, or a file or directory that
     exists inside one, spelt as the repository spells it. Anything else stops
     the run with exit code 2. A misspelt path would otherwise be mutated
     against the whole suite, correct and slow, with no sign the declaration
     was never read.
   - **How a held path runs.** It is one unit, mutated against its group alone
     and never with the shared coverage map. For Pest that is `--group`. For
     Infection it is the group or, for `#[Holds]`, a `--filter` naming the
     holding tests. The runs of the tree around it leave it out (Pest's
     `--ignore`), so no mutant is judged twice or by the wrong tests.

10. **A group must cover what it holds before it may judge it.** Before a held
    path is mutated, its group runs alone under coverage. Every line of the path
    that the whole suite covers must be covered by the group. Otherwise the held
    unit fails (exit code 1) and the message lists the lines it misses:

    ```
    holds:src/Kernel.php does not cover src/Kernel.php, so its mutants cannot be judged by it.
    Not reached: src/Kernel.php:48, src/Kernel.php:61
    Add the test that runs them to the group.
    ```

    A group that does not pass on its own is *cannot judge*. A path with no
    file in the group's report is unreached as a whole, never "nothing missed".

11. **A path every test runs through, and nothing holds, is named.** After the
    coverage run, a warning names each source file whose lines are executed by
    at least `holds.hotPath` of the suite's tests (0.8 by default, and only in
    suites of 20 tests or more), when no group holds it. The warning appears in
    the console, the step summary and the PR comment (ADR-0009):
    *`src/Kernel.php` is run by 412 of 430 tests and nothing holds it; each of
    its mutants runs most of the suite.* It is a warning, not a failure, because
    the verdict stays correct and only its cost is at stake.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Always mutate everything** | Correct and unaffordable: a twenty-shard run on every push to every pull request. It stays available as full mode, and as the scheduled run. |
| **Infection's `--git-diff-filter`/`--git-diff-lines`** | Only one runner has it, and it knows nothing of tests reaching code, holding groups or packages. The gate computes changes once, for every runner. |
| **The diff against the base commit rather than the merge base** | On a branch behind its base, the base's newer commits read as the branch's own changes. |
| **Reach only through the coverage map, even for source changes** | A changed file is always mutated, whether or not a map exists. The map is needed for what a changed test reaches, where the diff shows no source change. |
| **A changed source file also reaches every unit whose tests run it** | Would re-run most of a project for a change to shared code, to catch mutants whose killing test changed behaviour indirectly. The scheduled full run catches those at a fraction of the cost. The companion made the same trade. |
| **Reach every dependent package whenever a package's source changes** | Every change to a shared core package would re-mutate the whole monorepo. Kept for changes that alter how a package's tests run (its manifest and test setup), which is where a dependent's verdict genuinely moves. |
| **Declaring held paths in config instead of in tests** | Puts the claim "these tests hold that code" away from the tests that make it. A group or attribute moves with the test, and the coverage check keeps it honest. |
| **Reading `#[Holds]` by reflection** | Loads test classes, which can run code at load time. Tokens are enough to read an attribute's argument. |
| **Failing, not warning, on an unheld hot path** | The verdict is still right, only slow. A failure would make adopting the gate on a framework app mean restructuring tests first. |

## Consequences

**A typical pull request mutates what it touched and what its changed tests
run**, and carries every other unit's result from its base.

**Correctness rests on three things together**: reach narrows the run, proofs
check it (ADR-0007), and the scheduled full run catches what reach cannot see.
The README says so, and its example workflows include the schedule.

**Code every test passes through gets a cheap, honest judge**, where the group
covers it, instead of timeouts.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): carried results and the new-code floor
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): how each runner selects a group
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): held units in shards
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the ledger, `last-passed` and what reach does not cover
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): the paths presets add to rule 1
