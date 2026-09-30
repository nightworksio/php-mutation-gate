# mutation-gate

> **In development, not yet released.** This README describes what the
> decisions in [`.docs/decisions`](.docs/decisions/README.md) settle, and
> nothing more. Nothing is tagged until every feature below is built, tested
> and gated at 100%, and the first release is 1.0.0.

mutation-gate turns mutation testing into a CI gate for PHP projects. It
decides:

- which code to mutate;
- where to run it, spread over as many CI jobs as the work needs;
- what it can skip because the result is already proved;
- whether the result may merge.

The mutating itself is done by the tool you already use: Pest's `--mutate`, or
Infection.

Each tree of your code has a floor, the lowest score it may have. The floor
only rises. New code has a floor of its own, 100 by default. A pull request
mutates what it reaches, and every surviving mutant arrives with the one command
that reproduces it and a sentence saying what the tests miss.

## What it does

| | Feature | Decided in |
|---|---------|------------|
| **Adoption** | Zero-config start: trees from `phpunit.xml`'s `<source>`, and an optional config file | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| | Floors that only rise: a committed baseline, which fails on regression and rises on improvement | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| | Pull-request mode: changed lines and what the change reaches, with a stricter floor for new code | [0003](.docs/decisions/0003-a-floor-only-rises.md), [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| | Monorepos: a floor per package and module, with reach that follows the dependencies | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| **CI and speed** | A cost model that learns how long each file takes from earlier shards | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| | Sharding on any CI: GitHub Actions, GitLab, Buildkite, CircleCI, or a JSON plan | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| | A proof cache keyed by content, stored in the GitHub cache, a directory or S3/R2 | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| | Likely killers first: each mutant's tests ordered by which of them killed it before | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| | A shard count chosen from a target wall time | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| | A fork's pull request reads the default branch's proofs, read-only | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| **Reporting** | JSON, JUnit and SARIF, and line annotations on GitHub | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | Holding tests: Pest `holds:` groups and a `#[Holds]` attribute, a check that a group covers what it holds, and a warning for code every test runs through that nothing holds | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| | Infection as well as Pest | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| **Local use** | Watch mode and a pre-push hook | [0010](.docs/decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| | One command that reproduces each survivor | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md), [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | For each survivor, what the tests miss | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| **Run control** | A time budget that runs the riskiest code first, and reports anything unjudged instead of passing it | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Triage of timeouts and flaky tests | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Ignores for equivalent mutants, each with a reason and an optional expiry | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Survivors the compiler proves equivalent, left out of the score | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| | Presets for Laravel, Symfony and plain libraries | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| **Visibility** | An HTML report | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | A sticky comment on the pull request | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | A badge (a shields.io endpoint) and a trend on the default branch | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |

## Install

```sh
composer require --dev nightworksio/mutation-gate
```

Requirements:

- PHP 8.5 or a later 8.x, with pcov or Xdebug for coverage.
- One of the two runners:
  - **Pest**: `pestphp/pest` ^5.1 with `pestphp/pest-plugin-mutate` ^5.0, on
    the PHPUnit 13 release Pest pins;
  - **Infection**: `infection/infection` ~0.35.0, with PHPUnit 12 or 13.

  Infection does not run Pest suites, so a Pest project uses Pest's own
  mutation testing.

Optional packages, each needed only for what it enables:

- `symfony/yaml` for a YAML config;
- `nette/neon` for a NEON config;
- `async-aws/s3` for proofs kept in S3 or R2.

## A first run, with no config

```sh
vendor/bin/mutation-gate
```

With no config file, the gate works everything out:

- **trees**: from the `<source>` of `phpunit.xml` (or `phpunit.xml.dist`);
- **runner**: whichever of Pest and Infection is installed;
- **preset**: Laravel, Symfony or library, from your `composer.json`.

It runs the suite once under coverage, mutates every tree and prints each tree's
score. Every surviving mutant is shown with its diff, the tests that ran it,
what they miss, and the command that reproduces it:

```sh
vendor/bin/mutation-gate reproduce 3f9a1c2b7d04
```

On a first local run there is no baseline yet, so the gate writes
`mutation-gate.baseline.json` with each tree's floor at the score it measured,
and asks you to commit it. From then on a tree's score may not fall below its
floor. When the score improves, `vendor/bin/mutation-gate baseline --write`
raises the floor. In CI, a tree with no floor at all stops the run and says how
to write one.

## Commands

| Command | What it does |
|---------|--------------|
| `mutation-gate` or `mutation-gate run` | Plan, run and judge in one process. With `--changed-since=<ref>`, only what the change reaches. With `--budget=<duration>`, the riskiest code first, within that time. |
| `plan` | Work out the reach, drop proved units, cut shards and print the plan for a CI (`--ci=github\|gitlab\|buildkite\|circleci\|json`, or `--shards=<n>` for a fixed count) |
| `run --plan=<file> [--shard=<id>]` | Mutate one shard: the one `--shard` names, or the one the CI's environment names |
| `verdict --plan=<file> --results=<dir>` | Merge every shard's results, judge the floors, write reports and the ledger |
| `baseline [--write]` | Show, or write, floors raised to what was measured |
| `reproduce <id>` | Run one mutant again and show why it survives |
| `triage <path> [--repeat=<n>] [--order=runner\|killers-first]` | Run a unit n times (5 by default) and list every mutant whose result varied, with each mutant's tests in the order `--order` names (`tests.order` by default) |
| `watch` | Re-judge what each save reaches |
| `pre-push` | Judge the commits being pushed, as CI will |
| `hook install` / `hook uninstall` | Add or remove the pre-push hook |
| `init [--format=php\|json\|yaml\|neon]` | Write a config holding what zero-config found (PHP by default, or the file `--config` names, in the format of its extension), and add `.mutation-gate/` to `.gitignore` |
| `config:show [--format=…]` / `config:schema` | Print the effective config (JSON by default), or the JSON Schema |
| `pest:patch` | Apply the optional Pest patches ([ADR-0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md)) |

Options:

| Option | Accepted by | What it does | Decided in |
|--------|-------------|--------------|------------|
| `--config=<path>` | every command | Read this config file instead of looking for one | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `--no-extensions` | every command | Load only the package's own adapters | [0001](.docs/decisions/0001-a-framework-free-core-behind-eight-ports.md) |
| `--runner=<name>` | every command | Set `runner` | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `--report=<name>:<path>` | every command, repeatable | Add a file report to `reports` | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `--changed-since=<ref>` | `plan`, `run` without a plan | Mutate only what the change since `<ref>` reaches; `last-passed` is the newest passing commit | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `--full` | `plan`, `run` without a plan | Mutate everything, whatever the event; an error beside `--changed-since` | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `--budget=<duration>` | `run` | Stop after this long, riskiest code first | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `--coverage=<dir>` | `plan`, `run` without a plan | Read the coverage an earlier job wrote instead of running the suite | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--ci=<name>` | `plan`, `run` | Set `ci.plan`: this CI's format instead of the detected one | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--shards=<n>` | `plan`, `run` without a plan | Cut exactly n shards | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--plan=<file>` | `run`, `verdict` | The plan the shards and the verdict follow | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--shard=<id>` | `run` with a plan | The shard to mutate, instead of the one the CI names | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--results=<dir>` | `verdict` | Where every shard's result is | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--publish-dir=<dir>` | `verdict`, `run` without a plan | Where the badge and trend are written, `.mutation-gate/publish` by default | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |

Exit codes: `0` passed, `1` failed, `2` could not judge. The last means, for
example, an invalid config, an opening test run that failed, or a shard with
no results. `run` with a plan exits `0` once its shard's result is written,
because the verdict judges it, and `2` when it cannot write it, the plan
belongs to another commit, or the CI's shard count differs from the plan's.

## Configuration

A config file is optional. `mutation-gate.php` is the canonical format, and
JSON, YAML and NEON say exactly the same things. The four files below are the
same config.

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

A setting that names an adapter takes either a registered name (`"pest"`,
`"sarif"`) or a class with its options (`{"use": "Acme\\Gate\\SlackReporter",
"with": {"channel": "#ci"}}`). Packages that offer adapters are found through
`extra.mutation-gate.extensions` in their `composer.json`
([ADR-0001](.docs/decisions/0001-a-framework-free-core-behind-eight-ports.md)).

### Configuration reference

Every key, with its type, its default and the decision that sets it. Durations
are written `90s`, `15m` or `1h30m`, and dates `YYYY-MM-DD`. A key that
chooses an adapter takes a registered name or `{"use": <name or class>,
"with": <options>}`.

| Key | Type | Default | Decided in |
|-----|------|---------|------------|
| `extensions` | list of class names | `[]` | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `preset` | a preset name, or a list of them: `library`, `laravel`, `symfony` | chosen from `composer.json` | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `runner` | adapter: `pest`, `infection` | the one installed | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `treeSource` | adapter: `phpunit`, `composer` | `phpunit` | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `treeSource.with.fallback` | list of paths, the trees when `phpunit.xml` has no `<source>` | `[]`, or the preset's; `[]` takes the `autoload` paths of `composer.json` | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `trees` | list of `{path, floor, reason}` | the tree source's trees | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `trees[].floor` | number, 0 to 100 | the nearest manifest's, if any | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `trees[].reason` | string | none; required when `floor` is 0 | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `newCode.floor` | number, 0 to 100 | `100` | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `uncovered` | `count` or `exclude` | `count` | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `baseline.path` | path | `mutation-gate.baseline.json` | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `baseline.improvement` | `require` or `report` | `require` | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `packages` | list of globs | `[]` | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `reach.everything` | list of globs | `[]`, plus the preset's | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `holds.hotPath` | number, 0 to 1 | `0.8` | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `shards.seconds` | integer | `600` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `shards.max` | integer | `20` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `shards.target` | duration; replaces `shards.seconds`, and setting both is an error | none | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `shards.setup` | duration: each shard's CI setup before the gate starts | `1m` | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `costs.secondsPerLine` | map of path prefix to number | `{"": 0.2}` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.plan` | adapter: `github`, `gitlab`, `buildkite`, `circleci`, `json` | detected from the environment | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.defaultBranch` | branch name | the CI's answer, else git's `origin/HEAD`, else `main` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.gitlab.template` | path | `.gitlab/mutation-gate.yml` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.buildkite.step` | map of step keys | `{}` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `proofs.store` | adapter: `directory`, `s3` | `directory` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.path` (`directory`) | path | `.mutation-gate/ledger` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.bucket` (`s3`) | string | none; required | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.prefix` (`s3`) | string | `mutation-gate` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.region` (`s3`) | string | `us-east-1` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.endpoint` (`s3`) | URL | AWS's own | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.publicUrl` (`s3`) | `https://` URL a run without credentials reads ledgers from | none | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `proofs.ignore` | list of globs | `[]` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.write` | `auto` or `never` | `auto` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `budget` | duration | none | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.mode` | `confirm` or `unjudged` | `confirm` | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.seconds` | integer | `10`; `30` in the `laravel` and `symfony` presets | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.retries` | integer | `20` | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `flaky.confirmSurvivors` | boolean | `true` | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `tests.order` | `killers-first` or `runner` | `killers-first` | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `ignores.entries` | list of `{mutant, reason, expires}` or `{path, mutator, reason, expires}` | `[]` | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `ignores.maxDays` | integer | none | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `ignores.native` | `refuse` or `allow` | `refuse` | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `equivalence.static` | boolean | `true` | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `reports` | list of `{use, path, with}`; built-in `json`, `junit`, `sarif`, `html` | `[]` | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `badge.colors` | map of shields.io colour to lowest score | `{"brightgreen": 90, "green": 80, "yellow": 70, "orange": 60}`, red below | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `pest.patch` | boolean | `false` | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `pest.canary` | group name | `mutation-canary` | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `local.watchBudget` | duration | `60s` | [0010](.docs/decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| `local.prePushBudget` | duration | `5m` | [0010](.docs/decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |

In a `composer.json`, under `extra.mutation-gate`:

| Key | Type | Default | Decided in |
|-----|------|---------|------------|
| `extensions` | list of class names | `[]` | [0001](.docs/decisions/0001-a-framework-free-core-behind-eight-ports.md) |
| `floor` | number, 0 to 100 | none | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `floorReason` | string | none; required when `floor` is 0 | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `newCodeFloor` | number, 0 to 100 | `newCode.floor` | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |

Environment variables that change what the gate does:

| Variable | What it does | Decided in |
|----------|--------------|------------|
| `CI` | Set: a tree with no floor stops the run, `baseline.improvement` applies, and on the default branch the badge and trend are written. Unset: a full run writes missing floors and raises improved ones | [0003](.docs/decisions/0003-a-floor-only-rises.md), [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `GITHUB_ACTIONS`, `GITLAB_CI`, `BUILDKITE`, `CIRCLECI` | Choose the CI plan, and under GitHub Actions the annotations and step summary | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `SHARD`, `CI_NODE_INDEX`, `CI_NODE_TOTAL`, `BUILDKITE_PARALLEL_JOB`, `BUILDKITE_PARALLEL_JOB_COUNT`, `CIRCLE_NODE_INDEX`, `CIRCLE_NODE_TOTAL`, `CI_JOB_NAME`, `PARENT_PIPELINE_ID` | Which shard a job is, and how GitLab's child pipeline finds the plan | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `GITHUB_REF`, `GITHUB_EVENT_NAME`, `GITHUB_EVENT_PATH`, `CI_COMMIT_REF_NAME`, `CI_MERGE_REQUEST_IID`, `CI_DEFAULT_BRANCH`, `BUILDKITE_BRANCH`, `BUILDKITE_PULL_REQUEST`, `BUILDKITE_PIPELINE_DEFAULT_BRANCH`, `CIRCLE_BRANCH`, `CIRCLE_PULL_REQUEST` | The run's ref, whether it is a pull request, and the default branch | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `GITHUB_WORKFLOW_REF`, `CI_CONFIG_PATH` | Which CI definition runs the gate, for reach and the proof key | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md), [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `GITHUB_OUTPUT`, `GITHUB_STEP_SUMMARY` | Where the GitHub plan and the step summary are written | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `GITHUB_RUN_ID`, `GITHUB_RUN_ATTEMPT`, `CI_PIPELINE_ID`, `BUILDKITE_BUILD_ID`, `CIRCLE_WORKFLOW_ID` | The run a proof names | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `GITHUB_TOKEN` | Lets the sticky PR comment be posted, and the GitHub change source prove which pull request's run passed | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md), [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN` | Credentials for the S3 proof store | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `MUTATION_GATE_RESULTS` | Set by the Pest adapter for its own plugin; not for users | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `MUTATION_GATE_SHARED_COVERAGE`, `MUTATION_GATE_SUITE_SECONDS`, `MUTATION_GATE_CANARY` | Set by the Pest adapter for the lines `pest:patch` writes into pest-plugin-mutate: the planning job's coverage map, its suite's seconds and the canary group; not for users | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |

Files the gate reads and writes:

| Path | What it is | Decided in |
|------|------------|------------|
| `mutation-gate.php`, `.json`, `.yaml`, `.yml` or `.neon` | The config | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `mutation-gate.baseline.json` | The committed floors | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `.mutation-gate/plan.json`, `.mutation-gate/coverage/`, `.mutation-gate/results/<id>.json` | The plan, its coverage and each shard's result | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `.mutation-gate/pipeline.yml` | GitLab's child pipeline | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `.mutation-gate/ledger/<scope>/ledger.json` | The proof ledger of one ref | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `.mutation-gate/mutants/<native id>.php` | The mutated file of a mutant judged by reference (Pest) | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `.mutation-gate/publish/badge.json`, `trend.json`, `trend.svg` | The badge and trend | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |

### Holding tests

Some code is run by every test: a composition root, a service provider or a
kernel. Each mutant of it would run the whole suite. Declare instead which tests
hold it. The path is then mutated against those tests alone, once they are shown
to cover every line of it that the whole suite covers.

```php
// A Pest test, or every test in a describe
use NightWorksIO\MutationGate\Attribute\Holds;

it('boots the kernel', #[Holds('src/Kernel.php')] function () {
    // …
});

// or, for the whole file
pest()->group('holds:src/Kernel.php');
```

```php
// A PHPUnit test class
use NightWorksIO\MutationGate\Attribute\Holds;

#[Holds('src/Kernel.php')]
final class KernelTest extends TestCase {}
```

A PHPUnit class run by Pest also needs `#[Group('holds:src/Kernel.php')]`
beside its `#[Holds]`, because Pest cannot add a group to a class it did not
build
([ADR-0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md)).

## In CI

A CI run has three steps:

- **plan** works out what to mutate and cuts it into shards;
- **run** mutates one shard;
- **verdict** judges everything and is the check to protect.

A proof ledger lets each step skip what an earlier run already proved.

Two things the setup relies on:

- **The scheduled run.** A full run on the default branch, at least once a
  week, is part of the design on every CI, not an extra. It catches what a
  change's reach cannot see
  ([ADR-0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md)).
  The GitHub examples run it twice a week, because GitHub evicts a cache entry
  nothing restores for seven days
  ([ADR-0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)).
- **The optional Pest patches.** For sharded Pest runs, enabling them
  (`pest.patch: true`, plus `@php vendor/bin/mutation-gate pest:patch` in
  `post-install-cmd` and `post-update-cmd`) lets every shard reuse the planning
  job's coverage instead of running the whole suite again.

The proof ledger's trust boundary is the store's access control. On GitHub,
cache scoping keeps a pull request from writing what the default branch reads.
On GitLab, separate caches for protected branches do the same, and they also
keep merge requests from reading the default branch's ledger. On Buildkite and
CircleCI a branch's pipeline config picks its cache key, so a cache is no
boundary there. Wherever a pull request must read the default branch's proofs
safely, keep the ledger in S3, with credentials that can write the default
branch's prefix held only by default-branch runs
([ADR-0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)).

A fork's pull request runs without credentials. On GitHub's cache it restores
the default branch's ledger read-only, as any pull request does. With S3, set
`proofs.store.with.publicUrl` and give the bucket a policy that allows a public
`GetObject` on `<prefix>/refs/heads/<default branch>/*` and nothing else. A run
without credentials then reads the default branch's ledger from that URL, and
writes nothing. A fork can plant no proof that another run trusts. It can
influence only its own verdict, which its own workflow file could anyway, so
require approval before outside contributors' workflows run
([ADR-0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)).

### Use it in GitHub Actions

The repository is also a GitHub Action. Pin it to a full commit SHA, with its
tag in a comment. A release workflow moves the major tag (`v1`) to each new
release.

**One step, for most projects.** The action sets up PHP, installs your
dependencies, keeps the proof ledger in the Actions cache and runs the whole
gate in one job. It writes line annotations and the step summary, and posts
the sticky PR comment.

```yaml
name: mutation

on:
  pull_request:
  push:
    branches: [main] # your default branch
  schedule:
    - cron: '0 3 * * 1,4'

permissions:
  contents: read

jobs:
  mutation:
    name: mutation testing
    runs-on: ubuntu-latest
    permissions:
      contents: read
      actions: read
      pull-requests: write
    steps:
      - uses: actions/checkout@<sha> # <tag>
        with:
          fetch-depth: 0
          persist-credentials: false
      - uses: nightworksio/php-mutation-gate@<sha> # v1.0.0
        with:
          php-version: '8.5'
```

| Input | Default |
|-------|---------|
| `config` | the config file the gate finds |
| `php-version` | `8.5` |
| `runner` | the config's, or the one installed |
| `shard` | none: the whole gate runs |
| `mode` | `auto`: change-scoped on pull requests and pushes, full on schedules, manual runs, releases and tags; or `full`, or `changed` |
| `changed-since` | the pull request's base on `pull_request`, `last-passed` on a push to the default branch; used when the mode is change-scoped |
| `budget` | none |
| `reports` | none; `<name>:<path>` lines, such as `sarif:build/mutation.sarif` |
| `cache` | `true`: keep the ledger in the Actions cache |

Its outputs are `verdict` (`passed`, `failed` or `cannot-judge`), `scores`
(JSON), `report-paths` (JSON) and `plan` (JSON). The one-step action writes
the badge and trend to `.mutation-gate/publish` but does not publish them,
because that needs `contents: write` in a job that also runs on pull requests.
The reusable workflow publishes them.

**Sharded, for large projects.** The reusable workflow runs a `plan` job, one
`shard` job per shard and a `verdict` job. A last job, `publish`, runs on the
default branch only and publishes the badge and trend.

```yaml
name: mutation

on:
  pull_request:
  push:
    branches: [main] # your default branch
  schedule:
    - cron: '0 3 * * 1,4'

permissions:
  contents: read

jobs:
  mutation:
    uses: nightworksio/php-mutation-gate/.github/workflows/mutation-gate.yml@<sha> # v1.0.0
    permissions:
      contents: write        # used only by the default-branch publish job
      actions: read
      pull-requests: write
    with:
      php-version: '8.5'
```

It takes the action's inputs less `shard`, and the optional secrets
`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and `AWS_SESSION_TOKEN` for an S3
proof store. Its outputs are `verdict`, `scores` and `plan`, and it uploads the
reports as the artifact `mutation-gate-reports`.

Here is what the examples rely on:

- **Branch protection** should require the verdict's check. In the one-step
  example it is `mutation testing`. With the reusable workflow it is the
  `verdict` job, shown as `mutation / verdict`. The verdict says *cannot
  judge* (exit code 2) when any planned shard left no result.
- **The schedule** is the full run, twice a week.
- **The badge and trend** are published to a `mutation-gate` branch:

  ```markdown
  ![mutation score](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/<owner>/<repo>/mutation-gate/badge.json)
  ```

- **SARIF.** To see survivors in code scanning, add a `sarif` report and upload
  it with `github/codeql-action/upload-sarif`.

### GitLab CI

`parallel:matrix` has to be written before a pipeline starts, so the plan
writes a child pipeline and the parent triggers it. Define a hidden job,
`.mutation-gate`, that sets the image and installs dependencies, in the file
`ci.gitlab.template` names (`.gitlab/mutation-gate.yml` by default). The
generated jobs extend it, and so does the plan job. A merge request is judged
from its base, the default branch from the last commit that passed, and a
weekly scheduled pipeline mutates everything.

```yaml
include:
  - local: .gitlab/mutation-gate.yml

mutation-plan:
  stage: test
  extends: .mutation-gate
  script:
    - |
      case "$CI_PIPELINE_SOURCE" in
        merge_request_event) base="--changed-since=$CI_MERGE_REQUEST_DIFF_BASE_SHA" ;;
        schedule) base="" ;;
        *) base="--changed-since=last-passed" ;;
      esac
      vendor/bin/mutation-gate plan --ci=gitlab $base
  artifacts:
    paths: [.mutation-gate/]

mutation:
  stage: test
  needs: [mutation-plan]
  variables:
    PARENT_PIPELINE_ID: $CI_PIPELINE_ID
  trigger:
    include:
      - artifact: .mutation-gate/pipeline.yml
        job: mutation-plan
    strategy: mirror
```

The child pipeline holds one job with `parallel: matrix` over the shards and
a verdict job that runs even after a failed shard, and the `mutation` trigger
job takes its result. Keep `.mutation-gate/ledger` in a `cache:` keyed by
branch, such as `key: mutation-gate-ledger-$CI_COMMIT_REF_SLUG`.

### Buildkite

```yaml
steps:
  - label: "mutation: plan"
    artifact_paths: ".mutation-gate/**/*"
    command:
      - composer install
      - |
        if [ "$BUILDKITE_PULL_REQUEST" != "false" ]; then base="--changed-since=origin/$BUILDKITE_PULL_REQUEST_BASE_BRANCH"
        elif [ "$BUILDKITE_SOURCE" = "schedule" ]; then base=""
        else base="--changed-since=last-passed"; fi
        vendor/bin/mutation-gate plan --ci=buildkite $base | buildkite-agent pipeline upload
```

The uploaded steps are one step per shard, a `wait` that continues on failure,
then the verdict. Each is built from your step template (`ci.buildkite.step`),
and they pass the plan and results with `buildkite-agent artifact`. Schedule
the pipeline weekly for the full run, and keep `.mutation-gate/ledger` with a
cache plugin keyed by branch.

### CircleCI

CircleCI's parallelism is fixed in the config, so the plan cuts exactly that
many shards, and each `mutation` node reads its shard from `CIRCLE_NODE_INDEX`.
The ledger's cache keys use a checksum of the branch name, so no branch's key
is a prefix of another's. Each branch's cache holds only its own ledger: the
verdict job drops the default branch's copy before saving, and the plan job
restores both caches in turn.

```yaml
jobs:
  mutation-plan:
    docker: [{ image: <a PHP 8.5 image with pcov> }]
    steps:
      - checkout
      - run: |
          mkdir -p .mutation-gate
          echo "$CIRCLE_BRANCH" > .mutation-gate/branch
          echo main > .mutation-gate/default-branch
      - restore_cache:
          keys: [mutation-gate-ledger-{{ checksum ".mutation-gate/branch" }}-]
      - restore_cache:
          keys: [mutation-gate-ledger-{{ checksum ".mutation-gate/default-branch" }}-]
      - run: composer install
      - run: |
          if [ "<< pipeline.trigger_source >>" = "scheduled_pipeline" ]; then base=""
          elif [ -n "$CIRCLE_PULL_REQUEST" ]; then base="--changed-since=origin/main"
          else base="--changed-since=last-passed"; fi
          vendor/bin/mutation-gate plan --shards=4 $base
      - persist_to_workspace: { root: ., paths: [.mutation-gate] }
  mutation:
    parallelism: 4
    docker: [{ image: <a PHP 8.5 image with pcov> }]
    steps:
      - checkout
      - attach_workspace: { at: . }
      - run: composer install
      - run: vendor/bin/mutation-gate run --plan=.mutation-gate/plan.json
      - persist_to_workspace: { root: ., paths: [.mutation-gate/results] }
  mutation-verdict:
    docker: [{ image: <a PHP 8.5 image> }]
    steps:
      - checkout
      - attach_workspace: { at: . }
      - run: composer install
      - run: vendor/bin/mutation-gate verdict --plan=.mutation-gate/plan.json --results=.mutation-gate/results
      - run: '[ "$CIRCLE_BRANCH" = main ] || rm -rf .mutation-gate/ledger/refs/heads/main'
      - save_cache:
          key: mutation-gate-ledger-{{ checksum ".mutation-gate/branch" }}-{{ .Revision }}
          paths: [.mutation-gate/ledger]

workflows:
  mutation:
    jobs:
      - mutation-plan
      - mutation: { requires: [mutation-plan] }
      - mutation-verdict: { requires: [{ mutation: terminal }] }
```

`main` stands for your default branch. Add a weekly scheduled pipeline for the
full run. The verdict requires `mutation` with the status `terminal`, so it
runs, and says *cannot judge*, even when a shard failed.

### Any other CI

```sh
vendor/bin/mutation-gate plan --ci=json            # prints the shards; writes .mutation-gate/plan.json
vendor/bin/mutation-gate run --plan=.mutation-gate/plan.json --shard=<id>   # once per shard, in parallel
vendor/bin/mutation-gate verdict --plan=.mutation-gate/plan.json --results=.mutation-gate/results
```

Check out the branch by name (`git checkout -B <branch>`) before `plan`, because
the JSON plan takes the run's ref from git's current branch, and `run` and
`verdict` take it from the plan. A plan made on a detached `HEAD` writes no
ledger and publishes nothing
([ADR-0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md)).
The JSON plan never treats a run as a pull request, so there the new-code floor
applies only in pre-push and watch. Pass `--changed-since` with a change's base,
or `last-passed` on the default branch, and leave it out of a weekly scheduled
full run. Run the verdict even when a shard job failed, so a missing result is
judged *cannot judge*. Carry `.mutation-gate/` from job to job, and keep
`.mutation-gate/ledger` between runs with whatever cache your CI has, keyed by
branch. Proofs can also live in S3 or R2
([ADR-0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)).
To publish the badge and trend, restore the published files into
`.mutation-gate/publish` before the verdict on your default branch, and publish
that directory after it.

## Local use

```sh
vendor/bin/mutation-gate watch          # re-judges what each save reaches, within 60 seconds
vendor/bin/mutation-gate hook install   # a pre-push hook: judges the pushed commits within 5 minutes
```

`.mutation-gate/` holds local results and proofs. It belongs in `.gitignore`,
and `init` adds it there.

## How it is built

The design is recorded as [decisions](.docs/decisions/README.md):

- a framework-free core behind eight ports;
- Pest and Infection as adapters;
- a content-keyed proof ledger;
- the toolchain the package holds itself to.

That toolchain is:

- its own gate at 100%;
- PHPStan at max;
- arch tests, with a planted violation for every rule.

## Licence

MIT. See [LICENSE](LICENSE).
