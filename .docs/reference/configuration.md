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
are written `90s`, `15m`, `1h30m` or `7d`, and dates `YYYY-MM-DD`. A key that
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
| `reach.lockPackages` | list of Composer package names, `vendor/name`, where `*` stands for any run of characters within either part: a change to a `composer.lock` decides how the gate runs only where it moved one of these or a package this repository ships, or a key besides the package lists; a change to a `composer.json` only where it moved such a package's line in `require`, `require-dev`, `conflict`, `replace` or `provide`, or a key besides those and the keys that only describe the package or name its scripts | none: every package decides | [0030](../decisions/0030-a-lock-file-decides-by-the-packages-it-moved.md), [0056](../decisions/0056-a-manifest-decides-how-the-gate-runs-only-by-the-keys-that-install-and-load.md) |
| `reach.changedTests` | `narrow`: a changed test file reaches what the tests it changed run, by the coverage map's ids of them, and what `file` reaches where the change also moved code they share, such as a hook, a helper, a data provider, an import it removed or changed, or an import it added of a name the file already spelt; or `file`: what all its tests run, and what the runner judges by the file | `narrow` | [0065](../decisions/0065-a-changed-test-file-reaches-what-its-changed-tests-run.md), [0068](../decisions/0068-an-added-import-reaches-nothing-and-support-is-wherever-the-tests-load-it.md), [0069](../decisions/0069-an-added-import-of-a-name-the-file-spelt-widens.md) |
| `reach.fixtures` | list of globs, from the repository's root, each matching the directory of a fixture project the tests read: a change to a file in one reaches only what the tests that name its path, or a class or function its PHP declares, run, directly or through test support | `[]` | [0064](../decisions/0064-a-fixture-project-reaches-the-tests-that-name-its-path.md), [0066](../decisions/0066-a-fixture-project-is-used-by-the-tests-that-name-what-it-declares.md) |
| `holds.hotPath` | number, 0 to 1 | `0.8` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
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
| `costs.secondsPerLine` | map of path prefix to number | `{"": 0.2}` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `costs.perRunnerMinute` | `{amount, currency}` | none | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `costs.savingsWindow` | duration: how far back the runner time the gate saved is counted, which the step summary, `savings.json` and `summary.json` show | `30d` | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md), [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `costs.model` | adapter: `learned`, or a cost model an extension registers: what the plan expects each unit's work to cost | `learned` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.plan` | adapter: `github`, `gitlab`, `buildkite`, `circleci`, `azure`, `bitbucket`, `jenkins`, `json` | detected from the environment | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `ci.defaultBranch` | branch name | the CI's answer, else git's `origin/HEAD`, else `main` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.check` | the check-run name the verdict reports under | `mutation / verdict` | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `ci.trustMergedPullRequests` | boolean: whether the default branch takes a merged pull request's own recorded pass as proof of its tree and spares it a re-check, and so a commit on which a merge group's verdict passed; and whether a merge group whose commit holds its pull request's head tree takes that pull request's recorded pass and reaches nothing. It works only where pull request runs write their own scope's ledger, an untrusted write, so turning it on trusts the pull request's own computed result | `false` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md), [0049](../decisions/0049-an-owner-is-mentioned-once-and-a-merge-group-trusts-its-pull-request-as-a-merged-one.md) |
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
| `proofs.compression` | `auto`, `gzip` or `zstd`: how a ledger's file is compressed. `auto` writes zstd where PHP's zstd extension (0.13 or later) is loaded or the `zstd` program is on the path, and gzip where neither is; `zstd` leaves the ledger unwritten where neither is, and says why. A reader tells the two by the file's first bytes, whatever wrote it, and one with no zstd says what to install | `auto` | [0085](../decisions/0085-the-ledger-is-compact-and-compressed-with-zstd-where-it-can-be.md) |
| `proofs.compressionLevel` | integer, 1 to 19: the level a ledger is packed at; gzip packs at 9 at most | `9` | [0085](../decisions/0085-the-ledger-is-compact-and-compressed-with-zstd-where-it-can-be.md) |
| `coverage.incremental` | boolean: whether a run measures again only the test files whose coverage could have moved, keeping the rest from the map its own scope keeps, or else the default branch's | `true` | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `budget` | duration | none | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.mode` | `confirm` or `unjudged` | `confirm` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.seconds` | integer, or `"learned"`: three times the start-up each shard measures, at least 1 s, and 10 s in a shard that measures none; an integer is the least a mutant's run is allowed, and what one whose covering tests were not all timed is; each mutant otherwise gets three times the start-up its shard's run measured (5 s where it measured none) plus `timeouts.multiple` times its covering tests' own time, within the two bounds. Under `pest.patch`, `infection:patch` and with the PHPUnit, PhpSpec, Codeception and Testo runners, a mutant's run is also stopped where no test finishes for that rule of its slowest covering test's time. Infection does so under `infection:patch`; unpatched, it keeps its own limit under `timeouts.most`, with no floor, and every run warns of it | `10`; `30` in the `laravel` and `symfony` presets | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.most` | integer, at least `timeouts.seconds`: the most a mutant's run is allowed; unpatched Infection skips a mutant whose covering tests take as long | `300` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.multiple` | number of at least 1, or `"learned"`: how many times its covering tests' own time each mutant is allowed. Learned, it is what 99 in 100 of the last 2,000 kills the ledger holds took over their covering tests' own time, kept between 2 and 10, and 3 until a kill is learned. The plan prints what it learned | `3` | [0073](../decisions/0073-a-limit-can-be-learned-from-what-earlier-runs-measured.md) |
| `timeouts.startUp` | duration: what a mutant's limit allows a run to start in where nothing measured its start-up; where one was measured, the limit is laid on three times it | `5s` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.tighter.mutators` | list of mutators by their short names, the last part of the name a runner gives a mutator, after its last `\` or `/`: `RemoveArrayItem` matches Pest's `Pest\Mutate\Mutators\Removal\RemoveArrayItem` and the default set's `default/RemoveArrayItem`. A mutant of a listed mutator has its silence limit kept above `timeouts.tighter.floor` instead of `timeouts.seconds` | `RemoveArrayItem`, `DecrementInteger`, `IncrementInteger`, `ForeachEmptyIterable`, `UnwrapArrayValues`, `InstanceOfToTrue`, `InstanceOfToFalse`, `TernaryNegated`, and Infection's `ArrayItemRemoval`, `Foreach_`, `InstanceOf_` and `Ternary` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.tighter.floor` | integer, at least 1: the floor of the silence limit of a mutant of a mutator `timeouts.tighter.mutators` lists, where it is under `timeouts.seconds`; its whole-run limit keeps `timeouts.seconds` | `7` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.perTest` | integer, at least 1, or `false`: each test of a mutant's run, and of a timed-out mutant's unmutated control, is stopped once it has run this many times its own time, as the coverage map timed it, or `timeouts.testFloor` where that is longer; the mutant is a timeout whose reason names the test, and a control stopped so leaves its timeout too slow to judge. A test the map did not time is held only to the run's silence limit and whole limit. Applies under `pest.patch` and with the PHPUnit, PhpSpec, Codeception and Testo runners. `false` holds no test to a limit of its own | `3` | [0090](../decisions/0090-each-test-of-a-mutants-run-is-held-to-a-multiple-of-its-own-time.md) |
| `timeouts.testFloor` | integer, at least 1: the least seconds a test is allowed under `timeouts.perTest` | `3` | [0090](../decisions/0090-each-test-of-a-mutants-run-is-held-to-a-multiple-of-its-own-time.md) |
| `flaky.confirmSurvivors` | boolean: run each survivor once more, alone, and report one killed then as flaky. A suite whose CI runs each test file alone may turn it off | `true` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md), [0034](../decisions/0034-a-suite-whose-test-files-pass-alone-need-not-confirm-survivors.md) |
| `tests.order` | `killers-first` or `runner` | `killers-first` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `tests.suites` | list of `<testsuite>` names of the PHPUnit config, at least one: the suites whose tests judge | every suite | [0002](../decisions/0002-one-typed-config-from-several-formats.md) |
| `tests.holding` | list of `<testsuite>` names, at least one and none in `tests.suites`: the suites whose tests judge only the units they hold with `#[Holds]` or a `holds:` group, adding to their judges | none | [0002](../decisions/0002-one-typed-config-from-several-formats.md), [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `survivorsFirst.max` | whole number, at least 0: how many of the last run's survivors a pull request's run re-checks before its shards, those on changed lines first; `0` re-checks none | `20` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `ignores.entries` | list of `{mutant, reason, expires}` or `{path, mutator, reason, expires}` | `[]` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `ignores.maxDays` | integer | none | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `ignores.native` | `refuse` or `allow` | `refuse` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `ignores.notice` | duration in days: how far ahead an ignore's end is named, in the comment and in `doctor` | `14` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `equivalence.static` | boolean | `true` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `reports` | list of `{use, path, with}`; built-in `json`, `junit`, `sarif`, `html`, `stryker`, `digest`, `tests`, `kill-matrix`, `gitlab`, `sonar`; `badge`, whose `path` is `--publish-dir` where it names none; and without a `path` `console`, `problems`, `slack`, `discord`, `webhook`, `otlp`, `github-annotations`, `github-summary`, `github-comment`, `gitlab-comment`, `bitbucket-comment`, `bitbucket-insights`. A config names every `path` inside the project. `with` holds a built-in reporter's options: `problems` `{only: changed}`; `github-comment` `{identity: <account>}`; `badge` `{commit: <sha>}`, the commit the trend records, which is otherwise `GITHUB_SHA`, `CI_COMMIT_SHA`, `BUILDKITE_COMMIT` or `CIRCLE_SHA1`, and `{colors, trendKept}`, which take `badge.colors` and `badge.trendKept` where it sets none; `html` `{files: 8, survivors: 25}`, how many of the lowest-scoring files and of the survivors the page lists; `slack`, `discord` and `webhook` `{urlEnv: <variable>}`, the variable holding the URL, and never the URL itself, and `{timeout: 10s, longestWait: 30s}`, how long one post may take and the longest `Retry-After` it waits out; `slack` and `discord` `{survivors: 5, lineLength: 120}`, how many survivors the message lists and how long each line is; `webhook` `{secretEnv: <variable>}`; `otlp` `{endpoint: <https URL>}`; `gitlab-comment` and `bitbucket-comment` `{tokenEnv: <variable>}`, the variable their token is read from, `MUTATION_GATE_GITLAB_TOKEN` and `MUTATION_GATE_BITBUCKET_TOKEN` by default, and they refuse a `token` written in the config ([reports](reports.md)) | `[]` | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md), [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `suggest.enabled` | boolean: whether the gate suggests changes: what `config:suggest` proposes, which otherwise says suggestions are off; what stopping a doomed pull request's run would have saved; and the files most of the suite runs through that nothing holds. Turn it off for one CI with that CI's `contexts` block | `true` | [0104](../decisions/0104-suggestions-can-be-turned-off-and-each-ci-has-a-context.md) |
| `suggest.idle.judged` | integer, at least 1: how many of a mutator's mutants the ledger's tally must hold judged, killed or survived, before `config:suggest` calls it idle and proposes turning it off | `500` | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| `suggest.idle.survivors` | integer, at least 0: the most survivors an idle mutator's tally holds | `0` | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| `suggest.idle.share` | number, 0 to 100: the least percent of every mutator's runner time an idle mutator's mutants took | `5` | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| `suggest.ignored.share` | number, 0 to 100: the least percent of a mutator's mutants an ignore, a runner's marker or proof of equivalence left out before `config:suggest` calls it noisy and proposes turning it off | `80` | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| `suggest.ignored.mutants` | integer, at least 1: how many mutants a mutator's tally must hold before it can be noisy | `50` | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| `suggest.timeouts.cluster` | integer, at least 1: how many mutants of one directory, in the newest proof of each unit, must have run out of time before `config:suggest` proposes a `paths` entry that doubles its files' `timeouts.seconds` | `3` | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| `heatmap.files` | integer, at least 0: how many of the lowest-scoring files `summary.json` lists, which an organisation's dashboard shows under each repository | `10` | [0100](../decisions/0100-a-heatmap-scores-every-file-and-function-against-the-base.md) |
| `heatmap.methods` | boolean: whether the heatmap scores each function and method of a file, and each proof names the functions that hold its mutants | `true` | [0100](../decisions/0100-a-heatmap-scores-every-file-and-function-against-the-base.md) |
| `digest.tests` | integer, at least 0: how many of a mutant's covering tests `digest --detail=<id>` names, which `--tests=<n>` overrides | `5` | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `comment.sections` | list of `verdict`, `security`, `trees`, `sets`, `heatmap`, `survivors`, `unjudged`, `ignored`, `problems`, `notes`, `floors`, `report` and `costs`, each once, `verdict` first: the sections every `*-comment` reporter's comment shows, in this order; a run that cannot judge says why whatever it lists. A reporter's `with.comment` sets any `comment` key for its comment alone | every section, in that order | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.open` | list of sections: those shown open; every other section is folded, except on Bitbucket, which cannot fold | `[verdict, trees, heatmap]` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.states` | list of `planned`, `rechecked` and `verdict`: the states of a run the comment is written in | `[planned, rechecked, verdict]` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
| `comment.on` | list of `pull-request`, which takes a GitLab merge request too, and `default-branch`: the kinds of run that comment | `[pull-request]` | [0102](../decisions/0102-one-comment-section-shapes-every-pull-request-comment.md) |
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
| `staticCheck.tool` | adapter: `mago`, `phpstan`, `psalm`; or `auto`, the first installed and configured, or `none` | `auto` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `staticCheck.config` | path | the analyser's own | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `staticCheck.seconds` | integer | `60` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `staticCheck.before` | boolean: also check a mutant before its tests where its mutator's rejection rate times its tests' time outweighs one check | `true` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md), [0077](../decisions/0077-a-mutant-is-checked-before-its-tests-by-default.md) |
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
| `local.watchPoll` | duration: how long `watch` waits between two looks at the files it polls | `1s` | [0010](../decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| `local.serveIdle` | duration: how long a daemon of `serve` waits for a request before it stops | `30m` | [0078](../decisions/0078-a-daemon-keeps-the-gate-loaded-between-local-runs.md) |
| `local.serveStart` | `watch`: `watch` starts a daemon where none runs; `never`: no command starts one, and each uses one only where it runs | `watch` | [0078](../decisions/0078-a-daemon-keeps-the-gate-loaded-between-local-runs.md) |
| `local.serveStartWait` | duration: how long `watch` waits for a daemon it starts to listen before it runs the flow itself | `10s` | [0078](../decisions/0078-a-daemon-keeps-the-gate-loaded-between-local-runs.md) |
| `local.inferSubsumed` | boolean: outside CI, run each mutant another subsumes after the others, and show one the budget left unrun as inferred killed where a mutant subsuming it was killed; never a proof, a score or a verdict | `true` | [0079](../decisions/0079-a-local-run-infers-what-a-killed-mutant-subsumes.md) |
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
