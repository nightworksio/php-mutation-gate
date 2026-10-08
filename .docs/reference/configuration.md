# Configuration

A config file is optional: with none, the gate takes its trees from
`phpunit.xml`, its runner from what is installed and its preset from
`composer.json`. `vendor/bin/mutation-gate init` writes one for you, and
`vendor/bin/mutation-gate config:show` prints the config a run would use.

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
are written `90s`, `15m` or `1h30m`, and dates `YYYY-MM-DD`. A key that
chooses an adapter takes a registered name or `{"use": <name or class>,
"with": <options>}`. A glob of paths is matched against the whole path: `*`
and `?` match within one directory, and `**` across any number of them.
A `use` with a backslash is a class, so a class in the global namespace is
written `"\\SlackReporter"`; [writing an
adapter](../guide/extending/writing-an-adapter.md) says how one reads its
options.

| Key | Type | Default | Decided in |
|-----|------|---------|------------|
| `extensions` | list of class names | `[]` | [0002](../decisions/0002-one-typed-config-from-several-formats.md) |
| `preset` | a preset name, or a list of them: `library`, `laravel`, `symfony` | chosen from `composer.json` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `runner` | adapter: `pest`, `infection`, `phpunit` | the one installed: `phpunit` only where neither of the others is, and Pest is not | [0002](../decisions/0002-one-typed-config-from-several-formats.md), [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `runner.withhold` | list of environment-variable names or globs the runner never hands the project's tests, added to those every run withholds; a guard against accidents, not a sandbox | `[]` | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `runner.memory` | the `memory_limit` of every PHP process a mutation run starts, as PHP writes it (`512M`, `1G`), or `-1` for none; a `memory_limit` the project sets in `phpunit.xml` or a bootstrap file wins over it. Under Infection and the PHPUnit runner it also sets `display_errors=stdout`, so a warning raised outside a test, such as in a bootstrap file, also prints on standard output | `1G` | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `runner.workers` | how the PHPUnit runner starts each mutant's run: `fork`, from a worker per process that boots the autoloader and the bootstrap once, or `fresh`, a new PHP process per mutant. `fork` needs `pcntl` in the PHP the runner starts, and mutants run fresh without it. A boot that leaves a socket or database connection open, starts PHPUnit's events or loads a file the run mutates is refused: its mutants run fresh, and the run warns of why, as `doctor` does. Survivors are always confirmed in a fresh process. Pest and Infection start their own processes, so it changes nothing for them | `fork` | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `treeSource` | adapter: `phpunit`, `composer` | `phpunit` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `treeSource.with.fallback` | list of paths, the trees when `phpunit.xml` has no `<source>` | `[]`, or the preset's; `[]` takes the `autoload` paths of `composer.json` | [0002](../decisions/0002-one-typed-config-from-several-formats.md) |
| `trees` | list of `{path, floor, reason, exclude}`, laid over the tree source's trees: a listed path takes its floor, reason and exclude from here | the tree source's trees | [0003](../decisions/0003-a-floor-only-rises.md) |
| `trees[].floor` | number, 0 to 100 | the nearest manifest's, if any | [0003](../decisions/0003-a-floor-only-rises.md) |
| `trees[].reason` | string | none; required when `floor` is 0, and refused beside any other | [0003](../decisions/0003-a-floor-only-rises.md) |
| `trees[].exclude` | list of globs, each matching a file in the tree | `[]` | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `newCode.floor` | number, 0 to 100 | `100` | [0003](../decisions/0003-a-floor-only-rises.md) |
| `security.floor` | number, 0 to 100 | none | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `uncovered` | `count` or `exclude` | `count` | [0003](../decisions/0003-a-floor-only-rises.md) |
| `baseline.path` | path | `mutation-gate.baseline.json` | [0003](../decisions/0003-a-floor-only-rises.md) |
| `baseline.improvement` | `require` or `report` | `require` | [0003](../decisions/0003-a-floor-only-rises.md) |
| `packages` | list of globs | `[]` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `reach.everything` | list of globs, from the repository's root | `[]`, plus the preset's | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `holds.hotPath` | number, 0 to 1 | `0.8` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `run.full` | boolean: a run given neither `--full` nor `--changed-since` considers every unit, rather than what changed since `last-passed` | `false` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `shards.seconds` | integer | `600` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `shards.max` | integer | `20` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `shards.target` | duration; replaces `shards.seconds`, and setting both is an error | none | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `shards.setup` | duration: each shard's CI setup before the gate starts | `1m` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `costs.secondsPerLine` | map of path prefix to number | `{"": 0.2}` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `costs.perRunnerMinute` | `{amount, currency}` | none | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `ci.plan` | adapter: `github`, `gitlab`, `buildkite`, `circleci`, `azure`, `bitbucket`, `jenkins`, `json` | detected from the environment | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `ci.defaultBranch` | branch name | the CI's answer, else git's `origin/HEAD`, else `main` | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.check` | the check-run name the verdict reports under | `mutation / verdict` | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `ci.trustMergedPullRequests` | boolean: whether the default branch takes a merged pull request's own recorded pass as proof of its tree and spares it a re-check. It works only where pull request runs write their own scope's ledger, an untrusted write, so turning it on trusts the pull request's own computed result | `false` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
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
| `coverage.incremental` | boolean: whether a run measures again only the test files whose coverage could have moved, keeping the rest from the map its own scope keeps, or else the default branch's | `true` | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `budget` | duration | none | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.mode` | `confirm` or `unjudged` | `confirm` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.seconds` | integer: the least a mutant's run is allowed, and what one whose covering tests were not all timed is; each mutant otherwise gets three times the start-up its shard's run measured (5 s where it measured none) plus three times its covering tests' own time, within the two bounds. Under `pest.patch`, `infection:patch` and with the PHPUnit runner, a mutant's run is also stopped where no test finishes for that rule of its slowest covering test's time. Infection does so under `infection:patch`; unpatched, it keeps its own limit under `timeouts.most`, with no floor, and every run warns of it | `10`; `30` in the `laravel` and `symfony` presets | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.most` | integer, at least `timeouts.seconds`: the most a mutant's run is allowed; unpatched Infection skips a mutant whose covering tests take as long | `300` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.tighter.mutators` | list of mutators by their short names, the last part of the name a runner gives a mutator, after its last `\` or `/`: `RemoveArrayItem` matches Pest's `Pest\Mutate\Mutators\Removal\RemoveArrayItem` and the default set's `default/RemoveArrayItem`. A mutant of a listed mutator has its silence limit kept above `timeouts.tighter.floor` instead of `timeouts.seconds` | `RemoveArrayItem`, `DecrementInteger`, `IncrementInteger`, `ForeachEmptyIterable`, `UnwrapArrayValues`, `InstanceOfToTrue`, `InstanceOfToFalse`, `TernaryNegated`, and Infection's `ArrayItemRemoval`, `Foreach_`, `InstanceOf_` and `Ternary` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.tighter.floor` | integer, at least 1: the floor of the silence limit of a mutant of a mutator `timeouts.tighter.mutators` lists, where it is under `timeouts.seconds`; its whole-run limit keeps `timeouts.seconds` | `7` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `flaky.confirmSurvivors` | boolean | `true` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `tests.order` | `killers-first` or `runner` | `killers-first` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `survivorsFirst.max` | whole number, at least 0: how many of the last run's survivors a pull request's run re-checks before its shards, those on changed lines first; `0` re-checks none | `20` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `ignores.entries` | list of `{mutant, reason, expires}` or `{path, mutator, reason, expires}` | `[]` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `ignores.maxDays` | integer | none | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `ignores.native` | `refuse` or `allow` | `refuse` | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `equivalence.static` | boolean | `true` | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `reports` | list of `{use, path, with}`; built-in `json`, `junit`, `sarif`, `html`, `tests`, `kill-matrix`, `gitlab`, `sonar`; `badge`, whose `path` is `--publish-dir` where it names none; and without a `path` `console`, `problems`, `slack`, `discord`, `webhook`, `otlp`, `github-annotations`, `github-summary`, `github-comment`. A config names every `path` inside the project | `[]` | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `badge.colors` | map of shields.io colour to lowest score | `{"brightgreen": 90, "green": 80, "yellow": 70, "orange": 60}`, red below | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `pest.patch` | boolean | `false` | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `pest.canary` | group name, with no whitespace | `mutation-canary` | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `staticCheck.tool` | adapter: `mago`, `phpstan`, `psalm`; or `auto`, the first installed and configured, or `none` | `auto` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `staticCheck.config` | path | the analyser's own | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `staticCheck.seconds` | integer | `60` | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `mutators.sets` | list of mutator set names, never `default`, which is always on | `[]`, plus the preset's | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `mutators.except` | list of mutator names, `<set>/<Name>`, each held by a set in `mutators.sets` or by the default set; under Pest or Infection, by a set in `mutators.sets` | `[]` | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `local.watchBudget` | duration | `1m` | [0010](../decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| `local.prePushBudget` | duration | `5m` | [0010](../decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |

## In a package's `composer.json`

In a `composer.json`, under `extra.mutation-gate`:

| Key | Type | Default | Decided in |
|-----|------|---------|------------|
| `extensions` | list of class names | `[]` | [0001](../decisions/0001-a-framework-free-core-behind-nine-ports.md) |
| `floor` | number, 0 to 100 | none | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `floorReason` | string | none; required when `floor` is 0 | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `newCodeFloor` | number, 0 to 100 | `newCode.floor` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `securityFloor` | number, 0 to 100, in a package's `composer.json` only | `security.floor` | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
