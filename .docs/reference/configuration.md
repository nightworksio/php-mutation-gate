# Configuration

A config file is optional: with none, the gate takes its trees from
`phpunit.xml`, its runner from what is installed and its preset from
`composer.json`. `vendor/bin/mutation-gate init` writes one for you, and
`vendor/bin/mutation-gate config:show` prints the config a run would use.
After a major release, `vendor/bin/mutation-gate migrate` shows how the
config and the baseline move to it, and `migrate --write` writes them.

## One config, four formats

`mutation-gate.php` is the canonical format, and JSON, YAML and NEON say
exactly the same things. The four files below are the same config.

**`mutation-gate.php`**

```php
<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Config\Floor;
use NightWorksIO\MutationGate\Config\Gate;
use NightWorksIO\MutationGate\Config\Ignore;
use NightWorksIO\MutationGate\Config\Preset;
use NightWorksIO\MutationGate\Config\Report;
use NightWorksIO\MutationGate\Config\Runner;
use NightWorksIO\MutationGate\Config\Tree;

return Gate::configure()
    ->preset(Preset::laravel())
    ->runner(Runner::pest())
    ->trees(
        Tree::at('app/Domain', floor: 100),
        Tree::at('app/Http', floor: 80),
    )
    ->newCode(Floor::of(100))
    ->ignoring(
        Ignore::mutant('3f9a1c2b7d04', because: 'Both branches build the same list', until: '2027-03-31'),
    )
    ->reporting(Report::sarif('build/mutation.sarif'), Report::html('build/mutation'));
```

**`mutation-gate.json`**, checked by the published JSON Schema:

```json
{
    "$schema": "vendor/nightworksio/mutation-gate/resources/mutation-gate.schema.json",
    "preset": "laravel",
    "runner": "pest",
    "trees": [
        { "path": "app/Domain", "floor": 100 },
        { "path": "app/Http", "floor": 80 }
    ],
    "newCode": { "floor": 100 },
    "ignores": {
        "entries": [
            { "mutant": "3f9a1c2b7d04", "reason": "Both branches build the same list", "expires": "2027-03-31" }
        ]
    },
    "reports": [
        { "use": "sarif", "path": "build/mutation.sarif" },
        { "use": "html", "path": "build/mutation" }
    ]
}
```

**`mutation-gate.yaml`**, which needs `symfony/yaml`:

```yaml
preset: laravel
runner: pest
trees:
  - path: app/Domain
    floor: 100
  - path: app/Http
    floor: 80
newCode:
  floor: 100
ignores:
  entries:
    - mutant: '3f9a1c2b7d04'
      reason: Both branches build the same list
      expires: 2027-03-31
reports:
  - use: sarif
    path: build/mutation.sarif
  - use: html
    path: build/mutation
```

**`mutation-gate.neon`**, which needs `nette/neon`:

```neon
preset: laravel
runner: pest
trees:
    - {path: app/Domain, floor: 100}
    - {path: app/Http, floor: 80}
newCode:
    floor: 100
ignores:
    entries:
        - {mutant: '3f9a1c2b7d04', reason: 'Both branches build the same list', expires: 2027-03-31}
reports:
    - {use: sarif, path: build/mutation.sarif}
    - {use: html, path: build/mutation}
```

Quote mutant ids in YAML and NEON: an id such as `12e456789012` would
otherwise be read as a number. Dates need no quotes.

A `mutation-gate.php` may `require` or `include` another file by a literal
path, or by `__DIR__` and a literal. A change to that file, or to one it
includes in turn, reaches everything, as a change to the config does. The
gate reads this from the code and never runs it to find out. A config that
reads a file any other way, such as an include of a path it builds,
`file_get_contents()`, `glob()`, a class outside the builder's, or a path
outside the project, makes every change reach everything. JSON, YAML and NEON
configs read no other file: NEON's `includes:` is refused.

## Paths

Every path and glob a config file writes is named from the file's own
directory, and one that goes up out of the project, or is absolute, is
refused
([ADR-0002](../decisions/0002-one-typed-config-from-several-formats.md)).
Only the command line names a file outside the project, by its absolute
path: `--report json:/tmp/mutation.json`.

## Every key

Every key, with its type, its default and the design decision that sets it. Durations
are written `50ms`, `90s`, `15m`, `1h30m` or `7d`, and dates `YYYY-MM-DD`. A key that
chooses an adapter takes a registered name or `{"use": <name or class>,
"with": <options>}`. A glob of paths is matched against the whole path: `*`
and `?` match within one directory, and `**` across any number of them.
A `use` with a backslash is a class, so a class in the global namespace is
written `"\\SlackReporter"`; [writing an
adapter](../guide/extending/writing-an-adapter.md) says how one reads its
options.

A floor is a percentage held in hundredths, rounded up, so it is never lower
than written: `79.999` holds a score to `80`.

| Key | Type | Default | Decided in |
|-----|------|---------|------------|
| `extensions` | list of class names | `[]` | [0002](../decisions/0002-one-typed-config-from-several-formats.md) |
| `preset` | a preset name, or a list of them: `library`, `laravel`, `symfony` | chosen from `composer.json` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `runner` | adapter: `pest`, `infection`, `phpunit`, `phpspec`, `codeception`, `testo` | the one installed: `phpunit`, `phpspec`, `codeception` or `testo` only where neither Pest's mutation plugin nor Infection is, and Pest is not; Codeception installs PHPUnit, which counts beside it only where `composer.json` requires PHPUnit itself | [0002](../decisions/0002-one-typed-config-from-several-formats.md), [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md), [0027](../decisions/0027-codeception-phpspec-and-testo-get-native-runners.md) |
| `runner.withhold` | list of environment-variable names or globs the runner never hands the project's tests, added to those every run withholds; a guard against accidents, not a sandbox | `[]` | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `runner.memory` | the `memory_limit` of every PHP process a mutation run starts, as PHP writes it (`512M`, `1G`), or `-1` for none; a `memory_limit` the project sets in `phpunit.xml` or a bootstrap file wins over it. The Codeception runner passes it as Codeception's `settings.memory_limit`, which Codeception otherwise sets to `1024M` or what `codeception.yml` says. Under Infection and the PHPUnit, PhpSpec, Codeception and Testo runners it also sets `display_errors=stdout`, so a warning raised outside a test, such as in a bootstrap file, also prints on standard output | `1G` | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `runner.workers` | how the PHPUnit runner, and Pest under `pest.patch`, start each mutant's run and each run of the verification tail after it, the unmutated controls, baselines, replays, trials, confirmation and survivors' runs again: `fork`, each forked from a worker per process; `mutants`, each mutant's run forked and each run of the tail in a new PHP process; or `fresh`, a new PHP process for each. The PHPUnit runner's worker boots the autoloader and the bootstrap once; a boot that leaves a socket or database connection open, starts PHPUnit's events or loads a file the run mutates is refused: its mutants run fresh, and the run warns of why, as `doctor` does. Pest's worker loads nothing of the project, and a Laravel suite that uses `RefreshDatabase` migrates its test database once a worker: each later mutant starts on the database the first migration left, checked and its counters set back first, and migrates again where a test left it otherwise. `fork` needs `pcntl` in the PHP the runner starts, and `posix` too under Pest, and mutants run fresh without them. A run a worker forks no child for runs fresh. Under Pest the gate's own process starts the tail's workers. Infection starts its own processes, and the PhpSpec, Codeception and Testo runners start each mutant's run fresh, so it changes nothing for them | `fork` | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md), [0070](../decisions/0070-the-verification-tail-forks-from-warm-workers.md) |
| `runner.pathRules` | how a shard applies `paths`: `partitioned`, each group of its units the same entries match run after the units none matches, with a runner its settings build | `partitioned` | [0074](../decisions/0074-paths-set-mutators-timeouts-and-the-static-check-by-glob.md) |
| `runner.recycleAfter` | integer, at least 1: with the PHPUnit, phpspec, Codeception and Testo runners, how many runs a batch holds for each place, so each place has a queue of runs while a batch runs and few stand idle as its last runs end | `16` | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `runner.warmSlack` | duration: with those runners, how long a warm worker may run past the end of a run's time and the longest run's limit, for its boot and its writing | `1m` | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `runner.poll` | number of seconds, above 0 and at most 60: how long the gate waits between looks at a process it waits on to end by a deadline | `0.01` | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `runner.processes` | integer, at least 1: how many mutants a runner that runs them side by side runs at once, and how many the gate's proofs of equivalence run; a smaller count leaves a shared machine room, and a plan's estimates count with it | one per core | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `runner.workerWait` | duration: how long the gate waits for a warm worker to start, and to end once its run is done | `timeouts.startUp` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `treeSource` | adapter: `phpunit`, `composer` | `phpunit` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `treeSource.with.fallback` | list of paths, the trees when `phpunit.xml` has no `<source>` | `[]`, or the preset's; `[]` takes the `autoload` paths of `composer.json` | [0002](../decisions/0002-one-typed-config-from-several-formats.md) |
| `trees` | list of `{path, floor, reason, exclude}`, laid over the tree source's trees: a listed path takes its floor, reason and exclude from here | the tree source's trees | [0003](../decisions/0003-a-floor-only-rises.md) |
| `trees[].floor` | number, 0 to 100 | the nearest manifest's, if any | [0003](../decisions/0003-a-floor-only-rises.md) |
| `trees[].reason` | string | none; required when `floor` is 0, and refused beside any other | [0003](../decisions/0003-a-floor-only-rises.md) |
| `trees[].exclude` | list of globs, each matching a file in the tree | `[]` | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `newCode.floor` | number, 0 to 100 | `100` | [0003](../decisions/0003-a-floor-only-rises.md) |
| `security.floor` | number, 0 to 100 | none | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `uncovered` | `count` or `exclude` | `count` | [0003](../decisions/0003-a-floor-only-rises.md) |
| `baseline.path` | path of the committed floors, in the [baseline format](files.md#the-baseline). A pull request that moves the baseline is checked against the floors the branch it merges into keeps, or the default branch where its CI names none, in the file that branch's own config names | `mutation-gate.baseline.json` | [0003](../decisions/0003-a-floor-only-rises.md) |
| `baseline.improvement` | `require` or `report` | `require` | [0003](../decisions/0003-a-floor-only-rises.md) |
| `owners.floors` | map of an owner as CODEOWNERS writes it, `@user`, `@org/team` or an email address, to a number from 0 to 100: the floor of the code of every file whose rule names the owner | `{}` | [0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| `owners.mention` | `first`, the pull request comment mentions each user and team in the update that first heads a group of survivors naming it, or `never` | `first` | [0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md), [0049](../decisions/0049-an-owner-is-mentioned-once-and-a-merge-group-trusts-its-pull-request-as-a-merged-one.md) |
| `versionControl` | adapter: `git`, `github`, or a change source and repository an extension registers under one name: where a run learns what changed and what the repository holds | `github` under GitHub Actions, otherwise `git` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `packages` | list of globs | `[]` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `reach.everything` | list of globs, from the repository's root | `[]`, plus the preset's | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `reach.lockMoves` | `everything`, **`named`** or **`loaded`**. What a package's move in a `composer.lock` reaches: every unit; every unit only where it moved what `reach.lockPackages` names; or the units run by the tests that loaded a moved package, and by each test that started a process. `loaded` still reaches every unit where a package was added or removed, moved its `autoload.files`, `extra` or `type`, where the lock moved anything besides its packages, or where the coverage map records no loads for some test: the first run after setting it. **Each value but `everything` can miss a package a test reaches, so a kill it carries may no longer hold: under `loaded`, a child process not yet waited for when its test ends, a worker already running, a package read as data rather than loaded, and a preloaded one are not seen. Run the whole suite on a schedule.** It records under the Pest runner alone; another runner reaches every unit. See [ADR-0115](../decisions/0115-a-lock-move-reaches-what-loaded-the-package.md) | `everything`, `named` where a layer lists `reach.lockPackages` | [0115](../decisions/0115-a-lock-move-reaches-what-loaded-the-package.md) |
| `reach.lockPackages` | list of Composer package names, `vendor/name`, where `*` stands for any run of characters within either part: a change to a `composer.lock` decides how the gate runs only where it moved one of these or a package this repository ships, or a key besides the package lists; a change to a `composer.json` only where it moved such a package's line in `require`, `require-dev`, `conflict`, `replace` or `provide`, or a key besides those and the keys that only describe the package or name its scripts | none: every package decides | [0030](../decisions/0030-a-lock-file-decides-by-the-packages-it-moved.md), [0056](../decisions/0056-a-manifest-decides-how-the-gate-runs-only-by-the-keys-that-install-and-load.md) |
| `reach.changedTests` | `narrow`: a changed test file reaches what the tests it changed run, by the coverage map's ids of them, and what `file` reaches where the change also moved code they share, such as a hook, a helper, a data provider, an import it removed or changed, or an import it added of a name the file already spelt; or `file`: what all its tests run, and what the runner judges by the file | `narrow` | [0065](../decisions/0065-a-changed-test-file-reaches-what-its-changed-tests-run.md), [0068](../decisions/0068-an-added-import-reaches-nothing-and-support-is-wherever-the-tests-load-it.md), [0069](../decisions/0069-an-added-import-of-a-name-the-file-spelt-widens.md) |
| `reach.fixtures` | list of globs, from the repository's root, each matching the directory of a fixture project the tests read: a change to a file in one reaches only what the tests that name its path, or a class or function its PHP declares, run, directly or through test support | `[]` | [0064](../decisions/0064-a-fixture-project-reaches-the-tests-that-name-its-path.md), [0066](../decisions/0066-a-fixture-project-is-used-by-the-tests-that-name-what-it-declares.md) |
| `reach.defaultBranchSince` | `judged`, `judged-off-branch` or `passed`: what the default branch's changes are read since. `judged` reads them since its last judged run, passed or failed, for every unit, and since its last pass for the units that run left open; `judged-off-branch` does so off the default branch, a push to it reading since its last pass; `passed` reads since its last pass on every run. It decides how the gate runs | `judged` | [0141](../decisions/0141-a-failed-run-on-the-default-branch-still-stands-for-the-units-it-fully-judged.md) |
| `holds.hotPath` | number, 0 to 1 | `0.8` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `holds.fewestTests` | The fewest tests a suite needs for `holds.hotPath` to name a file hot | `20` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `holds.coverage` | `measure` or `map` (**loosens under `uncovered: exclude`**): how a shard learns which lines a held unit's holding tests cover. `measure` runs them alone under coverage, and they must pass; `map` reads their lines from the map the plan handed the shard, and does not check that they pass alone. It acts on no mutant a split stream judged | `measure` | [0142](../decisions/0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md) |
| `run.full` | boolean: a run given neither `--full` nor `--changed-since` considers every unit, rather than what changed since `last-passed` | `false` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `run.failFast` | `off`, `mark`, `stop` or `cancel`: what a pull request's shard does once a confirmed survivor leaves its run no way to pass: nothing, so the verdict alone fails; fail once it has judged every unit, so the verdict names every survivor, and warn what stopping would have saved; stop there, its other units unjudged, and fail; or stop, fail and, in the reusable workflow, have GitHub cancel the other shards, which acts as `stop` on any other CI | `mark` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md), [0103](../decisions/0103-a-doomed-shard-fails-fast.md) |
| `run.failFastWaits` | list of `unidentified`, `no-warm-up`, `out-of-scope`, `no-mutant`, `print-differs`, `failed` or `out-of-time`: why static analysis may leave a survivor unchecked that a shard waits on rather than counting it as a survivor that dooms the run, since a check may still kill it; `[]` lets every unchecked survivor doom it | `[out-of-time, failed]` | [0103](../decisions/0103-a-doomed-shard-fails-fast.md) |
| `plan.chunkMinutes` | integer, at least 1: how much expected work, in minutes, a chunk of a shard that can stop once it cannot pass holds | `15` | [0032](../decisions/0032-a-pull-requests-shard-stops-in-chunks-of-fifteen-minutes.md) |
| `plan.provingRange` | integer, at least 0: how many commits since a base a change source asks GitHub to have proved, one question each, before it reads the range whole. Past it, a full reach is the safe answer | `20` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `timing.newest` | number above 0 and at most 1: the weight a unit's newest measurement takes in its smoothed timing, the earlier one taking the rest | `0.9` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `timing.startUpSamples` | integer, at least 1: how many runs of no test a plan times to read the start-up; their median is the least noisy estimate of a fixed cost | `3` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `shards.seconds` | integer | `600` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `shards.max` | integer, or `"learned"`: the most shards the default branch's last 10 runs ran at once, by `shards.learnedFrom`, kept between 1 and 20; 20 until a sharded run is learned. The plan prints what it learned | `20` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0073](../decisions/0073-a-limit-can-be-learned-from-what-earlier-runs-measured.md) |
| `shards.learnedFrom` | `max` or `median`: which of the shard counts the last 10 runs ran at once a learned `shards.max` takes | `max` | [0073](../decisions/0073-a-limit-can-be-learned-from-what-earlier-runs-measured.md) |
| `shards.cutAt` | number of at least 0: how many times its overhead, its opening run and `shards.setup`, a shard's share of the cost must be at least, for a cut to make it | `2.0` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `shards.target` | duration; replaces `shards.seconds`, and setting both is an error | none | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `shards.setup` | duration: each shard's CI setup before the gate starts. With the opening run it is a shard's overhead, and no shard is cut whose share of the cost is below `shards.cutAt` times that | `1m` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `shards.jobLimit` | duration: how long a shard's job can run. The cut keeps each shard inside it while `shards.max` leaves room, the plan says where the longest cannot fit, and a CI shard stops at it, keeping what it judged, sooner than its job is cancelled with nothing | `5h40m` | [0114](../decisions/0114-a-shard-is-cut-to-fit-its-job-and-stops-before-it.md) |
| `shards.pull` | `auto`, `claim`, `steal` or `off`: how a shard finds its work on GitHub Actions. Every value but `off` has the plan cut each package's work into items of `plan.chunkMinutes`, or under test parts into as many parts, each listed first for one of its shards, longest first; a push's shard then runs in chunks too. Under `claim` a shard claims each item by an empty artifact named for the plan, the run's attempt and the item before it runs it, through the artifact service the action hands the gate's process alone in a file it removes once read, working through its own list and then the other shards' lists from their backs; under `steal` it skips an item a done marker names and marks each item it finished, so two shards may run one item. `auto` steals, since no service's claims pass the gate's nightly claim race: github.com accepts a second claim of an item once the first is written. A shard whose claims cannot be written or read runs its own items, and says so. The verdict needs one result for every item; where two runs of an item judged a mutant differently, it is flaky. It changes no result | `auto` | [0134](../decisions/0134-shards-pull-the-work-left-instead-of-owning-a-fixed-part.md) |
| `costs.secondsPerLine` | map of path prefix to number | `{"": 0.2}` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `costs.perRunnerMinute` | `{amount, currency}` | none | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `costs.savingsWindow` | duration: how far back the runner time the gate saved is counted, which the step summary, `savings.json` and `summary.json` show | `30d` | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md), [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `costs.model` | adapter: `learned`, or a cost model an extension registers: what the plan expects each unit's work to cost | `learned` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `costs.testsBeforeAKill` | integer: how many covering tests of average time a first estimate expects a killed mutant to run before one kills it. Higher plans more shards for a suite whose tests rarely kill first; lower plans fewer, and such a suite runs longer than planned | `5` | [0114](../decisions/0114-a-shard-is-cut-to-fit-its-job-and-stops-before-it.md) |
| `costs.surviving` | number from 0 to 1: the share of mutants a first estimate expects to survive and run every covering test. Raise it for a project with many survivors, which a low share plans too few shards for; a high one plans too many for a well-tested project | `0.02` | [0114](../decisions/0114-a-shard-is-cut-to-fit-its-job-and-stops-before-it.md) |
| `costs.shardOverhead` | number, at least 1: how many times its mutants' own runs a first estimate takes a shard to be, with its controls, baselines and static checks. Higher pays for more shards on a first run and leaves more room under `shards.jobLimit`; lower risks a shard stopping there with mutants unjudged. All three apply only until a shard has timed the unit | `2.5` | [0114](../decisions/0114-a-shard-is-cut-to-fit-its-job-and-stops-before-it.md) |
| `ci.plan` | adapter: `github`, `gitlab`, `buildkite`, `circleci`, `azure`, `bitbucket`, `jenkins`, `json` | detected from the environment | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `ci.defaultBranch` | branch name | the CI's answer, else git's `origin/HEAD`, else `main` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.check` | the check-run name the verdict reports under | `mutation / verdict` | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `ci.schedule` | five cron fields: when the full run `init --ci` writes runs, on GitHub Actions and Jenkins. It reaches the units no change does | `0 3 * * 1` | [0122](../decisions/0122-a-push-spends-only-what-its-pull-request-did-not.md) |
| `ci.trustMergedPullRequests` | `false`, **`true`** or **`delta`**: what the default branch takes from a merged pull request's own recorded pass. `true` spares a commit whose tree is the head the pull request's verdict passed on, and a commit on which a merge group's verdict passed, and a merge group whose commit holds its pull request's head tree reaches nothing; each carries the results the pull request's ledger holds, as `ci.mergedOwnScopeProofs` allows. `delta` also takes a pull request whose landed tree differs, and reaches only the change from its judged head. Either trusts the pull request's own computed result, an untrusted write. The Actions cache keeps it out of a push's reach, so the action and the reusable workflow carry it as an artifact under their `merged-records` input | `false` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md), [0049](../decisions/0049-an-owner-is-mentioned-once-and-a-merge-group-trusts-its-pull-request-as-a-merged-one.md), [0139](../decisions/0139-a-merged-pull-request-s-pass-reaches-its-push-as-an-artifact-of-its-run.md) |
| `ci.mergedOwnScopeProofs` | `reach`, `refuse` or **`trust`**: what a merged pull request's pass that took results from its own scope's proofs is trusted for. `reach` carries its other results and runs the units it took from its own scope again; `refuse` takes no such pass; `trust` takes it whole | `reach` | [0139](../decisions/0139-a-merged-pull-request-s-pass-reaches-its-push-as-an-artifact-of-its-run.md) |
| `ci.gitlab.template` | path | `.gitlab/mutation-gate.yml` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.buildkite.step` | map of step keys | `{}` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.buildkite.definition` | path of the pipeline file that runs the gate under Buildkite | `.buildkite/pipeline.yml` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.azure.definition` | path of the pipeline file that runs the gate under Azure DevOps | `azure-pipelines.yml` | [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `ci.bitbucket.definition` | path of the pipeline file that runs the gate under Bitbucket Pipelines | `bitbucket-pipelines.yml` | [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `ci.jenkins.definition` | path of the Jenkinsfile that runs the gate under Jenkins | `Jenkinsfile` | [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `proofs.store` | adapter: `directory`, `s3`, `gcs`, `azure` | `directory` | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.path` (`directory`) | path | `.mutation-gate/ledger` | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.bucket` (`s3`) | string | none; required | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.prefix` (`s3`) | string | `mutation-gate` | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.region` (`s3`) | a region's name: lowercase letters and digits in parts joined by single hyphens, such as `eu-west-1`, or R2's `auto` | `us-east-1` | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.endpoint` (`s3`) | `https://` URL, or `http://` where `insecureEndpoint` is true | AWS's own | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.insecureEndpoint` (`s3`) | boolean: whether an `http://` endpoint is allowed, for a store on a network you trust; it sends the signed requests and the ledgers in the clear | `false` | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.publicUrl` (`s3`) | `https://` URL a run without credentials reads the default branch's ledger from, at `<publicUrl>/<prefix>/refs/heads/<default branch>/ledger.json.gz`; one with a user, a query or a fragment is refused | none | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `proofs.store.with.bucket` (`gcs`) | a Cloud Storage bucket's name | none; required | [0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.store.with.prefix` (`gcs`, `azure`) | string | `mutation-gate` | [0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.store.with.publicUrl` (`gcs`) | `https://storage.googleapis.com/<bucket>`, over a managed folder `<prefix>/refs/heads/<default branch>/` that `allUsers` may read | none | [0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.store.with.account` (`azure`) | a storage account's name | none; required | [0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.store.with.container` (`azure`) | the private container every scope but the default branch's is kept in | none; required | [0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.store.with.publicContainer` (`azure`) | a container at the `Blob` access level, which keeps the default branch's scope | none | [0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.store.with.publicUrl` (`azure`) | `https://<account>.blob.core.windows.net/<publicContainer>` | none | [0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.ignore` | list of globs | `[]` | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.write` | `auto` or `never` | `auto` | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.copies` | where the store's copy of a ledger, which `fetch` writes into `.mutation-gate/fetched`, and the copy the run keeps both hold a scope's ledger, the one the run reads: `newest`, the copy with the later record, the store's where neither is later, for most projects; `together`, the store's copy, with the reusable workflow keeping its own copy only once the store holds the same, where the cache must never get ahead of the store, as where a fork's run reads it through `publicUrl`, or another system reads it; or `merged`, both read as one, where each copy can hold proofs the other lacks, as where another pipeline also writes the store | `newest` | [0061](../decisions/0061-a-run-reads-the-copy-of-a-ledger-proofs-copies-chooses.md) |
| `proofs.ownScope` | boolean: a run takes the proofs its own scope's earlier runs left, so a new push to a pull request mutates again only the units whose key moved; `false` reads none of them, and every unit the run reaches runs. The default branch and a merge group take none of a pull request's proofs either way. The plan says how many units the scope's earlier runs stand for | `true` | [0081](../decisions/0081-a-new-push-mutates-only-what-its-earlier-runs-do-not-prove.md) |
| `proofs.keep.killers` | integer of at least 1, or `all`: how many mutants a ledger keeps the killers of, those that learned one most recently. `all` lets the byte limits alone cap them | `all` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `proofs.keep.bases` | integer, at least 1: how many of the bases its runs saw most recently keep their proofs | `5` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `proofs.keep.proofs` | integer, at least 1: how many proofs a ledger keeps at most, the newest | `20000` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `proofs.keep.functions` | integer, at least 1: how many functions keep their killers at most, those that learned one most recently | `5000` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `proofs.keep.kills`, `proofs.keep.runs` | How many kills the learned timeout multiple is read from, and how many runs the learned shard count is, the newest of each, as a ledger keeps them | `2000`, `10` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `proofs.limits.ledger.packed`, `proofs.limits.ledger.unpacked` | integer of bytes, at least 1: the most of a ledger, compressed and decompressed, a store reads or writes. A ledger past them loses its oldest proofs where it is written, and is refused where it is read. `deliver`, which reads no config, reads and writes within the defaults | `11000000`, `38000000` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `proofs.limits.map.packed`, `proofs.limits.map.unpacked` | integer of bytes, at least 1: the most of a kept coverage map, compressed and decompressed. A map past either is not kept, which costs the next run a full measure and never a verdict | `4200000`, `85000000` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `proofs.limits.handoff.packed`, `proofs.limits.handoff.unpacked` | integer of bytes, at least 1: the most of a coverage map one job hands another, compressed and decompressed. Unset, the decompressed limit is what the reading process's memory holds | `256000000`, what memory holds | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `proofs.limits.read` | The longest one read of a ledger over a network may take | `60s` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `proofs.compression` | `auto`, `gzip` or `zstd`: how a ledger's file is compressed. `auto` writes zstd where PHP's zstd extension (0.13 or later) is loaded or the `zstd` program is on the path, and gzip where neither is; `zstd` leaves the ledger unwritten where neither is, and says why. A reader tells the two by the file's first bytes, whatever wrote it, and one with no zstd says what to install | `auto` | [0085](../decisions/0085-the-ledger-is-compact-and-compressed-with-zstd-where-it-can-be.md) |
| `proofs.compressionLevel` | integer, 1 to 19: the level a ledger is packed at; gzip packs at 9 at most | `9` | [0085](../decisions/0085-the-ledger-is-compact-and-compressed-with-zstd-where-it-can-be.md) |
| `coverage.incremental` | boolean: whether a run measures again only the test files whose coverage could have moved, keeping the rest from the map its own scope keeps, or else the default branch's | `true` | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `coverage.compressionLevel` | 1 to 9: the gzip level a coverage map the gate keeps beside the ledger or hands its shards is packed at. It changes only the map's bytes | `6` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `budget` | duration | none | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.mode` | `confirm` or `unjudged` | `confirm` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.headroom` | number from 0 to 0.9: the share of a timed-out mutant's limit its tests, run unmutated, must leave unused for the timeout to count as a kill; a mutant whose tests leave less is too slow to judge | `0.1` | [0108](../decisions/0108-no-shortcut-stands-on-a-result-its-tests-or-its-evidence-no-longer-hold.md) |
| `timeouts.seconds` | integer, or `"learned"`: three times the start-up each shard measures, at least 1 s, and 10 s in a shard that measures none; an integer is the least a mutant's run is allowed, and what one whose covering tests were not all timed is; each mutant otherwise gets three times the start-up its shard's run measured (5 s where it measured none) plus `timeouts.multiple` times its covering tests' own time, within the two bounds. Under `pest.patch`, `infection:patch` and with the PHPUnit, PhpSpec, Codeception and Testo runners, a mutant's run is also stopped where no test finishes for that rule of its slowest covering test's time. Infection does so under `infection:patch`; unpatched, it keeps its own limit under `timeouts.most`, with no floor, and every run warns of it | `10`; `30` in the `laravel` and `symfony` presets | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.most` | integer, at least `timeouts.seconds`: the most a mutant's run is allowed; unpatched Infection skips a mutant whose covering tests take as long | `300` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.multiple` | number of at least 1, or `"learned"`: how many times its covering tests' own time each mutant is allowed. Learned, it is what 99 in 100 of the last 2,000 kills the ledger holds took over their covering tests' own time, kept between 2 and 10, and 3 until a kill is learned. The plan prints what it learned | `3` | [0073](../decisions/0073-a-limit-can-be-learned-from-what-earlier-runs-measured.md) |
| `timeouts.startUp` | duration: what a mutant's limit allows a run to start in where nothing measured its start-up; where one was measured, the limit is laid on `timeouts.startUpMultiple` times it | `5s` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.startUpMultiple` | number, at least 1: how many times a run of no test's measured start-up a mutant's limit allows it to start in, and how many times it a learned `timeouts.seconds` is | `3` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `timeouts.tighter.mutators` | list of mutators by their short names, the last part of the name a runner gives a mutator, after its last `\` or `/`: `RemoveArrayItem` matches Pest's `Pest\Mutate\Mutators\Removal\RemoveArrayItem` and the default set's `default/RemoveArrayItem`. A mutant of a listed mutator has its silence limit kept above `timeouts.tighter.floor` instead of `timeouts.seconds` | `RemoveArrayItem`, `DecrementInteger`, `IncrementInteger`, `ForeachEmptyIterable`, `UnwrapArrayValues`, `InstanceOfToTrue`, `InstanceOfToFalse`, `TernaryNegated`, and Infection's `ArrayItemRemoval`, `Foreach_`, `InstanceOf_` and `Ternary` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.tighter.floor` | integer, at least 1: the floor of the silence limit of a mutant of a mutator `timeouts.tighter.mutators` lists, where it is under `timeouts.seconds`; its whole-run limit keeps `timeouts.seconds` | `7` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.perTest` | integer, at least 1, or `false`: each test of a mutant's run, and of a timed-out mutant's unmutated control, is stopped once it has run this many times its own time, as the coverage map timed it, or `timeouts.testFloor` where that is longer; the mutant is a timeout whose reason names the test, and a control stopped so leaves its timeout too slow to judge. A test the map did not time is held only to the run's silence limit and whole limit. Applies under `pest.patch` and with the PHPUnit, PhpSpec, Codeception and Testo runners. `false` holds no test to a limit of its own | `3` | [0090](../decisions/0090-each-test-of-a-mutants-run-is-held-to-a-multiple-of-its-own-time.md) |
| `timeouts.testFloor` | integer, at least 1: the least seconds a test is allowed under `timeouts.perTest` | `3` | [0090](../decisions/0090-each-test-of-a-mutants-run-is-held-to-a-multiple-of-its-own-time.md) |
| `timeouts.controlMost` | integer, at least `timeouts.most`: the most a kill's unmutated control is allowed. It runs the tests its mutant's run selected, the standard limit of their time, and a run that left its filter out, as too long to pass, selected every test of the files it loaded, which take far longer than the tests that failed in it. Below what the control needs, it runs out and leaves the kill unjudged | `900`, or `timeouts.most` where that is longer | [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| `timeouts.learned.percentile`, `timeouts.learned.least`, `timeouts.learned.most` | How `timeouts.multiple: learned` reads a ledger's kills: the share of them the multiple covers, between 0 and 1, kept between the least and the most | `0.99`, `2`, `10` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `timeouts.heavyFill` | boolean: a round of heavy mutants' parts runs each mutant's next part and fills the rest of the pool with parts ahead. It changes when a mutant is judged and never how: what a part ahead found is read in the order of the parts. It trades runs past a part that kills for a pool with no idle slot; off, a heavy mutant runs one part at a time | `true` | [0114](../decisions/0114-a-shard-is-cut-to-fit-its-job-and-stops-before-it.md) |
| `timeouts.heavyParts` | integer, at least 1: a heavy mutant no part has settled by this many parts is left unjudged, which fails the verdict. It trades a red verdict on every such mutant, which a later part might have killed or passed, for the minutes those parts take, and its unit keeps no proof, so it runs again on every run; a higher cap judges them. Never a pass, and never a kill | off: every part runs | [0114](../decisions/0114-a-shard-is-cut-to-fit-its-job-and-stops-before-it.md) |
| `timeouts.parts` | boolean: whether a mutant whose tests cannot fit `timeouts.most` in one run is judged in parts; off, it is unjudged | `true` | [0142](../decisions/0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md) |
| `flaky.killControls` | `order`: a kill's unmutated control runs its run again in its order, up to its last killer where the runner recorded the order and otherwise all it selected, so a test that fails only after the tests before it kills nothing. **`alone` loosens**: it runs the killers alone, which is quicker and gives up that proof; `doctor` advises against it, and the verdict names it as a loose value in force. Under Pest, `alone` only turns off the replay of a run that loaded every test file: a narrowed run's replay stays, since it also vouches for the files that run left out. | `order` | [0143](../decisions/0143-a-kills-control-replays-its-order.md) |
| `flaky.confirmSurvivors` | boolean: run each survivor once more, alone, and report one killed then as flaky; where `splitStream.sharding: tests` cuts the test files across the shards, each shard runs its own survivors again by its part of their covering tests, or by every covering test for a mutant no stream can ever judge. A suite whose CI runs each test file alone may turn it off | `true` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md), [0034](../decisions/0034-a-suite-whose-test-files-pass-alone-need-not-confirm-survivors.md) |
| `tests.order` | `killers-first` or `runner` | `killers-first` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `tests.suites` | list of `<testsuite>` names of the PHPUnit config, at least one: the suites whose tests judge | every suite | [0002](../decisions/0002-one-typed-config-from-several-formats.md) |
| `tests.holding` | list of `<testsuite>` names, none in `tests.suites`: the suites whose tests judge only the units they hold with `#[Holds]` or a `holds:` group, adding to their judges. An empty list holds none, as leaving it out does, and where `tests.suites` names the suites that judge, the verdict notes that the other suites' tests judge no mutant unless `suggest.emptyHolding` is off | none | [0002](../decisions/0002-one-typed-config-from-several-formats.md), [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md), [0116](../decisions/0116-an-empty-holding-list-is-allowed-and-noted.md) |
| `survivorsFirst.max` | whole number, at least 0: how many of the last run's survivors a pull request's run re-checks before its shards, those on changed lines first; `0` re-checks none | `20` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `survivorsFirst.budget` | duration, as `20m` or `1h`: the time the re-check spends at most; a file of survivors it has no time left for is not run, and the comment says how many | `20m` | [0106](../decisions/0106-carried-survivors-are-judged-again-and-their-re-check-is-bounded-and-shared.md) |
| `survivorsFirst.shards` | whole number, 1 to 20: how many jobs share the re-check at most, each taking its share of the files of survivors; never more than there are such files | `4` | [0106](../decisions/0106-carried-survivors-are-judged-again-and-their-re-check-is-bounded-and-shared.md) |
| `ignores.entries` | list of `{mutant, reason, expires}` or `{path, mutator, reason, expires}` | `[]` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `ignores.maxDays` | integer | none | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `ignores.native` | `refuse` or `allow` | `refuse` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `ignores.notice` | duration in days: how far ahead an ignore's end is named, in the comment and in `doctor` | `14` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `equivalence.static` | boolean | `true` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `reports` | list of `{use, path, with}`; built-in `json`, `junit`, `sarif`, `html`, `stryker`, `digest`, `tests`, `kill-matrix`, `gitlab`, `sonar`; `badge`, whose `path` is `--publish-dir` where it names none; and without a `path` `console`, `problems`, `slack`, `discord`, `webhook`, `otlp`, `github-annotations`, `github-summary`, `github-comment`, `gitlab-comment`, `bitbucket-comment`, `bitbucket-insights`, `azure-comment`, `azure-summary`, `buildkite-annotation`. A config names every `path` inside the project. `with` holds a built-in reporter's options: `problems` `{only: changed}`; `github-comment` `{identity: <account>}`; `badge` `{commit: <sha>}`, the commit the trend records, which is otherwise `GITHUB_SHA`, `CI_COMMIT_SHA`, `BUILDKITE_COMMIT` or `CIRCLE_SHA1`, and `{colors, trendKept}`, which take `badge.colors` and `badge.trendKept` where it sets none; `html` `{files: 8, survivors: 25}`, how many of the lowest-scoring files and of the survivors the page lists; `slack`, `discord` and `webhook` `{urlEnv: <variable>}`, the variable holding the URL, and never the URL itself, and `{timeout: 10s, longestWait: 30s}`, how long one post may take and the longest `Retry-After` it waits out; `slack` and `discord` `{survivors: 5, lineLength: 120}`, how many survivors the message lists and how long each line is; `webhook` `{secretEnv: <variable>}`; `otlp` `{endpoint: <https URL>, timeout: 5s}`, how long one post may take; `gitlab-comment` and `bitbucket-comment` `{tokenEnv: <variable>}`, the variable their token is read from, `MUTATION_GATE_GITLAB_TOKEN` and `MUTATION_GATE_BITBUCKET_TOKEN` by default, and they refuse a `token` written in the config ([reports](reports.md)) | `[]` | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md), [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `suggest.enabled` | boolean: whether the gate suggests changes: what `config:suggest` proposes, which otherwise says suggestions are off; what stopping a doomed pull request's run would have saved; the files most of the suite runs through that nothing holds; and what an empty `tests.holding` means. Turn it off for one CI with that CI's `contexts` block | `true` | [0104](../decisions/0104-suggestions-can-be-turned-off-and-each-ci-has-a-context.md) |
| `suggest.emptyHolding` | boolean: whether the verdict notes what `tests.holding` written as an empty list means, where `tests.suites` names the suites that judge: the other suites' tests judge no mutant, not even of the code they hold. Off with `suggest.enabled` | `true` | [0116](../decisions/0116-an-empty-holding-list-is-allowed-and-noted.md) |
| `suggest.looseValues` | boolean: whether the verdict and the pull request's comment note each loose value in force; `config:show` marks them whatever it says, and `suggest.enabled: false` turns the note off too | `true` | [0142](../decisions/0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md) |
| `suggest.idle.judged` | integer, at least 1: how many of a mutator's mutants the ledger's tally must hold judged, killed or survived, before `config:suggest` calls it idle and proposes turning it off | `500` | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| `suggest.idle.survivors` | integer, at least 0: the most survivors an idle mutator's tally holds | `0` | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| `suggest.idle.share` | number, 0 to 100: the least percent of every mutator's runner time an idle mutator's mutants took | `5` | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| `suggest.ignored.share` | number, 0 to 100: the least percent of a mutator's mutants an ignore, a runner's marker or proof of equivalence left out before `config:suggest` calls it noisy and proposes turning it off | `80` | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| `suggest.ignored.mutants` | integer, at least 1: how many mutants a mutator's tally must hold before it can be noisy | `50` | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| `suggest.timeouts.cluster` | integer, at least 1: how many mutants of one directory, in the newest proof of each unit, must have run out of time before `config:suggest` proposes a `paths` entry that doubles its files' `timeouts.seconds` | `3` | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| `heatmap.files` | integer, at least 0: how many of the lowest-scoring files `summary.json` lists, which an organisation's dashboard shows under each repository | `10` | [0100](../decisions/0100-a-heatmap-scores-every-file-and-function-against-the-base.md) |
| `heatmap.methods` | boolean: whether the heatmap scores each function and method of a file, and each proof names the functions that hold its mutants | `true` | [0100](../decisions/0100-a-heatmap-scores-every-file-and-function-against-the-base.md) |
| `digest.tests` | integer, at least 0: how many of a mutant's covering tests `digest --detail=<id>` names, which `--tests=<n>` overrides | `5` | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `outputs.verdictOnly` | boolean: a run writes the verdict alone, not the reports where it is, under `outputs.automatic`, nor those the action asks for, under `outputs.actionReports`. Those `reports` lists are written still | `false` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `outputs.automatic` | list of `github-annotations`, `github-summary`, `github-comment` and `badge`: the reporters a run writes because of where it is. A run writes the re-check of survivors only where the comment or the summary reads it | all four | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `outputs.actionReports` | list of `json`, `html` and `digest`: the reports the action and the reusable workflow ask of the verdict | all three | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `outputs.costs` | boolean: the reports say what the run cost and saved | `true` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `comment.sections` | list of `verdict`, `security`, `trees`, `sets`, `heatmap`, `survivors`, `unjudged`, `ignored`, `problems`, `notes`, `floors`, `report` and `costs`, each once, `verdict` first: the sections every `*-comment` reporter's comment shows, in this order; a run that cannot judge says why whatever it lists. A reporter's `with.comment` sets any `comment` key for its comment alone | every section, in that order | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.open` | list of sections: those shown open; every other section is folded, except on Bitbucket, which cannot fold | `[verdict, trees, heatmap]` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.states` | list of `planned`, `rechecked` and `verdict`: the states of a run the comment is written in | `[planned, rechecked, verdict]` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.on` | list of `pull-request`, which takes a GitLab merge request too, and `default-branch`: the kinds of run that comment; `buildkite-annotation`, whose non-pull-request builds count as `default-branch`, takes both by default | `[pull-request]` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.mode` | `sticky`, one comment updated in place; `new`, a new comment each run; or `new-hiding-old`, a new one that hides the one before, on GitHub | `sticky` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.passing` | `update`, a passing run updates a comment already there and writes no new one; `always`; or `never`, which writes none and says why | `update` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.try` | boolean: whether each survivor says what test would kill it | `true` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.reproduce` | boolean: whether each survivor says how to reproduce it | `true` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.drafts` | boolean: whether a draft pull request's run comments | `true` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.sampled` | boolean: whether a sampled run comments | `true` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.cutShort` | boolean: whether a run its budget cut short comments | `true` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.survivors.most` | integer, at least 0: the most survivors the comment lists; `0` leaves the section out | `20` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.survivors.lines` | `changed`, the survivors on lines the change touched, or `all` | `changed` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.survivors.detail` | `full`, each survivor folded with its diff, hint, what to try and how to reproduce it, or `row`, one table row each | `full` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.security.most` | integer, at least 0: the most security survivors the comment lists | `20` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.unjudged.most` | integer, at least 0: the most unjudged and flaky mutants the comment lists | `20` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.ignored.most` | integer, at least 0: the most ignored mutants the comment lists | `20` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.heatmap.most` | integer, at least 0: the most files the change reaches that fell since the base the comment lists, with any below its tree's floor | `5` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.text.most` | integer, at least 0: the most characters of one diff or hint the comment shows; the whole comment is fitted to what the channel holds | `1500` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `badge.colors` | map of shields.io colour to lowest score | `{"brightgreen": 90, "green": 80, "yellow": 70, "orange": 60}`, red below | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `badge.trendKept` | integer, at least 1: how many of the newest entries `trend.json` keeps, which the sparkline draws and the savings are counted from | `500` | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `dashboard.topic` | string: the GitHub topic of the repositories `mutation-gate dashboard` shows, where `dashboard.repositories` names none | `"mutation-gate"` | [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `dashboard.repositories` | list of the names of the organisation's repositories that `mutation-gate dashboard` shows, in place of a topic | none | [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `pest.patch` | boolean | `false` | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `pest.canary` | group name, with no whitespace | `mutation-canary` | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `pest.bootTail` | boolean: under `pest.patch` and `runner.workers: fork`, the verification tail's warm workers boot the project, every test file of the suites a run names loaded, before they fork its controls, confirmation and other runs of every test file; runs of some test files, and every mutant's own run, keep the unbooted worker. A run of a file the boot loaded, a project whose PHPUnit config caches its test index, and a boot that leaves a socket open run fresh | `false` | [0075](../decisions/0075-the-verification-tail-may-fork-from-a-booted-pest-worker.md) |
| `pest.workerOpcache` | boolean: under `pest.patch` and `runner.workers: fork`, Pest's warm workers compile through opcache, which every run they fork shares, with each file the run mutates, pest-plugin-mutate's copies and the gate's own directory kept out of it by `opcache.blacklist_filename`, so no mutant or original a mutant is served in place of is ever cached; `false` runs them with opcache off, every run compiling Pest, PHPUnit and the project afresh | `true` | [0088](../decisions/0088-pest-workers-compile-through-opcache-keeping-mutated-files-out.md) |
| `pest.schemata` | boolean: under `pest.patch`, each file's mutants are compiled into one schema, every mutant a switch on the line its original stands on, and a mutant's own run is served the schema in its file's place, its switch in force, rather than a mutated copy of its own. A mutant whose change cannot be switched where it stands, in a constant expression, a default, an attribute, a signature, a string's interpolation, a place written to, a call's result passed as an argument, a declaration or a change printed on more than one line, keeps its own copy. Pest's mutant cache is not read while it is on, so each mutant's node is at hand | `true` | [0091](../decisions/0091-a-files-mutants-are-compiled-into-one-schema.md) |
| `pest.bootMutants` | boolean: under `pest.patch`, where `runner.workers` forks mutants' own runs, each warm worker of them boots the project once before it forks: Composer's autoloader, Pest's kernel, the PHPUnit config's PHP settings and bootstraps, with each file's schema served in its place, and no test file loaded. Each child boots Pest's plugins and loads its own test files. A mutant of a file the worker loaded, other than through a schema that switches it, runs fresh, as does every run of a worker whose boot left a socket open; a project with no PHPUnit config has each run forked unbooted. Off, each child boots the project as a fresh run does | `true` | [0091](../decisions/0091-a-files-mutants-are-compiled-into-one-schema.md) |
| `pest.bootChunks` | boolean: under `pest.patch` and `runner.workers: fork`, whether a shard's chunks keep the warm workers of their mutants' own runs for the next chunk, each living as long as the gate's process. A worker is kept only where `pest.bootMutants` and `pest.workerOpcache` are off, so it boots nothing of the project and caches nothing, and every chunk forks from the state a fresh worker starts in. It changes no output | `false` | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `pest.trials` | boolean (**`false` loosens under `uncovered: exclude`**): whether a mutant left uncovered on a line that is not executable is tried; off, it stays uncovered, which is stricter under `uncovered: count`. It acts on no mutant a split stream judged | `true` | [0142](../decisions/0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md) |
| `pest.valueReaders` | boolean: whether a declared value's lines are covered by the tests that read it (ADR-0037); off, its mutants are tried instead | `true` | [0142](../decisions/0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md) |
| `pest.narrow` | boolean: whether a patched mutant's own run loads only the test files its covering tests need; off, every run loads every test file, and no narrowed kill needs vouching for | `true` | [0142](../decisions/0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md) |
| `pest.narrowedKills` | `vouch` or **`trust`**: what a kill in a narrowed run needs before it counts. `vouch` takes its replay, its alone run or its run again with every test file; `trust` counts it as the narrowed run found it. It acts on no mutant a split stream judged | `vouch` | [0142](../decisions/0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md) |
| `pest.longFilter` | `files`, `set` or `until-killer`: what a mutant's own run and its control select where its covering tests' filter cannot be passed on a command line. `files` runs every test of the files it loaded, and its control runs them again; `set` names exactly the covering tests in a file the patched plugin reads, and controls a kill by its killers; `until-killer` runs as `files`, its control stopping after its last killer in the run's own order | `files` | [0138](../decisions/0138-a-covering-set-too-long-for-a-filter-is-selected-exactly.md) |
| `pest.preloaded` | `fresh` or `unjudged`: what becomes of a mutant whose own Pest run had loaded its file before the mutant was in place, so its tests ran the original code. `fresh` judges it again in a fresh `pest` process over its covering tests, the mutant served before Composer's autoloader loads anything, its verdict read from Pest's JUnit log and exit status; a run that fails before any test counts only where the same run serving the file unmutated passes. `unjudged` leaves it unjudged | `fresh` | [0148](../decisions/0148-a-mutant-of-a-file-pest-loads-first-is-judged-in-a-fresh-run.md) |
| `pest.shardScan` | boolean: whether a patched shard scans the codebase for the tests that read a value once, each later chunk reading only the files it mutates that the scan did not. It changes no output | `true` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `pest.longestFirst` | boolean: whether the commands a run starts side by side, its controls, replays and trials, start the longest-limited first, so the slowest is not the last to start. It changes no output | `true` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `pest.remember` | boolean: whether a shard keeps what it read, listed, scanned and ran unmutated once for every later run of it. It changes no output | `true` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `pest.overlapSteps` | boolean: whether an invocation's unmutated controls start beside its survivors' runs again, each in a share of the pool's places proportional to its runs, so neither waits for the other's slowest run. It changes no output | `true` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `pest.pcovOff` | boolean: whether a mutation run and every process it starts get `PCOV_ENABLED=0`, unless its streams record lines with pcov. It changes no output | `true` | [0147](../decisions/0147-a-stream-records-its-own-lines-and-holds-a-child-to-its-parent-s-steps.md) |
| `pest.xdebugOff` | boolean: whether a mutation run and every process it starts get `XDEBUG_MODE=off`. It changes no output | `true` | [0147](../decisions/0147-a-stream-records-its-own-lines-and-holds-a-child-to-its-parent-s-steps.md) |
| `splitStream.enabled` | boolean: under `pest.patch` and `pest.schemata`, a shard judges each mutant its file's schema switches in split streams: Pest runs of whole test files, every schema served, each forking at a mutant's site the first time a test reaches it, the child finishing that test with the mutant on and the parent going on without it. A test the child fails, errors or ends in, and the parent passes, kills the mutant; a streamed verdict runs no control. A test holding a regular file open for writing (a temporary file or a file-backed SQLite database among them), a pipe open for writing or a socket the stream did not hold as it started, or running in a separate process, forks nothing, since a child shares each descriptor and its offset with the parent, and a mutant it covers that no stream killed runs its own run. Without `pcntl` and `posix`, every mutant runs its own run | `false` | [0117](../decisions/0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md) |
| `splitStream.children` | integer, at least 1: the most children a stream holds at once | `1` | [0117](../decisions/0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md) |
| `splitStream.changeCheck` | boolean: a stream forks a mutant whose original and replacement are expressions of literals, constants, variables and operators only where it changes the value, and a mutant no test changed survives without a fork | `true` | [0117](../decisions/0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md) |
| `splitStream.killersFirst` | boolean: a stream forks each mutant first at the test the ledger last saw kill it, and only what that test did not kill at every covering test, in a second pass | `true` | [0117](../decisions/0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md) |
| `splitStream.killerStreams` | boolean: under `splitStream.killersFirst`, a first-pass stream runs, in the suite's order, only the tests the ledger names as a killer of its mutants, selected by their ids where PHPUnit is 13.2.0 or later; every mutant it did not kill streams its covering test files in the second pass. It does nothing for a mutant that runs its own run, nor where no mutant of the part has a killer in the ledger | `false` | [0136](../decisions/0136-a-first-pass-stream-may-run-only-the-tests-that-killed-before.md) |
| `splitStream.bootOnce` | **`true`** or `false`: a first-pass stream that records no lines boots a Laravel 11+ application once, through `APP_BASE_PATH`, and runs each test in a process forked from it, which forks its mutants' children; a mutant whose site runs during the boot streams in the second pass with a boot in every test, and a test the stream cannot fork in boots its own | `false` | [0145](../decisions/0145-a-stream-may-fork-each-test-from-an-application-booted-once.md) |
| `splitStream.reconnect` | **`true`** or `false`: a test that holds sockets or a file-backed SQLite database the stream did not hold as it started forks where each is a Laravel connection of its own, none in a transaction, none set to read and write apart, and none whose session holds a temporary table, a variable, a lock, a listened channel, a prepared statement or a held cursor, or settings a fresh connection lacks, as each dialect reads them. At each fork the parent checks that, closes each connection, which reconnects as it is next used, copies each MySQL or MariaDB database (one with a view, trigger, routine or event falls back), PostgreSQL database (as a template) or SQLite file, and the child reconnects to its copies before its mutant runs; the parent drops each copy once the child ended. A test any of that fails for falls back | `false` | [0147](../decisions/0147-a-stream-records-its-own-lines-and-holds-a-child-to-its-parent-s-steps.md) |
| `splitStream.early` | `no-match`, `always`, `unproven-only` or `never`: which full split-stream runs, sharded by tests and handed no map, plan without a fresh one. `no-match` starts one under `unproven`, or where no proof of the ledgers it reads may share its first five key items; `always` every one, taking no proof; `unproven-only` only under `unproven`; `never` none. Such a run reads the map kept beside the default branch's ledger for its tests' times alone, takes no proof, streams every test file of each part, records the lines each test executes with pcov, judges each mutant by the tests that executed it, and its verdict keys by the map its streams recorded. It is in no key | `no-match` | [0135](../decisions/0135-a-full-split-stream-run-starts-without-waiting-for-a-fresh-map.md) |
| `splitStream.sharding` | `tests`: a run of n shards cuts the test files into n parts of about the same time by the coverage map's times, every shard of a package taking every unit of it and streaming its own part; the verdict merges each mutant across the parts: a kill in any part wins, then a timeout; a mutant survives only where every part that reached it let it survive; a mutant no stream can ever judge runs one own run, by every covering test, in the one shard whose part holds its first covering test; a mutant a stream falls back on in a part runs an own run in that part's shard by that part's covering tests alone, each part that falls back running its own; `source`: each shard takes its own part of the source and streams the test files that cover it | `tests` | [0117](../decisions/0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md) |
| `controls.kills` | boolean (**`false` loosens**): whether every kill runs its control on the unmutated code, a kill a test is named for by its killers and one no test is named for by its covering tests. Off, a kill whose killers fail on the unmutated code too counts. It acts on no mutant a split stream judged | `true` | [0142](../decisions/0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md) |
| `controls.memory` | boolean: whether an out-of-memory mutant's tests run unmutated under the same cap before it counts | `true` | [0142](../decisions/0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md) |
| `mutants.duplicates` | `judge-once` or `judge-each`: whether mutants that are one program are judged once. Under `judge-once` the first of mutants of one file that leave it alike, or that a rule the gate ships makes one program (a removed negation and the same condition negated again), is judged, and every other takes its judgement in no time; each keeps its id and place, and counts in the score. Infection runs every mutant whatever it says | `judge-once` | [0137](../decisions/0137-a-mutant-that-is-the-same-program-as-another-is-judged-once.md) |
| `staticCheck.tool` | adapter: `mago`, `phpstan`, `psalm`; or `auto`, the first installed and configured, or `none` | `auto` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `staticCheck.config` | path | the analyser's own | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `staticCheck.seconds` | integer | `60` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `staticCheck.before` | boolean: also check a mutant before its tests where its mutator's rejection rate times its tests' time outweighs one check | `true`; `false` under `splitStream.enabled` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md), [0077](../decisions/0077-a-mutant-is-checked-before-its-tests-by-default.md), [0117](../decisions/0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md) |
| `staticCheck.testsTime` | `learned`: weigh a check before a mutant's tests against its covering tests' own time × the median pace the ledger's kills took, or their own time while it holds no kill; `covering`: against every covering test's own time | `learned` | [0107](../decisions/0107-a-pre-check-is-weighed-against-what-a-kill-is-learned-to-cost.md) |
| `staticCheck.dependents` | How many dependents of a changed file an analyser reads at most, the first in path order | `25` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `staticCheck.preCheckAfter` | How many checks of a mutator come before its rejection rate decides whether it is checked before its tests | `50` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `staticCheck.after` | boolean: whether a survivor the analyser rejects after its tests counts as killed by static analysis | `true` | [0142](../decisions/0142-a-check-that-vouches-for-a-kill-can-be-turned-off-and-a-run-notes-each-loose-value.md) |
| `staticCheck.php` | object of ini settings each PHPStan process runs under, so PHPStan does not restart it. Where `opcache.file_cache_only` is `1` and no `opcache.file_cache` is named, the cache is kept under the gate's workspace; where `xdebug.mode` is named, PHPStan's `XDEBUG_MODE` is set to it too, since the variable overrides the setting. It changes no finding; `doctor` reports a PHPStan that still restarts | `{"pcov.enabled": "0", "xdebug.mode": "off", "opcache.enable_cli": "1", "opcache.file_cache_only": "1", "opcache.validate_timestamps": "0"}` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `staticCheck.turbo` | boolean: whether PHPStan's processes load its turbo extension, where PHPStan ships a build of it for this PHP and machine and it is not loaded already. It changes no finding | `true` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `staticCheck.opcache.batch` | integer, at least 1: the programs one child compiles when opcache proves mutants equivalent, few enough that opcache's memory never fills | `100` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `staticCheck.opcache.limit` | duration: how long one child may take to compile its programs; one that takes longer proves none | `1m` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `pruning.enabled` | boolean: whether a run with a base leaves out, on a unit whose code is as its newest full result recorded it, the mutators that let no mutant through lately, carrying their last results | `true` | [0025](../decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| `pruning.window` | integer, at least 1: how many of a mutator's newest judged mutants must all be killed before it is pruned | `500` | [0025](../decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| `pruning.audit` | duration: how old a unit's newest full result may be for a pruned mutator's results to be carried from it | `7d` | [0025](../decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| `sampling.confidence` | `90`, `95` or `99`: how sure a sampled run's interval of each tree is to hold its score | `95` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `sampling.margin` | number, above 0 and at most 10: the half-width in points each tree's interval is sized for | `2.0` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `sampling.minUnits` | integer, at least 1: the fewest units of a tree's sampled stratum a sample runs, or every one where it holds fewer | `30` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `sampling.seed` | string: what a sample is drawn from | the change's base commit in a change-scoped run, and the plan's commit otherwise | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `sampling.auto` | `off`, an integer of at least 1, or a duration: a run of a kind `sampling.autoRuns` lists samples by itself where the units it runs beyond its new code, outside a tree held to 100, are expected to hold more mutants than the count, or to take more runner time than the duration ([sampling by itself](#sampling-by-itself)) | `off` | [0086](../decisions/0086-a-pull-request-can-sample-by-itself-above-a-threshold.md) |
| `sampling.autoRuns` | list of `pullRequests`, `mergeGroups` and `branches`, a push to a branch that is not the default: the kinds of run that may sample by themselves; the default branch, a tag and an `--unproven` run never do | `["pullRequests"]` | [0086](../decisions/0086-a-pull-request-can-sample-by-itself-above-a-threshold.md) |
| `mutators.sets` | list of mutator set names, never `default`, which is always on | `[]`, plus the preset's | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `mutators.except` | list of mutator names, `<set>/<Name>`, each held by a set in `mutators.sets` or by the default set; under Pest or Infection, by a set in `mutators.sets` | `[]` | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `triage.fewest` | integer, at least 2: the fewest runs `triage --repeat` takes | `2` | [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| `local.watchBudget` | duration | `1m` | [0010](../decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| `local.prePushBudget` | duration | `5m` | [0010](../decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| `local.watchPoll` | duration: how long `watch` waits between two looks at the files it polls; while a round runs, it looks for a change before a batch only once this long has passed since its last look | `2s` | [0010](../decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md), [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md) |
| `local.serveIdle` | duration: how long a daemon of `serve` waits for a request before it stops | `30m` | [0078](../decisions/0078-a-daemon-keeps-the-gate-loaded-between-local-runs.md) |
| `local.serveRequest` | How long a client may take to send a daemon its request | `5s` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `local.serveStart` | `watch`: `watch` starts a daemon where none runs; `never`: no command starts one, and each uses one only where it runs | `watch` | [0078](../decisions/0078-a-daemon-keeps-the-gate-loaded-between-local-runs.md) |
| `local.serveStartWait` | duration: how long `watch` waits for a daemon it starts to listen before it runs the flow itself | `10s` | [0078](../decisions/0078-a-daemon-keeps-the-gate-loaded-between-local-runs.md) |
| `local.quick` | boolean: each round of `watch` after the first takes the coverage of the files a save changed from the last round's map and the static graph instead of measuring it again, streams its mutants in split streams of their covering test files with no static check, labels what it shows `quick` and never `passed`, and keys its proofs apart, so `pre-push`, `run` and CI never take one; `false` judges each round as `run` does | `true` | [0146](../decisions/0146-a-watch-round-is-quick-advisory-and-keyed-apart.md) |
| `local.cancelByUnit` | boolean: a save during a round of `watch` drops the queued work of the units whose files it changed, halts a batch running one and reads nothing it came to, leaving its units unjudged, and the round goes on with every other unit; `false` stops the round before its next batch | `true` | [0146](../decisions/0146-a-watch-round-is-quick-advisory-and-keyed-apart.md) |
| `local.inferSubsumed` | boolean: outside CI, run each mutant another subsumes after the others, and show one the budget left unrun as inferred killed where a mutant subsuming it was killed; never a proof, a score or a verdict | `true` | [0079](../decisions/0079-a-local-run-infers-what-a-killed-mutant-subsumes.md) |
| `local.memoryHeadroom` | How many times the suite's peak `doctor` wants a memory cap to hold | `2` | [0112](../decisions/0112-every-number-a-run-goes-by-is-a-setting.md) |
| `local.watchStatus` | boolean: `watch` looks through `git status` and hashes only the files that differ from the commit or differed at its last look; `false` hashes every file at every look | `true` | [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md) |
| `local.cpu` | `balanced` (half the logical cores, at least 1 and at most 8, at utility priority), `fast` (every core but one, at normal priority) or `quiet` (one worker at background priority): how much of the machine `watch` and `serve` take | `balanced` | [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md) |
| `local.workers` | integer, at least 1, `half` or `all-but-one`: how many workers a `watch` round runs, for its coverage run, its pools, Pest's mutants at once and the opcache equivalence check's children | `local.cpu`'s | [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md) |
| `local.priority` | `normal`, `utility` or `background`: the priority `watch` and the daemon it starts run at | `local.cpu`'s | [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md) |
| `local.macosClass` | `none`, `utility`, `background` or `maintenance`: the QoS clamp on macOS. The daemon starts under `taskpolicy -c`; `watch` on its own takes darwin background for `background` and `maintenance`, and nice 10 for `utility` | `local.priority`'s | [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md) |
| `local.linuxNice` | integer, 0 to 19: the niceness on Linux, which a process raises and never lowers again | `local.priority`'s: 0, 10 or 19 | [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md) |
| `local.windowsClass` | `normal`, `below-normal` or `idle`: the priority class `watch` runs in on Windows | `local.priority`'s | [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md) |
| `local.onBattery` | `reduce` (one worker at background priority), `pause` (no round until the machine runs on power) or `run` (as on power): what `watch` does on battery or in Low Power Mode, read from `pmset`, `/sys/class/power_supply` or PowerShell at the start of each round, and where `outbreak serve` tells of the power source's changes (notifyd on macOS, the kernel's device events on Linux) read again only once it changed; `pre-push` and `run` are never held | `reduce` | [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md), [0133](../decisions/0133-outbreak-serve-holds-the-map-the-ledgers-and-the-coverage-in-memory.md) |
| `local.childWait` | duration: how long a warm worker and its client wait for a wake in the local loop before they look anyway | `1s` | [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md) |
| `local.coverageAlone` | boolean: a coverage run of one test file runs in Pest's own process | `true` | [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md) |
| `local.sizedPools` | boolean: a set of warm workers boots no more workers than it has runs | `true` | [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md) |
| `local.listOnce` | boolean: a `watch` round lists the tests only where a file in a test directory was added, changed or removed since the listing it reuses | `true` | [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md) |
| `local.streamBatches` | boolean: `watch` prints what each batch judged as it ends, before the round's verdict | `true` | [0120](../decisions/0120-the-local-loop-takes-a-share-of-the-machine-and-looks-only-at-what-changed.md) |
| `local.recordLines` | boolean: a quick round's streams record the lines each test their parent runs whole executes, with `outbreak.so` where `outbreak.lines` lets them and with pcov otherwise, and the kept map takes the recorded lines in place of that test's entries after the round; a test that ran in a process of its own, or that a stream handed to a forked process, keeps its entries | `true` | [0147](../decisions/0147-a-stream-records-its-own-lines-and-holds-a-child-to-its-parent-s-steps.md) |
| `local.siteHits` | boolean: where the round's streams record lines, a quick round does not measure a changed test file the static graph answers for: each of its tests keeps its entries and covers every line of each source file the graph says the test file reaches, until a stream records it. A test file the graph does not answer for is measured as before | `true` | [0147](../decisions/0147-a-stream-records-its-own-lines-and-holds-a-child-to-its-parent-s-steps.md) |
| `outbreak.enabled` | boolean: run Outbreak's core, the `outbreak` binary, where one fits the machine and passes its checksum, to vouch for each coverage map a shard and the verdict read, to write the maps a plan hands them, and to convert the PHP report a run left into the map `coverage --from` writes; where none does, or it declines, the gate does the work in PHP. Either way the output is the same | `true` | [0118](../decisions/0118-outbreak-s-core-vouches-for-and-slices-coverage-maps.md), [0119](../decisions/0119-outbreak-s-core-converts-a-run-s-php-report-into-the-gate-s-map.md) |
| `outbreak.binary` | path to the `outbreak` binary, from the project's root where relative, run in place of the one the package ships; no checksum is asked of it, only that it speak contract 1 | none | [0118](../decisions/0118-outbreak-s-core-vouches-for-and-slices-coverage-maps.md) |
| `outbreak.timeout` | duration: how long one run of the binary may take before it is stopped and the gate does the work in PHP | `5m` | [0118](../decisions/0118-outbreak-s-core-vouches-for-and-slices-coverage-maps.md) |
| `outbreak.threads` | integer, at least 1: how many threads the binary writes a plan's maps on | as many as the machine has cores | [0118](../decisions/0118-outbreak-s-core-vouches-for-and-slices-coverage-maps.md) |
| `outbreak.equivalence` | boolean: where `outbreak.so` ships for the platform and the tests' PHP minor and loads into that PHP, compile each mutant beside its original before any test runs, and prove it equivalent where its op arrays are its original's and the gate finds its declarations alike. A proven mutant is never run, and the verdict leaves it out of the score as *equivalent, proven*, whatever `equivalence.static` says. Affects results, so it is in a proof's key | `true` | [0130](../decisions/0130-a-mutant-that-compiles-to-its-original-is-equivalent-and-is-never-run.md) |
| `outbreak.forkStates` | boolean: where `outbreak.so` ships for the platform and the tests' PHP minor, a split stream loads it, and at each fork reads, in the parent and in the child, the state the call that reached the mutant's site leaves as it returns: what it returned and the properties of the object it ran on. The run's log counts the forks whose two states are equal. A measurement, which no verdict reads | `false` | [0130](../decisions/0130-a-mutant-that-compiles-to-its-original-is-equivalent-and-is-never-run.md) |
| `outbreak.recheckTouchedKills` | **`false`** or `true`: where `outbreak.so` ships for the platform and the tests' PHP minor, a split stream loads it, and a kill by a child whose test touched the clock, randomness, the environment, another process, the network or a file outside the project after the fork is not taken from the stream: the mutant runs its own run, with its verification. A kill by a test that touched none is never trusted more for it, since the extension cannot see every source. `false` takes every streamed kill as the stream judged it. Affects results, so it is in a proof's key | `true` | [0130](../decisions/0130-a-mutant-that-compiles-to-its-original-is-equivalent-and-is-never-run.md) |
| `outbreak.graph` | `local`, **`narrow`**, `widen` or `off`: where Outbreak's static graph of the sources and the tests acts, a graph of the files each file names, resolved by its namespace and imports. `local` adds, in `watch`'s quick rounds, the tests the graph says reach an edited file after the coverage map's covering tests. `narrow` also lets a change the map cannot place (a deleted test, no map to read, support that runs code when it is loaded or is named by anything but a test or support) reach the files the tests that reach it reach, in place of every unit of its package, wherever the graph can place every test; an edge the graph misses leaves a unit unreached until the scheduled full run. `widen` also lets a changed source file reach the files the map shows its joined tests running. `off` acts nowhere. A test the map shows running a file the graph does not join to it gives no answer. Without the binary, or with `outbreak.enabled` off, every value acts as `off`. Decides how the gate runs, so it is in no key | `local` | [0132](../decisions/0132-a-static-graph-places-what-the-coverage-map-cannot-and-the-map-stays-the-check.md) |
| `outbreak.lines` | boolean: where `outbreak.so` ships for the platform and the tests' PHP minor, a stream that records its tests' lines loads it and records with it each line an executed op of a user function carries, a superset of pcov's executed lines; where another extension took PHP's executor over after it, or off, the stream records with pcov. A run that stands in for the suite's coverage report records with pcov. Decides how the gate runs, so it is in no key | `true` | [0147](../decisions/0147-a-stream-records-its-own-lines-and-holds-a-child-to-its-parent-s-steps.md) |
| `outbreak.steps` | boolean: where `outbreak.so` ships for the platform and the tests' PHP minor, a stream's processes count the jumps their ops take and their calls of user functions, and a child is a timeout once its steps since the fork pass `timeouts.perTest` times its parent's steps from the fork to the test's end, plus `outbreak.stepFloor`; wall time then holds it to `timeouts.most` alone. Off, a child is held to its parent's time. Affects results, so it is in a proof's key | `true` | [0147](../decisions/0147-a-stream-records-its-own-lines-and-holds-a-child-to-its-parent-s-steps.md) |
| `outbreak.stepFloor` | integer, at least 1: the least steps a child may take past its parent's share under `outbreak.steps`. Affects results, so it is in a proof's key | `10000000` | [0147](../decisions/0147-a-stream-records-its-own-lines-and-holds-a-child-to-its-parent-s-steps.md) |
| `outbreak.serve.enabled` | boolean: where the binary is in use, the daemon `watch` starts is `outbreak serve`, which starts the PHP daemon (ADR-0078) as its child and lives as long as it, holding the proofs the gate keeps and the names of the static graph's files in memory under `.mutation-gate/outbreak/`, a file read for its names again only where its bytes changed; the gate asks it on `.mutation-gate/outbreak/state.sock`, and reads the files itself wherever it does not answer within `outbreak.timeout`. Off, `watch` starts the PHP daemon alone. Judges or reports only | `true` | [0133](../decisions/0133-outbreak-serve-holds-the-map-the-ledgers-and-the-coverage-in-memory.md) |
| `outbreak.serve.snapshotEvery` | duration: how often, at most, `outbreak serve` writes its state's snapshot, `state.bin`, while no client is connected; it writes one as it starts and as it stops too. Judges or reports only | `10m` | [0133](../decisions/0133-outbreak-serve-holds-the-map-the-ledgers-and-the-coverage-in-memory.md) |
| `outbreak.serve.syncEvery` | duration: how often, at most, `outbreak serve` syncs the journal of what it was asked to keep, `journal.bin`, to disk; a record whose checksum fails ends the journal there when it is read back. Judges or reports only | `1s` | [0133](../decisions/0133-outbreak-serve-holds-the-map-the-ledgers-and-the-coverage-in-memory.md) |
| `outbreak.serve.debounce` | duration: how long the changes `outbreak serve` watches through the platform's notifications (inotify on Linux, kqueue on macOS and the BSDs) settle before `watch` looks at the files and starts a round; where the watcher cannot arm or a look finds a change it did not report, `watch` says so once and waits `local.watchPoll` between looks. Judges or reports only | `50ms` | [0133](../decisions/0133-outbreak-serve-holds-the-map-the-ledgers-and-the-coverage-in-memory.md) |
| `outbreak.cache.store` | `auto`, `none`, `actions` or `oci`: where `outbreak cache` keeps the proofs a verdict accepted by their keys, packed by base, for any run whose key matches to take after its ledgers. `auto` is `oci` where `outbreak.cache.registry` names a repository, `actions` under the package's action, and `none` elsewhere | `auto` | [0131](../decisions/0131-proofs-are-shared-across-scopes-by-their-keys.md) |
| `outbreak.cache.registry` | an OCI repository as a plain `host[:port]/path`, such as `ghcr.io/owner/proofs`, that holds the packs under `oci`. Packs move only where the workflow names the same repository in the `MUTATION_GATE_REGISTRY` variable: `fetch` pulls each class's newest pack before the plan, with the job's token on any run but a pull request's, and `deliver` pushes a verdict's entries. The plan, which runs the project's code, reaches no registry and holds no token | none | [0131](../decisions/0131-proofs-are-shared-across-scopes-by-their-keys.md) |
| `outbreak.cache.trust` | `default-branch` or **`repository`**: which packs the default branch and a merge group read. `default-branch` reads only what the default branch's own runs wrote; `repository` also reads what any branch of the repository wrote, a proof another branch's code computed | `default-branch` | [0131](../decisions/0131-proofs-are-shared-across-scopes-by-their-keys.md) |
| `contexts` | object of blocks, `local`, `ci`, `github`, `gitlab`, `bitbucket`, `azure`, `buildkite`, `jenkins`, `circleci`, `pr` and `main`, each holding any key of this table but `preset`, `extensions`, `ci.plan` and `ci.defaultBranch`: what a run in that context lays over the rest of its layer. A run outside CI lays `local`; a run in CI lays `ci`, then the block of the CI its plan is for, then `pr` on a pull request or a merge group, or `main` on the default branch | `{}` | [0071](../decisions/0071-a-config-holds-a-block-for-each-context-a-run-is-in.md), [0104](../decisions/0104-suggestions-can-be-turned-off-and-each-ci-has-a-context.md) |
| `paths` | list of `{glob, mutators, timeouts, staticCheck}`: what each entry sets for the files its glob matches, every match laid over the settings least specific first, by the length of the glob's literal prefix, then as written | `[]` | [0074](../decisions/0074-paths-set-mutators-timeouts-and-the-static-check-by-glob.md) |
| `paths[].glob` | glob, matched as `trees[].exclude` is | none; required | [0074](../decisions/0074-paths-set-mutators-timeouts-and-the-static-check-by-glob.md) |
| `paths[].mutators.sets` | as `mutators.sets`, added to it for the files the entry matches | `[]` | [0074](../decisions/0074-paths-set-mutators-timeouts-and-the-static-check-by-glob.md) |
| `paths[].mutators.except` | as `mutators.except`, added to it; under Pest or Infection, only of a set `mutators.sets` turns on, as their own mutants stand in for the default set's | `[]` | [0074](../decisions/0074-paths-set-mutators-timeouts-and-the-static-check-by-glob.md) |
| `paths[].timeouts.seconds` | as `timeouts.seconds`, for the files the entry matches | `timeouts.seconds` | [0074](../decisions/0074-paths-set-mutators-timeouts-and-the-static-check-by-glob.md) |
| `paths[].timeouts.most` | as `timeouts.most`, at least the entry's `timeouts.seconds` | `timeouts.most` | [0074](../decisions/0074-paths-set-mutators-timeouts-and-the-static-check-by-glob.md) |
| `paths[].staticCheck.tool` | as `staticCheck.tool`, `none` included | `staticCheck.tool` | [0074](../decisions/0074-paths-set-mutators-timeouts-and-the-static-check-by-glob.md) |
| `paths[].staticCheck.before` | as `staticCheck.before` | `staticCheck.before` | [0074](../decisions/0074-paths-set-mutators-timeouts-and-the-static-check-by-glob.md) |

## Sampling by itself

With `sampling.auto` set, a pull request that reaches more than the threshold
beyond its own lines runs a sample in place of every unit it reaches, as
`--sample` would, and its plan says why:

```json
{
    "sampling": {
        "auto": "1h",
        "autoRuns": ["pullRequests"]
    }
}
```

The units holding a line of the change, and every tree held to 100, still run
in full, and the threshold counts neither. Everything else the run would run
is sampled, sized by `sampling.margin` and `sampling.minUnits`, and each tree
is judged on its interval.

- **Gains:** a change to a file that reaches everything, such as the lock or
  the test bootstrap, costs a sample's runner time and wall time, not a full
  run's.
- **Costs:** a pull request can merge on a sampled pass, so a survivor in a
  unit the sample left out is caught only by the next run that runs that unit
  in full: the default branch's run after the merge, or a scheduled or
  `--unproven` run. A sampled pass is never `last-passed` and raises no floor.
- **Keep it off** where every merge must be proved whole. Merge groups and
  branches are listed by name only: a merge group's pass merges at once.

## Context blocks

`contexts` holds what a run lays over the rest of the config in each context
it is in
([ADR-0071](../decisions/0071-a-config-holds-a-block-for-each-context-a-run-is-in.md)):

```json
{
    "budget": "30m",
    "contexts": {
        "local": { "budget": "2m" },
        "pr": { "budget": "10m", "shards": { "max": 4 } },
        "main": { "shards": { "max": 16 } }
    }
}
```

- **`local`**: outside CI, and in watch, pre-commit and pre-push wherever
  they run.
- **`ci`**: in CI.
- **`pr`**: in CI, on a pull request or a merge group, laid over `ci`.
- **`main`**: in CI, on the default branch, laid over `ci`.

`--context=<local|ci|pr|main>` puts a run in a context, and in `ci` beneath
`pr` and `main`. A block holds any key but `preset`, `extensions`, `ci.plan`
and `ci.defaultBranch`, which are read before the context is known. Each
layer, a preset's too, lays its blocks over its own rest before the layers
are laid, so the config file's own setting beats a preset's block.
`config:show` prints the blocks as written, and `config:show --resolved`
prints the config of a run in its contexts, which it names on standard
error.

## Path rules

`paths` sets mutators, timeouts and the static check for the files a glob
matches
([ADR-0074](../decisions/0074-paths-set-mutators-timeouts-and-the-static-check-by-glob.md)):

```json
{
    "paths": [
        { "glob": "src/Legacy/**", "timeouts": { "seconds": 60, "most": 600 } },
        { "glob": "src/Generated/**", "mutators": { "except": ["default/Plus"] }, "staticCheck": { "tool": "none" } }
    ]
}
```

Every entry that matches a file is laid over the settings, least specific
first, so the innermost entry wins for what it sets and a broader one keeps
the rest. A unit's proof key holds what its entries set that affects
results, so an edit to one entry re-runs only the units it matches. Under
`runner.pathRules: partitioned`, a shard runs the units no entry matches as
it always does, then each other group with a runner its settings build:
one runner boot more per group, and only where `paths` is used. Mutators
an entry turns off are the gate's own: under Pest or Infection, which make
their own mutants, an entry cannot turn off one only the default set holds.

## In a package's `composer.json`

In a `composer.json`, under `extra.mutation-gate`:

| Key | Type | Default | Decided in |
|-----|------|---------|------------|
| `extensions` | list of class names | `[]` | [0001](../decisions/0001-a-framework-free-core-behind-nine-ports.md) |
| `floor` | number, 0 to 100 | none | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `floorReason` | string | none; required when `floor` is 0 | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `newCodeFloor` | number, 0 to 100 | `newCode.floor` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `securityFloor` | number, 0 to 100, in a package's `composer.json` only | `security.floor` | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |

## In the reusable workflow's call

The reusable workflow takes the action's inputs, which the
[GitHub Actions guide](../guide/ci/github-actions.md) lists, less `shard`,
`deliver` and `composer-auth`, whose credentials it takes as the secret
`COMPOSER_AUTH`, and three inputs of its own:

| Input | Type | Default | Decided in |
|-------|------|---------|------------|
| `coverage` | the name of an artifact of the run that holds `map.json.gz` | none: the plan runs the suite under coverage | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `runs-on` | the runner the plan, the shards, the survivors and the verdict run on: a label, or a JSON list of labels a runner must all have | `ubuntu-latest` | [0035](../decisions/0035-the-reusable-workflow-runs-on-the-runner-its-caller-names.md) |
| `keyed-runs-on` | the runner `fetch`, the `deliver` jobs and `publish` run on, which hold a key or a token that writes: a label, or a JSON list of labels | `ubuntu-latest` | [0039](../decisions/0039-the-jobs-that-hold-a-credential-run-on-a-runner-of-their-own.md) |

The one-step action runs on the runner of the job that uses it, so that job's
`runs-on` sets it.
