# ADR-0005: What a change reaches is what is mutated, package by package, judged by the tests that hold it

**Status:** Accepted
**Date:** 2026-09-29

## Context

Mutating everything on every pull request is the honest default and the
expensive one. The in-house gate's full run is about twenty shards of ten
minutes. Most pull requests touch a few files, and most of those shards judge
code the change cannot have affected.

The in-house gate narrows a run to what a change reaches, and the seed carries
the same rules:

- a changed source file is mutated;
- a changed test reaches every file it executes, read from the coverage map;
- changed test support reaches the tests that name it;
- a change to what decides how the gate runs reaches everything, unless all it
  did was move a workflow's action pins;
- anything else reaches nothing by itself.

On the default branch it mutates what was reached since the last commit that
passed. It mutates nothing when every commit in between is the tree of a pull
request that already passed.

The same repository has two more needs.

- **Code that every test runs through.** The composition root and each module's
  service provider are covered by the whole suite. So every mutant there is run
  against the whole suite, which on its runners makes one shard take fifteen
  to thirty minutes, mostly in timeouts. A test file there can declare
  `pest()->group('holds:<path>')`. That path is then mutated against its group
  alone, once the group is shown to cover all of it.
- **Monorepos.** That repository is a monorepo of modules, each with a manifest
  that declares its floor.

## Decision

1. **A unit is what a verdict and a proof are about.** It is either one source
   file in a tree, or a path a holding group judges (decision 9), mutated as one
   unit against that group.

2. **Two modes.**
   - **Full**: every unit is considered. This is the default for a local run,
     for `mutation-gate run` with no base, and for scheduled CI runs.
   - **Change-scoped**: only the units the change reaches are considered.
     - `--changed-since=<ref>`, accepted by `plan` and by `run` without a plan,
       selects it. `<ref>` is anything git resolves, or `last-passed`.
     - `last-passed` means the newest commit of this scope whose verdict
       passed, which the ledger records (ADR-0007). With none recorded, the run
       is full.
     - The package's GitHub action and reusable workflow pass the pull
       request's base on `pull_request`, and `last-passed` on a push to the
       default branch (ADR-0011).
   - **`--full`** asks for a full run by name. It is accepted wherever
     `--changed-since` is, and the two together are an error (exit code 2).

   **The run mode in CI.** The action and the reusable workflow take a `mode`
   input: `auto`, `full` or `changed`, `auto` by default.
   - `auto` is change-scoped on `pull_request` and on a push, from the base
     the bullet above names, and full on `schedule`, `workflow_dispatch`,
     `release` and a pushed tag.
   - `full` passes `--full` whatever the event.
   - `changed` is change-scoped whatever the event. On an event with no base it
     runs from `last-passed`.
   - The release workflow (ADR-0011) runs the gate with `mode: full` before it
     moves the major tag, so a release is judged over all its code.

   In both modes the proof ledger then decides which considered units actually
   run (ADR-0007). A unit the change does not reach carries its newest result
   from the ledgers the run reads, its own scope's and the default branch's,
   into its tree's score (ADR-0003). A unit with no result to carry is treated
   as reached, so a change-scoped run on an empty ledger is a full run.

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
      - the files that define the runner, which the runner names itself
        (ADR-0004): Pest's `tests/Pest.php`, and Infection's config
        (`infection.json5`) and the PHPUnit config in its `phpUnit.configDir`;
      - the paths a preset lists, such as Laravel's `bootstrap/**`, `config/**`
        and `routes/**` (ADR-0008);
      - the paths matched by `reach.everything`, a list of globs spelt from
        the repository's root, empty by default. Each of the files above is
        spelt from its package's directory instead.

      A file a `composer.json` lists under `files` in its `autoload` or
      `autoload-dev` is not one of these, though Composer's autoloader loads it in every
      process: a change to it changes no other file's code, so it reaches
      what rules 2 to 5 say of it. It leaves every kill a time budget would
      carry across it unjudged instead (ADR-0008, decision 1).

      A root file reaches every package. The CI definition that runs the gate
      is one of these files: under GitHub Actions the workflow
      `GITHUB_WORKFLOW_REF` names; on GitLab the file `CI_CONFIG_PATH` names
      (`.gitlab-ci.yml` by default) and the file `ci.gitlab.template` names;
      on Buildkite the file `ci.buildkite.definition` names
      (`.buildkite/pipeline.yml` by default); on CircleCI
      `.circleci/config.yml`; and none under the JSON plan or locally. It
      reaches everything except when every line its change touched is an
      action pin (`uses: owner/repo@<40-hex sha>`, with or without a trailing
      comment). A pin move changes which revision of an action runs, not how
      the gate cuts, runs or judges.
   2. **A changed source file in a tree reaches that unit.**
   3. **A changed test reaches every unit its tests execute**, according to the
      coverage map.
      - A changed test inside a module's directory (decision 7) also reaches
        every tree of that module.
      - A test that asserts less changes no line of the code it judged. The map
        is the only way to find that code again.
      - A deleted test, or no map to read, reaches everything in its package.
        A package's units are judged by its own suite alone (decision 7), so
        no other package's verdict reads that test.
   4. **Changed test support reaches the tests that use it.** Test support is a
      file under a test directory that is not a file of test cases, such as a
      fake or a helper.
      - The gate finds the tests that name a class or function it declares,
        through any other support that names it in turn. Those tests reach what
        they execute, as in rule 3.
      - Support that runs code when it is loaded reaches everything in its
        package, and so does support named by anything other than a test or
        support, and support none of whose versions can be read.
   5. **Anything else reaches nothing by itself**: documentation, templates,
      translations, the lock file, other CI files. The content key still covers
      these files (ADR-0007). A unit reached for another reason is therefore
      run again when they moved, and the scheduled full run (decision 6) catches
      the rest.

   Where git cannot say what changed, everything is reached.

5. **On the default branch, a tree a pull request already proved may be spared
   a re-check, where the config asks for it.** On a push, the reach runs from
   `last-passed`. Where `ci.trustMergedPullRequests` is true (false by
   default), the GitHub `ChangeSource`, which reads pull requests and
   check-runs through GitHub's API with `GITHUB_TOKEN`, spares a commit whose
   tree is the tree of the head of a pull request merged into the
   repository's default branch, and whose verdict passed on that head: it
   reaches nothing, since the branch was up to date and its run judged exactly
   the tree that landed. A pull request merged into any other branch, which no
   protection of the default branch guards, proves nothing. The gate asks at
   most twenty commits back. A longer range, or one commit it cannot prove, is
   mutated in full.
   - A pull request's verdict passed where its own ledger records that head
     as `passed` under the check `ci.check` names (`mutation / verdict` by
     default, the reusable workflow's verdict job) with `ownScopeProofs` of 0
     (ADR-0007). A verdict that used a proof of its own scope is not trusted,
     because the pull request's own code could have written that proof.
   - The latest check-run GitHub Actions completed on the head under that
     name must also have concluded in success, so a failed re-run outweighs an
     earlier success. The check-run is a hint, not a proof: any workflow run
     at that commit, the pull request's own included, can make one under any
     name.
   - Where the run cannot read the pull requests' ledgers, it proves nothing
     this way, and the range is mutated in full.
   - The shortcut works only where pull request runs write their own scope's
     ledger. That write is untrusted: the pull request's own code runs where
     the ledger is computed and written, and no setup this package ships gives
     a pull request's run the keys to write a store. Turning the shortcut on
     trusts the pull request's own computed result, so anyone who can open a
     pull request whose run writes its scope can make the default branch skip
     re-checking its tree once it is merged. Off, every merged range is
     mutated in full.

6. **A scheduled full run is part of the design, not an extra.** Reach cannot
   see everything:
   - a template that decides what a screen test asserts;
   - a dependency the lock moved;
   - a module whose tests run the code another module changed.

   Every documented CI setup includes a weekly scheduled full run on the
   default branch. Proofs keep that run to the units whose key moved (ADR-0007).

7. **Monorepos: modules share a suite, packages have their own.**
   - **A module** is a directory with its own `composer.json` inside one project,
     sharing that project's test suite, coverage map and `vendor`.
     - Each is one or more trees.
     - A tree's declared floor is read from its `trees` entry in the config or,
       failing that, from the nearest `composer.json` above it that declares
       `extra.mutation-gate.floor` (a number from 0 to 100). A floor of 0 needs
       `extra.mutation-gate.floorReason` beside it.
     - `extra.mutation-gate.newCodeFloor` (a number from 0 to 100) in the same
       manifest sets the floor for the new lines in that module's trees, in
       place of `newCode.floor` (ADR-0003).
   - **A package** is a directory with its own `composer.json` and its own PHPUnit
     config. It is its own project: the runner runs in its directory, with its
     own coverage map, trees and floors.
     - The Runner port's `rootedAt(package)` gives the runner in that
       directory, with the package's tests, vendor and gate directory there.
       There is no config key for it. A directory where the runner is not
       installed cannot be judged.
     - Packages come from `packages`, a list of globs in the root config, empty
       by default, and from the root `composer.json`'s `path` repositories that
       hold a PHPUnit config.
     - A package reads no config file of its own. The root config applies to
       every package, the tree source (decision 8) finds its trees in its own
       directory, and its manifest declares floors with the same
       `extra.mutation-gate` keys a module uses. The root baseline holds every
       package's trees.
     - A shard never mixes packages.
   - **Reach follows the dependency graph.** A change that reaches everything in
     package A (rule 1) also reaches everything in each package that depends on
     A through a path repository, transitively, because A's manifest or test
     setup is part of how their tests run. A change to one of A's source files
     reaches that unit, as it would inside one project. A package that nothing
     reaches is not planned at all.

8. **The tree source is data.** `treeSource` chooses the `TreeSource` adapter
   (ADR-0001) that finds the trees when the config lists none: `phpunit`, the
   default, reads `phpunit.xml`'s `<source>` and falls back to the preset's
   trees (ADR-0002); `composer` takes one tree per `autoload` path of the
   project's `composer.json`, and of each package's. Declared floors come from
   manifests as in decision 7. A layout the built-ins do not read is an
   extension, not a fork.

9. **Holding tests: a path can be judged by the tests that hold it.**
   - **How a test declares it.** The attribute is
     `#[NightWorksIO\MutationGate\Attribute\Holds('<path>')]`, repeatable,
     and allowed on a class, a method or a function, which in PHP includes a
     closure.
     - In Pest: `#[Holds]` on the closure passed to `it()`, `test()` or
       `arch()`, which holds that test, or on the closure passed to
       `describe()`, which holds every test inside it at any depth. Groups
       work too: `pest()->group('holds:<path>')` for a whole file,
       `->group('holds:<path>')` on one test or one `describe`. The package's
       Pest plugin turns each `#[Holds]` into the matching group before Pest
       builds the test (ADR-0004), so Pest selects both kinds the same way.
     - In PHPUnit classes: `#[Holds]` on a class or a method, or PHPUnit's own
       `#[Group('holds:<path>')]`. Under Pest, a PHPUnit class also needs the
       `#[Group]` beside its `#[Holds]`, because Pest cannot add a group to a
       class it did not build.
   - **How the gate reads it.**
     - Groups come from the runner's own listing (ADR-0004), so the answer is
       the groups the runner will actually select by.
     - `#[Holds]` is read from test files by their tokens, without loading them,
       so reading it runs no test code.
     - Under Pest the group listing is the answer, because the plugin has
       turned every `#[Holds]` into a group. Every path that tokens find must
       appear there as `holds:<path>`. Otherwise the run stops with exit code
       2 and says the package's Pest plugin is not loaded.
   - **What the gate refuses** (exit code 2, with the file and line), each for
     a reason at source:
     - **`#[Holds]` on a `beforeEach` or dataset closure, or on a named
       function.** Pest never passes those to the filter, so no group could
       follow from them.
     - **`#[Holds]` on a closure kept in a variable.** Tokens cannot tell which
       test the closure becomes, so the gate cannot check it against the group
       listing.
     - **`#[Holds]` in `tests/Pest.php`.** Pest loads that file before it
       starts any plugin, so its tests register before the filter exists.
     - **Under Pest, `#[Holds]` on a PHPUnit class or method without the
       matching `#[Group]`.** Pest loads a PHPUnit class file with a plain
       `include`, and only its own closure tests pass through the filter.
       PHPUnit reads groups only from its own attributes, and its `Group` is
       final (the Alternatives below). The gate reads both attributes from
       tokens, and the message prints the exact line to add:
       `#[Group('holds:<path>')]`.
     - **Under Infection, a path that is not one string literal**, such as a
       constant. The gate reads `#[Holds]` from tokens there, and tokens cannot
       evaluate an expression. Under Pest such a path works on a closure,
       because the plugin evaluates it and the group listing reports it.
     - **Under Pest, a path that is not one string literal on a PHPUnit class
       or method.** The plugin never sees that class, and tokens cannot check
       that its `#[Group]` matches.

     Each message names the group form to use instead. A held test
     chained with `->depends()` on a test outside its group is not refused:
     PHPUnit skips it, it covers nothing, and the coverage check of decision
     10 names the lines the group then misses.
   - **What the path must be.** It has to be a tree, or a file or directory that
     exists inside one, spelt as the repository spells it. Anything else stops
     the run with exit code 2. A misspelt path would otherwise be mutated
     against the whole suite, correct and slow, with no sign the declaration
     was never read.
   - **How a held path runs.** It is one unit, mutated against its holding
     tests alone and never with the shared coverage map. For Pest that is
     `--group`. For Infection it is the group or, for `#[Holds]`, a `--filter`
     naming the holding tests (ADR-0004). The runs of the tree around it leave
     it out (Pest's `--ignore`), so no mutant is judged twice or by the wrong
     tests.

10. **A group must cover what it holds before it may judge it.** Before a held
    path is mutated, its group runs alone under coverage. Every line of the path
    that the whole suite covers must be covered by the group. Otherwise the held
    unit fails (exit code 1) and the message lists the lines it misses:

    ```text
    holds:src/Kernel.php does not cover src/Kernel.php, so its mutants cannot be judged by it.
    Not reached: src/Kernel.php:48, src/Kernel.php:61
    Add the test that runs them to the group.
    ```

    A group that does not pass on its own is *cannot judge*. A path with no
    file in the group's report is unreached as a whole, never "nothing missed".

    The tests of the group that run any line of a path it covers are the
    tests that judge its mutants. The shard's result lists them as `covered`,
    and the unit's proof keeps them as `judging` (ADR-0007, decision 3), so a
    proved or carried result names them as a run does (ADR-0009, decision 7).

11. **A path every test runs through, and nothing holds, is named.** After the
    coverage run, a warning names each source file whose lines are executed by
    at least `holds.hotPath` of the suite's tests (a fraction from 0 to 1, 0.8
    by default, and only in suites of 20 tests or more), when no group holds
    it. The warning appears in the console, the step summary and the PR comment
    (ADR-0009): *`src/Kernel.php` is run by 412 of 430 tests and nothing holds
    it; each of its mutants runs most of the suite.* The verdict finds them in
    the coverage map the plan hands it (ADR-0014, decision 11), among the
    files of the units the run considered. It is a warning, not a failure,
    because the verdict stays correct and only its cost is at stake.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Always mutate everything** | Correct and unaffordable: a twenty-shard run on every push to every pull request. It stays available as full mode, and as the scheduled run. |
| **Infection's `--git-diff-filter`/`--git-diff-lines`** | Only one runner has it, and it knows nothing of tests reaching code, holding groups or packages. The gate computes changes once, for every runner. |
| **The diff against the base commit rather than the merge base** | On a branch behind its base, the base's newer commits read as the branch's own changes. |
| **Reach only through the coverage map, even for source changes** | A changed file is always mutated, whether or not a map exists. The map is needed for what a changed test reaches, where the diff shows no source change. |
| **A changed source file also reaches every unit whose tests run it** | Would re-run most of a project for a change to shared code, to catch mutants whose killing test changed behaviour indirectly. The scheduled full run catches those at a fraction of the cost. The in-house gate makes the same trade. |
| **Reach every dependent package whenever a package's source changes** | Every change to a shared core package would re-mutate the whole monorepo. Kept for changes that alter how a package's tests run (its manifest and test setup), which is where a dependent's verdict genuinely moves. |
| **A config file per package** | Two places answer for one monorepo, and a package's file could quietly contradict the root's. One root config, with floors in the manifests beside the code they hold, keeps one answer. |
| **Declaring held paths in config instead of in tests** | Puts the claim "these tests hold that code" away from the tests that make it. A group or attribute moves with the test, and the coverage check keeps it honest. |
| **Reading `#[Holds]` by reflection** | Loads test classes, which can run code at load time. Tokens are enough to read an attribute's argument. The Pest plugin reads it by reflection only inside Pest's own runs, which load the test files anyway. |
| **`#[Holds]` refused under Pest, with groups as the only Pest form** | One attribute would then mean different things per runner. The plugin can add the group at the point Pest adds its own, so the attribute works with both. |
| **Translating `#[Holds]` on a PHPUnit class run by Pest** | PHPUnit takes groups only from its own `Group` attribute, which is final, and the only other hook is PHPUnit's `@internal` metadata registry. Asking for the `#[Group]` beside it costs one line and patches nothing. |
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
