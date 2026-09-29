# mutation-gate

> **In design, not yet released.** This README describes what the decisions in
> [`.docs/decisions`](.docs/decisions/README.md) settle, and nothing more. There
> is no code yet. Nothing is tagged until all twenty features below are built,
> tested and gated at 100%, and the first release will be 1.0.0.

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

It is extracted from the mutation gate of the lemonfiber companion app,
`scripts/mutation.php`, which holds most of that app's code to a floor of 100.

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
| **Reporting** | JSON, JUnit and SARIF, and line annotations on GitHub | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | Holding tests: Pest `holds:` groups and a `#[Holds]` attribute, a check that a group covers what it holds, and a warning for code every test runs through that nothing holds | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| | Infection as well as Pest | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| **Local use** | Watch mode and a pre-push hook | [0010](.docs/decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| | One command that reproduces each survivor | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md), [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | For each survivor, what the tests miss | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| **Run control** | A time budget that runs the riskiest code first, and reports anything unjudged instead of passing it | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Triage of timeouts and flaky tests | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Ignores for equivalent mutants, each with a reason and an optional expiry | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Presets for Laravel, Symfony and plain libraries | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| **Visibility** | An HTML report | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | A sticky comment on the pull request | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | A badge (a shields.io endpoint) and a trend on the default branch | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |

## Install

```sh
composer require --dev nightworksio/mutation-gate
```

Requirements:
- PHP 8.5, with pcov or Xdebug for coverage.
- One of the two runners:
  - **Pest**: `pestphp/pest` ^5.1 with `pestphp/pest-plugin-mutate` ^5.0;
  - **Infection**: `infection/infection` ~0.35.0, with PHPUnit 12 or 13.

  Infection no longer runs Pest suites, so a Pest project uses Pest's own
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

```
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
| `run --plan=<file> --shard=<id>` | Mutate one shard |
| `verdict --plan=<file> --results=<dir>` | Merge every shard's results, judge the floors, write reports and the ledger |
| `baseline [--write]` | Show, or write, floors raised to what was measured |
| `reproduce <id>` | Run one mutant again and show why it survives |
| `triage <path> --repeat=<n>` | Run a file n times and list every mutant whose result varied |
| `watch` | Re-judge what each save reaches |
| `pre-push` | Judge the commits being pushed, as CI will |
| `hook install` / `hook uninstall` | Add or remove the pre-push hook |
| `init --format=php\|json\|yaml\|neon` | Write a config holding what zero-config found |
| `config:show` / `config:schema` | Print the effective config, or the JSON Schema |
| `pest:patch` | Apply the optional Pest patches ([ADR-0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md)) |

Exit codes: `0` passed, `1` failed, `2` could not judge. The last means, for
example, an invalid config, an opening test run that failed, or a shard with
no results.

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
    "ignore": [
        { "mutant": "3f9a1c2b7d04", "reason": "Both branches build the same list", "expires": "2027-03-31" }
    ],
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
ignore:
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
ignore:
	- {mutant: '3f9a1c2b7d04', reason: 'Both branches build the same list', expires: 2027-03-31}
reports:
	- {use: sarif, path: build/mutation.sarif}
	- {use: html, path: build/mutation}
```

A setting that names an adapter takes either a registered name (`"pest"`,
`"sarif"`) or a class with its options (`{"use": "Acme\\Gate\\SlackReporter",
"with": {"channel": "#ci"}}`). Packages that offer adapters are found through
`extra.mutation-gate.extensions` in their `composer.json`
([ADR-0001](.docs/decisions/0001-a-framework-free-core-behind-eight-ports.md)).

### Holding tests

Some code is run by every test: a composition root, a service provider or a
kernel. Each mutant of it would run the whole suite. Declare instead which tests
hold it. The path is then mutated against those tests alone, once they are shown
to cover all of it.

```php
// A Pest test file
pest()->group('holds:src/Kernel.php');
```

```php
// A PHPUnit test class
use NightWorksIO\MutationGate\Attribute\Holds;

#[Holds('src/Kernel.php')]
final class KernelTest extends TestCase {}
```

## In CI

A CI run has three steps:
- **plan** works out what to mutate and cuts it into shards;
- **run** mutates one shard;
- **verdict** judges everything and is the check to protect.

A proof ledger lets each step skip what an earlier run already proved.

Two things the setup relies on:
- **The weekly run.** The schedule in the GitHub examples is part of the design,
  not an extra. It catches what a change's reach cannot see
  ([ADR-0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md)).
- **The optional Pest patches.** For sharded Pest runs, enabling them
  (`pest.patch: true`, plus `@php vendor/bin/mutation-gate pest:patch` in
  `post-install-cmd` and `post-update-cmd`) lets every shard reuse the planning
  job's coverage instead of running the whole suite again.

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
    branches: [main]
  schedule:
    - cron: '0 3 * * 1'

permissions:
  contents: read

jobs:
  mutation:
    name: mutation testing
    runs-on: ubuntu-latest
    permissions:
      contents: read
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

Its inputs are `config`, `php-version`, `runner`, `shard`, `changed-since`,
`budget`, `reports` and `cache`. Its outputs are `verdict`, `scores` (JSON),
the paths of the reports it wrote, and `plan` (JSON).

**Sharded, for large projects.** The reusable workflow runs a plan job, one
job per shard and an aggregate job that gives the verdict. A last job, on the
default branch only, publishes the badge and trend.

```yaml
name: mutation

on:
  pull_request:
  push:
    branches: [main]
  schedule:
    - cron: '0 3 * * 1'

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

Here is what the examples rely on:
- **Branch protection** should require the verdict's check. In the one-step
  example it is `mutation testing`. With the reusable workflow it is the
  aggregate job, shown as `mutation / <job name>`. The verdict fails when any
  planned shard left no result.
- **The schedule** is the weekly full run.
- **The badge and trend** are published to a `mutation-gate` branch:

  ```markdown
  ![mutation score](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/<owner>/<repo>/mutation-gate/badge.json)
  ```

- **SARIF.** To see survivors in code scanning, add a `sarif` report and upload
  it with `github/codeql-action/upload-sarif`.

### GitLab CI

`parallel:matrix` has to be written before a pipeline starts, so the plan
writes a child pipeline and the parent triggers it. The generated jobs extend
a hidden job you define in the file named by `ci.gitlab.template`, which sets
the image and installs dependencies.

```yaml
mutation-plan:
  stage: test
  extends: .mutation-gate
  script:
    - vendor/bin/mutation-gate plan --ci=gitlab
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
    strategy: depend
```

The child pipeline holds one job with `parallel: matrix` over the shards and
a verdict job, and the `mutation` trigger job takes its result.

### Buildkite

```yaml
steps:
  - label: "mutation: plan"
    artifact_paths: ".mutation-gate/**/*"
    command:
      - composer install
      - vendor/bin/mutation-gate plan --ci=buildkite | buildkite-agent pipeline upload
```

The uploaded steps are one step per shard, a `wait`, then the verdict. Each is
built from your step template (`ci.buildkite.step`), and they pass the plan
and results with `buildkite-agent artifact`.

### CircleCI

CircleCI's parallelism is fixed in the config, so the plan cuts exactly that
many shards.

```yaml
jobs:
  mutation-plan:
    docker: [{ image: <a PHP 8.5 image with pcov> }]
    steps:
      - checkout
      - run: composer install
      - run: vendor/bin/mutation-gate plan --shards=4
      - persist_to_workspace: { root: ., paths: [.mutation-gate] }
  mutation:
    parallelism: 4
    docker: [{ image: <a PHP 8.5 image with pcov> }]
    steps:
      - checkout
      - attach_workspace: { at: . }
      - run: composer install
      - run: vendor/bin/mutation-gate run --plan=.mutation-gate/plan.json --ci=circleci
      - persist_to_workspace: { root: ., paths: [.mutation-gate/results] }
  mutation-verdict:
    docker: [{ image: <a PHP 8.5 image> }]
    steps:
      - checkout
      - attach_workspace: { at: . }
      - run: composer install
      - run: vendor/bin/mutation-gate verdict --plan=.mutation-gate/plan.json --results=.mutation-gate/results

workflows:
  mutation:
    jobs:
      - mutation-plan
      - mutation: { requires: [mutation-plan] }
      - mutation-verdict: { requires: [mutation] }
```

Each `mutation` node reads its shard from `CIRCLE_NODE_INDEX`. To keep the
proof ledger between runs, keep `.mutation-gate/ledger` with `save_cache` and
`restore_cache`.

### Any other CI

```sh
vendor/bin/mutation-gate plan --ci=json            # prints the shards; writes .mutation-gate/plan.json
vendor/bin/mutation-gate run --plan=.mutation-gate/plan.json --shard=<id>   # once per shard, in parallel
vendor/bin/mutation-gate verdict --plan=.mutation-gate/plan.json --results=.mutation-gate/results
```

Carry `.mutation-gate/` from job to job, and keep `.mutation-gate/ledger`
between runs with whatever cache your CI has. Proofs can also live in S3 or R2
([ADR-0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)).

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
