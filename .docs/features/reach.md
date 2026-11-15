# What a change reaches

On a pull request the gate mutates only what the change can affect, and
carries every other unit's last result. A unit is a source file, or a path a
group of tests holds
([ADR-0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md)).

## Two modes

- **Full**: every unit is considered. `--full` asks for it, and so does
  `run.full: true`.
- **Change-scoped**: only the units the change reaches are considered.
  `--changed-since=<ref>` takes the change from the merge base of a ref and
  `HEAD`, `last-passed` from this branch's newest passing commit, and
  `last-run` from the commit its last run judged. In CI a pull request reads
  its change against the branch it merges into
  ([ADR-0050](../decisions/0050-a-pull-request-is-read-against-the-branch-it-merges-into.md)).
  Every unit the change does not reach carries its newest result, where that
  result is of its source as it is now
  ([ADR-0060](../decisions/0060-a-result-carries-only-for-the-code-it-is-of.md)),
  and a unit with no result to carry is treated as reached, so a
  change-scoped run on an empty ledger is a full run.

Reach cannot see everything a change can do, so a scheduled full run on the
default branch is part of the design: it catches what reach missed, at least
once a week ([running the gate in CI](../using/ci/README.md#what-every-setup-needs)).

## What a change reaches

The first rule that matches decides, and the plan prints each decision.

1. **A file that decides how the gate runs reaches everything in its
   package**: the gate's config and every file it reads, the PHPUnit config
   and its bootstrap, the files that define the runner, `composer.json` and
   `composer.lock`, the CI definition that runs the gate, a preset's paths and
   `reach.everything`. Some of these reach everything only where what they
   decide changed:
   - the config, where a setting other than one that only judges or reports
     differs;
   - a `composer.json`, where it changed a key other than those that only
     describe the package or name its scripts, and a `composer.lock`; with
     `reach.lockPackages` set, either only where it moved a package that
     setting names or this repository ships
     ([ADR-0030](../decisions/0030-a-lock-file-decides-by-the-packages-it-moved.md),
     [ADR-0056](../decisions/0056-a-manifest-decides-how-the-gate-runs-only-by-the-keys-that-install-and-load.md));
   - a GitHub Actions workflow, where it changed what the jobs that run the
     gate read
     ([ADR-0058](../decisions/0058-a-workflow-that-runs-the-gate-is-read-by-the-jobs-that-run-it.md)).
2. **A changed source file reaches its unit.**
3. **A changed test reaches what its changed tests run**, by the coverage
   map. Under `reach.changedTests: narrow`, the default, a change inside a
   test reaches what that test runs; a change to its file's set-up, helpers,
   data providers or file-level code widens to every test of the file, and
   `file` always takes the whole file
   ([ADR-0065](../decisions/0065-a-changed-test-file-reaches-what-its-changed-tests-run.md)).
   An added import reaches nothing by itself, unless it gives a name the file
   already spelt
   ([ADR-0068](../decisions/0068-an-added-import-reaches-nothing-and-support-is-wherever-the-tests-load-it.md),
   [ADR-0069](../decisions/0069-an-added-import-of-a-name-the-file-spelt-widens.md)).
4. **Changed test support reaches the tests that use it**, and through them
   what they run. A file under `reach.fixtures` reaches only the tests that
   name its project's path or what it declares
   ([ADR-0064](../decisions/0064-a-fixture-project-reaches-the-tests-that-name-its-path.md),
   [ADR-0066](../decisions/0066-a-fixture-project-is-used-by-the-tests-that-name-what-it-declares.md)).
5. **Anything else reaches nothing by itself**: documentation, templates,
   assets.

A change that reaches no unit plans no mutant, and its verdict stands on the
verdict of the commit it is read since
([ADR-0062](../decisions/0062-a-change-that-reaches-no-unit-keeps-the-verdict-it-is-read-since.md)).

## Monorepos

A **module** is a directory with its own `composer.json` inside one project,
sharing its suite; a **package** has its own `composer.json` and PHPUnit
config, and runs as a project of its own, from `packages` or the root's
`path` repositories. Each declares floors under `extra.mutation-gate` in its
manifest, its new-code floor among them. Reach follows the dependency graph: a
change that reaches everything in one package reaches every package that
depends on it through a path repository, and a package nothing reaches is not
planned at all.

## Holding tests

Code every test runs through, such as a framework's service providers, is
reached by every changed test. A group of tests can **hold** such a path, so
that only that group judges it, and changes elsewhere stop reaching it.
[Holding tests](holding-tests.md) says how.

## The tests a change reaches

`mutation-gate affected` reads the same rules backwards and lists the tests a
change can make fail, for a plain test run: as paths, or as the ids PHPUnit
takes with `--format=ids`
([ADR-0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md)).
