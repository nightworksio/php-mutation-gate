<p align="center">
  <img alt="php-mutation-gate: a pixel-art gate with the PHP elephant standing in its arch" src="https://raw.githubusercontent.com/nightworksio/php-mutation-gate/main/.docs/brand/php-mutation-gate-logo.svg" width="288">
</p>

<h1 align="center">php-mutation-gate</h1>

<p align="center">
  <strong>Mutation testing as a CI gate for PHP.</strong><br>
  Floors that only rise, sharded and cached runs, and every survivor with the command that reproduces it.
</p>

<div align="center">

[![ci](https://github.com/nightworksio/php-mutation-gate/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/nightworksio/php-mutation-gate/actions/workflows/ci.yml?query=branch%3Amain)
[![mutation score](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2Fnightworksio%2Fphp-mutation-gate%2Fmutation-gate%2Fbadge.json)](https://github.com/nightworksio/php-mutation-gate/actions/workflows/mutation-gate.yml?query=branch%3Amain)
[![codeql](https://github.com/nightworksio/php-mutation-gate/actions/workflows/codeql.yml/badge.svg?branch=main)](https://github.com/nightworksio/php-mutation-gate/actions/workflows/codeql.yml?query=branch%3Amain)
[![quality gate](https://sonarcloud.io/api/project_badges/measure?project=nightworksio_php-mutation-gate&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=nightworksio_php-mutation-gate)
[![coverage](https://sonarcloud.io/api/project_badges/measure?project=nightworksio_php-mutation-gate&metric=coverage)](https://sonarcloud.io/summary/new_code?id=nightworksio_php-mutation-gate)
[![OpenSSF Scorecard](https://api.securityscorecards.dev/projects/github.com/nightworksio/php-mutation-gate/badge)](https://scorecard.dev/viewer/?uri=github.com/nightworksio/php-mutation-gate)

[![PHP](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2Fnightworksio%2Fphp-mutation-gate%2Fmain%2Fcomposer.json&query=%24.require.php&label=PHP&color=777BB4&logo=php&logoColor=white)](#requirements)
[![runners](https://img.shields.io/badge/runs-Pest%20%C2%B7%20Infection%20%C2%B7%20PHPUnit%20%C2%B7%20PhpSpec%20%C2%B7%20Codeception-4b5563)](#requirements)
[![docs](https://img.shields.io/badge/docs-nightworksio.github.io-0f766e)](https://nightworksio.github.io/php-mutation-gate/guide/)
[![discussions](https://img.shields.io/github/discussions/nightworksio/php-mutation-gate?label=Q%26A)](https://github.com/nightworksio/php-mutation-gate/discussions/categories/q-a)
[![licence](https://img.shields.io/github/license/nightworksio/php-mutation-gate?label=licence)](https://github.com/nightworksio/php-mutation-gate/blob/main/LICENSE)

**[Documentation](https://nightworksio.github.io/php-mutation-gate/guide/)** ·
[Quick start](#quick-start) ·
[CI setup](https://nightworksio.github.io/php-mutation-gate/guide/ci/) ·
[Reading the report](https://nightworksio.github.io/php-mutation-gate/guide/reading-the-report/) ·
[Command line](https://nightworksio.github.io/php-mutation-gate/reference/cli/) ·
[Configuration](https://nightworksio.github.io/php-mutation-gate/reference/configuration/) ·
[Decisions](https://nightworksio.github.io/php-mutation-gate/decisions/)

</div>

php-mutation-gate turns mutation testing into a check your pull requests must pass.
It is for PHP projects tested with Pest, Infection or PHPUnit that want a
mutation score they can hold in CI without mutating the whole codebase on
every push.

The mutating itself is done by the tool you already use: Pest's `--mutate`,
Infection, or, for PHPUnit on its own, the gate's own mutators. The gate
decides:

- **what to mutate:** on a pull request, only the code the change reaches;
- **where to run it:** cut into shards that fill as many CI jobs as the work
  needs;
- **what to skip:** anything an earlier run already proved;
- **whether it may merge:** each tree of your code (a source directory, such
  as `src` or `app/Domain`) has a floor, the lowest score it may have, and
  the floor only rises. New code has a floor of its own, 100 by default.

Every surviving mutant arrives with the one command that reproduces it and a
sentence saying what the tests miss.

> **No version is tagged.** The gate is published to no package registry:
> install it from this repository's `main` branch, as the quick start shows.

Each release's PHAR is signed with the release key, whose fingerprint is
`5EAA 6082 E57F F4C5 42A2  EA66 B757 A4B1 1361 16D2`, and attested by
GitHub; [SECURITY.md](https://github.com/nightworksio/php-mutation-gate/blob/main/SECURITY.md#verifying-a-release) says how to check both.
The PHAR keeps its dependencies apart from the project's, but for
`nikic/php-parser` and `psr/clock`, which mutators and extensions share with
the gate. The gate loads the PHAR's autoloader ahead of the project's in every
test run it starts, so a project that requires another major of either runs
its tests with the PHAR's copy. Such a project installs the gate through
Composer.

## Requirements

- PHP 8.4 or a later 8.x, with pcov or Xdebug for coverage, and the sodium
  extension, which the gate encodes with because it is timing-safe.
- Linux or macOS. Windows is not supported.
- One of the runners:
  - **Pest**: `pestphp/pest` ^5.1 with `pestphp/pest-plugin-mutate` ^5.0.2, on
    the PHPUnit 13 release Pest pins;
  - **Infection**: `infection/infection` ~0.35.0, with PHPUnit 12 or 13;
  - **PHPUnit** on its own: `phpunit/phpunit` 13.2 or later;
  - **PhpSpec**: `phpspec/phpspec` 8.2 or later, with
    `phpunit/php-code-coverage` 14;
  - **Codeception**: `codeception/codeception` 5.3.6 or later, with
    `phpunit/php-code-coverage` 14, for the suites that run in the test's
    own process.

  Infection and the PHPUnit runner do not run Pest suites, so a Pest project
  uses Pest's own mutation testing. Beside the gate, Composer installs no
  PHPUnit before 12.5.8, and neither 12.5.21 nor 13.1.5.

Optional packages, each needed only for what it enables:

- `symfony/yaml` for a YAML config;
- `nette/neon` for a NEON config;
- `async-aws/s3` for proofs kept in S3 or R2;
- `colinodell/json5` to import an `infection.json5`;
- `ext-pcntl`, and for Pest `ext-posix` too, for the warm workers each
  mutant's run forks from.

## Quick start

Add this repository to your project's Composer repositories, and require the
gate from its `main` branch:

```sh
composer config repositories.mutation-gate vcs https://github.com/nightworksio/php-mutation-gate
composer require --dev "nightworksio/mutation-gate:dev-main" -W
```

`-W` lets Composer move `pestphp/pest-plugin-mutate` up to 5.0.2, the least
release the gate allows. `composer.lock` holds the commit it installed, and
`composer update nightworksio/mutation-gate` moves it to the head of `main`.
A run refuses releases its runner does not drive before it starts, and
`vendor/bin/mutation-gate doctor` says so first, with what to require. It also
advises where `pestphp/pest-plugin-mutate` is newer than any release the gate
is tested with.

Set the project up, then run the gate:

```sh
vendor/bin/mutation-gate init
vendor/bin/mutation-gate
```

`init` detects your runner, preset (Laravel, Symfony or a plain library) and
CI, asks what detection leaves open, writes a config, and adds
`.mutation-gate/` to `.gitignore`. The config is
optional: with none, the gate takes its trees from the `<source>` of
`phpunit.xml` (or `phpunit.dist.xml`, or `phpunit.xml.dist`), one per
directory it names.

The run measures coverage once, mutates every tree and prints each tree's
score. Each surviving mutant is printed with its diff, the tests that ran it,
what they miss, and the commands that reproduce and explain it. On a pull
request, one looks like this:

```text
src/Money.php:7  LessToLessOrEqual  survived, on a changed line  3f9a1c2b7d04
    --- Original
    +++ New
    @@ @@
    -        if ($amount < $limit) {
    +        if ($amount <= $limit) {
    Judged by: MoneyTest::fits, MoneyTest::refuses, PriceTest::adds, CartTest::totals
    Add a test that uses a value at the boundary of `$amount < $limit`. It is judged by `MoneyTest::fits`, `MoneyTest::refuses`, `PriceTest::adds` and 1 more.
    Reproduce: vendor/bin/mutation-gate reproduce 3f9a1c2b7d04
    Explain: vendor/bin/mutation-gate explain 3f9a1c2b7d04
```

The first local run writes `mutation-gate.baseline.json`, holding each tree's
floor at the score it measured. Commit it. From then on a score may not fall
below its floor, and `vendor/bin/mutation-gate baseline --write` raises the
floors when scores improve. `vendor/bin/mutation-gate doctor` says what would
fail or run slowly before a run finds out.

New to mutation testing? [Reading the report](https://nightworksio.github.io/php-mutation-gate/guide/reading-the-report/)
says what the report shows, what is good and what is not, and what to do
next. `vendor/bin/mutation-gate digest` says the last run's verdict one fact a
line, for an agent or a terminal, running nothing.

## In CI

A CI run plans the work, mutates each shard in parallel, and judges the
result in a verdict job, which is the check to require.
`vendor/bin/mutation-gate init --ci` writes the definition for GitHub
Actions, GitLab CI, Buildkite, CircleCI, Azure DevOps, Bitbucket Pipelines or
Jenkins; on GitHub it uses this repository as an action, or calls its
reusable workflow. The action runs the project's own copy where its
`composer.json` requires the gate, and otherwise the signed PHAR of the
release the action is pinned to, once the PHAR's signature and attestation
hold. [Running the gate in CI](https://nightworksio.github.io/php-mutation-gate/guide/ci/)
has a page for each, and the commands for any other CI.

## Documentation

| Read | For |
|------|-----|
| [Running the gate in CI](https://nightworksio.github.io/php-mutation-gate/guide/ci/) | A page per CI, and what every setup needs |
| [Proofs and trust](https://nightworksio.github.io/php-mutation-gate/guide/concepts/proofs-and-trust/) | Where proofs are kept, and keeping pull requests from writing what the default branch trusts |
| [Holding tests](https://nightworksio.github.io/php-mutation-gate/guide/concepts/holding-tests/) | Mutating code every test runs through against the tests that hold it |
| [Pre-push hook and watch mode](https://nightworksio.github.io/php-mutation-gate/guide/recipes/pre-push-and-watch/) | Judging a change before you push it |
| [Survivors in your editor](https://nightworksio.github.io/php-mutation-gate/guide/recipes/editors/) | VS Code and PhpStorm |
| [Troubleshooting](https://nightworksio.github.io/php-mutation-gate/guide/troubleshooting/) | What each finding of `doctor` means, and its fix |
| [Writing an adapter](https://nightworksio.github.io/php-mutation-gate/guide/extending/writing-an-adapter/) | Adding a runner, reporter or store from your own package |
| [Command line](https://nightworksio.github.io/php-mutation-gate/reference/cli/) | Every command, option and exit code |
| [Configuration](https://nightworksio.github.io/php-mutation-gate/reference/configuration/) | The config formats and every key |
| [Reports](https://nightworksio.github.io/php-mutation-gate/reference/reports/) | Every report the gate writes |
| [Environment variables](https://nightworksio.github.io/php-mutation-gate/reference/environment/) | Every variable the gate reads |
| [Files](https://nightworksio.github.io/php-mutation-gate/reference/files/) | Every file the gate reads and writes |
| [Decisions](https://nightworksio.github.io/php-mutation-gate/decisions/) | The design, and why |

## Features

*Built* says whether `main` holds the feature.

| | Feature | Built | Decided in |
|---|---------|-------|------------|
| **Adoption** | Zero-config start: trees from `phpunit.xml`'s `<source>`, and an optional config file | yes | [0002](https://nightworksio.github.io/php-mutation-gate/decisions/0002-one-typed-config-from-several-formats/) |
| | `init`: it detects the runner, preset and CI, and writes the config and the CI | yes | [0017](https://nightworksio.github.io/php-mutation-gate/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved/) |
| | `init`'s questions: the runner, the CI, an Infection config's import, native markers, the pre-push hook and `composer mutate`, asked where detection cannot settle them, with `--native`, `--hook`, `--no-hook` and `--dry-run` | yes | [0017](https://nightworksio.github.io/php-mutation-gate/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved/) |
| | `init`'s first-run estimate from one coverage run, with its hot paths, or from lines of code with `--no-measure`; and the trees listed as a comment rather than written | yes; a range needs the benchmark | [0017](https://nightworksio.github.io/php-mutation-gate/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved/) |
| | `doctor`: what would fail or run slowly, and the fix, before a run finds out | yes | [0017](https://nightworksio.github.io/php-mutation-gate/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved/) |
| | A first CI run with no baseline measures, then hands over the baseline to commit | yes | [0017](https://nightworksio.github.io/php-mutation-gate/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved/) |
| | `init --from=infection.json5`: a config taken over from Infection's | yes | [0016](https://nightworksio.github.io/php-mutation-gate/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs/) |
| | `init --ci`: a ready, pinned workflow for GitHub, GitLab, Buildkite, CircleCI, Azure DevOps, Bitbucket Pipelines or Jenkins | yes | [0015](https://nightworksio.github.io/php-mutation-gate/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit/), [0024](https://nightworksio.github.io/php-mutation-gate/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation/) |
| | Floors that only rise: a committed baseline, which fails on regression and rises on improvement | yes | [0003](https://nightworksio.github.io/php-mutation-gate/decisions/0003-a-floor-only-rises/) |
| | Pull-request mode: changed lines and what the change reaches, with a stricter floor for new code | yes | [0003](https://nightworksio.github.io/php-mutation-gate/decisions/0003-a-floor-only-rises/), [0005](https://nightworksio.github.io/php-mutation-gate/decisions/0005-what-a-change-reaches-is-what-is-mutated/) |
| | Monorepos: a floor per package and module, with reach that follows the dependencies | yes | [0005](https://nightworksio.github.io/php-mutation-gate/decisions/0005-what-a-change-reaches-is-what-is-mutated/) |
| | `migrate`: a config and a baseline moved to the current format, in any of the four formats | yes | [0026](https://nightworksio.github.io/php-mutation-gate/decisions/0026-configs-and-baselines-move-forward-with-one-command/) |
| | A signed PHAR and a multi-arch container image beside the Composer package | yes | [0022](https://nightworksio.github.io/php-mutation-gate/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs/) |
| | `init --hook`: the gate in CaptainHook, GrumPHP and the pre-commit framework | yes | [0024](https://nightworksio.github.io/php-mutation-gate/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation/) |
| | `composer mutate`: a root script `init` adds to `composer.json` | yes | [0024](https://nightworksio.github.io/php-mutation-gate/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation/) |
| **CI and speed** | A cost model that learns how long each file takes from earlier shards | yes | [0006](https://nightworksio.github.io/php-mutation-gate/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once/) |
| | Sharding on any CI: GitHub Actions, GitLab, Buildkite, CircleCI, Azure DevOps, Bitbucket Pipelines, Jenkins, or a JSON plan | yes | [0006](https://nightworksio.github.io/php-mutation-gate/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once/), [0024](https://nightworksio.github.io/php-mutation-gate/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation/) |
| | A proof cache keyed by content, stored in the GitHub cache, a directory, S3/R2, Google Cloud Storage or Azure Blob, with OIDC and a public read path for forks | yes | [0007](https://nightworksio.github.io/php-mutation-gate/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads/), [0028](https://nightworksio.github.io/php-mutation-gate/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube/) |
| | Likely killers first: each mutant's tests ordered by which of them killed it before | yes | [0013](https://nightworksio.github.io/php-mutation-gate/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut/) |
| | A shard count chosen from a target wall time | yes | [0013](https://nightworksio.github.io/php-mutation-gate/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut/) |
| | A fork's pull request reads the default branch's proofs, read-only | yes | [0013](https://nightworksio.github.io/php-mutation-gate/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut/) |
| | `affected`: the tests a change can reach, for plain test runs | yes | [0020](https://nightworksio.github.io/php-mutation-gate/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled/) |
| | A static analyser (Mago, PHPStan or Psalm) kills the mutants it rejects, before their tests where that saves time | yes | [0020](https://nightworksio.github.io/php-mutation-gate/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled/) |
| | A new push re-checks the previous survivors first, for a signal within minutes | yes | [0020](https://nightworksio.github.io/php-mutation-gate/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled/) |
| | A sampled mode for huge repositories: each tree estimated with a confidence interval, judged on its bounds | yes | [0020](https://nightworksio.github.io/php-mutation-gate/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled/) |
| | Coverage re-measured only for the tests whose inputs moved | yes | [0023](https://nightworksio.github.io/php-mutation-gate/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured/) |
| | Every core of a shard's runner busy, and one warm worker per core for native runners | yes | [0023](https://nightworksio.github.io/php-mutation-gate/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured/) |
| | Mutators that never let a mutant through are pruned on unchanged code, and audited weekly | yes | [0025](https://nightworksio.github.io/php-mutation-gate/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions/) |
| | Mutants that leave their file alike run once and share one verdict, under Pest and the PHPUnit, PhpSpec and Codeception runners | yes | [0025](https://nightworksio.github.io/php-mutation-gate/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions/) |
| | Merge queues: a `merge_group` run judges what will land, trusting no pull request's own proofs | no | [0022](https://nightworksio.github.io/php-mutation-gate/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs/) |
| **Reporting** | JSON, JUnit and SARIF, and line annotations on GitHub | yes | [0009](https://nightworksio.github.io/php-mutation-gate/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer/) |
| | A useless-test report: tests that cover code and kill none of it | yes | [0014](https://nightworksio.github.io/php-mutation-gate/decisions/0014-every-test-is-judged-by-what-it-kills/) |
| | A redundant-test report: tests whose every kill another test also makes | yes | [0014](https://nightworksio.github.io/php-mutation-gate/decisions/0014-every-test-is-judged-by-what-it-kills/) |
| | A kill-matrix export, as CSV and in the JSON and HTML reports | yes | [0014](https://nightworksio.github.io/php-mutation-gate/decisions/0014-every-test-is-judged-by-what-it-kills/) |
| | Holding tests: Pest `holds:` groups and a `#[Holds]` attribute, a check that a group covers what it holds, and a warning for code every test runs through that nothing holds | yes | [0005](https://nightworksio.github.io/php-mutation-gate/decisions/0005-what-a-change-reaches-is-what-is-mutated/) |
| | Infection as well as Pest | yes | [0004](https://nightworksio.github.io/php-mutation-gate/decisions/0004-pest-and-infection-behind-one-runner-port/) |
| | A native runner for PHPUnit, on the gate's own mutants | yes | [0023](https://nightworksio.github.io/php-mutation-gate/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured/) |
| | Native runners for PhpSpec and Codeception, on the gate's own mutants | yes | [0027](https://nightworksio.github.io/php-mutation-gate/decisions/0027-codeception-phpspec-and-testo-get-native-runners/) |
| | A native runner for Testo | no | [0027](https://nightworksio.github.io/php-mutation-gate/decisions/0027-codeception-phpspec-and-testo-get-native-runners/) |
| | Weak assertions, found and paired with the survivors they let through | yes | [0025](https://nightworksio.github.io/php-mutation-gate/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions/) |
| | A score per test suite | yes | [0025](https://nightworksio.github.io/php-mutation-gate/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions/) |
| | Survivors grouped by code owner, from the CODEOWNERS at the base, with each group's score in the JSON report | yes | [0022](https://nightworksio.github.io/php-mutation-gate/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs/) |
| | Floors per owner, judged over every file CODEOWNERS gives the owner | yes | [0022](https://nightworksio.github.io/php-mutation-gate/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs/) |
| | Owners mentioned once per pull request | no | [0022](https://nightworksio.github.io/php-mutation-gate/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs/) |
| | Survivors with one cause clustered into one item with one suggested test | yes | [0022](https://nightworksio.github.io/php-mutation-gate/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs/) |
| | Survivors in SonarQube's issue list, through its generic external-issues format | yes | [0028](https://nightworksio.github.io/php-mutation-gate/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube/) |
| **Local use** | Watch mode and a pre-push hook | yes | [0010](https://nightworksio.github.io/php-mutation-gate/decisions/0010-the-gate-runs-while-you-work-and-before-you-push/) |
| | A score change before each commit, from a hook that runs nothing and never blocks | yes | [0015](https://nightworksio.github.io/php-mutation-gate/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit/) |
| | Survivors inline in VS Code and PhpStorm | yes | [0015](https://nightworksio.github.io/php-mutation-gate/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit/) |
| | `stub`: a failing Pest or PHPUnit test or PhpSpec example to fill in, for a survivor | yes | [0015](https://nightworksio.github.io/php-mutation-gate/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit/) |
| | One command that reproduces each survivor | yes | [0004](https://nightworksio.github.io/php-mutation-gate/decisions/0004-pest-and-infection-behind-one-runner-port/), [0009](https://nightworksio.github.io/php-mutation-gate/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer/) |
| | For each survivor, what the tests miss | yes | [0009](https://nightworksio.github.io/php-mutation-gate/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer/) |
| | `explain`: a mutant's diff, tests, their outcomes and its history, without running it | yes | [0014](https://nightworksio.github.io/php-mutation-gate/decisions/0014-every-test-is-judged-by-what-it-kills/) |
| **Run control** | A time budget that runs the riskiest code first, and reports anything unjudged instead of passing it | yes | [0008](https://nightworksio.github.io/php-mutation-gate/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first/) |
| | With `run.stopWhenDoomed`, a pull request's shards stop once a survivor of a floor of 100 makes the verdict certain to fail, once static analysis has not cleared it, and say so for a CI to cancel the rest | yes | [0008](https://nightworksio.github.io/php-mutation-gate/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first/) |
| | A kill counts only with evidence: a test named as its killer (one that failed or errored, raised an issue the runner fails the run on, or whose dataset the mutant left empty), or a signal or a fatal error that ended its process; any other is unjudged, with its exit code and output | yes | [0014](https://nightworksio.github.io/php-mutation-gate/decisions/0014-every-test-is-judged-by-what-it-kills/) |
| | A named killer must pass on the unmutated code, served as its mutant was, or the kill is unjudged | yes | [0014](https://nightworksio.github.io/php-mutation-gate/decisions/0014-every-test-is-judged-by-what-it-kills/) |
| | Triage of timeouts and flaky tests: a timeout counts as a kill only where its tests, run unmutated under the same limit, finish within it | yes | [0008](https://nightworksio.github.io/php-mutation-gate/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first/) |
| | A mutant out of the memory cap counts as a kill only where its tests, run unmutated under the same cap, finish under it, measured alike for every runner | yes | [0004](https://nightworksio.github.io/php-mutation-gate/decisions/0004-pest-and-infection-behind-one-runner-port/) |
| | Ignores for equivalent mutants, and by its id for a mutant whose file loads before Pest can put it in place, each with a reason and an optional expiry | yes | [0008](https://nightworksio.github.io/php-mutation-gate/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first/) |
| | Survivors the compiler proves equivalent, left out of the score | yes | [0013](https://nightworksio.github.io/php-mutation-gate/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut/) |
| | Presets for Laravel, Symfony and plain libraries | yes | [0008](https://nightworksio.github.io/php-mutation-gate/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first/) |
| | Custom mutators, written once for Pest and Infection against a typed SDK | yes | [0021](https://nightworksio.github.io/php-mutation-gate/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave/) |
| | Laravel and Symfony mutator sets, turned on by their presets | yes | [0021](https://nightworksio.github.io/php-mutation-gate/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave/) |
| | Security mode: mutators for authorisation, CSRF, escaping and constant-time comparisons, held to a floor of their own | yes | [0021](https://nightworksio.github.io/php-mutation-gate/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave/) |
| | A hint that a surviving removal may be dead code | yes | [0025](https://nightworksio.github.io/php-mutation-gate/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions/) |
| **Visibility** | An HTML report | yes | [0009](https://nightworksio.github.io/php-mutation-gate/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer/) |
| | A digest for agents: the verdict one fact a line, the same verdict as the JSON and HTML reports at a cost in tokens that does not grow with the run, with depth one file, mutant or cluster at a time | yes | [0009](https://nightworksio.github.io/php-mutation-gate/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer/) |
| | A sticky comment on the pull request, posted when the plan is made with the units it mutates, the time it should take and the changed lines no test runs, then updated with the verdict, which links the run's HTML report | yes | [0009](https://nightworksio.github.io/php-mutation-gate/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer/), [0019](https://nightworksio.github.io/php-mutation-gate/decisions/0019-contributor-automation-runs-no-pull-request-content-where-it-can-write/) |
| | A cost estimate in the PR comment: time planned, measured and spared, and money at the team's rate | yes | [0016](https://nightworksio.github.io/php-mutation-gate/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs/) |
| | Chat alerts to Slack, Discord or a webhook when the default branch fails, cannot be judged, or recovers | yes | [0016](https://nightworksio.github.io/php-mutation-gate/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs/) |
| | Run metrics as OpenTelemetry traces and metrics, and in the JSON report | yes | [0016](https://nightworksio.github.io/php-mutation-gate/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs/) |
| | A badge (a shields.io endpoint) and a trend on the default branch | yes | [0009](https://nightworksio.github.io/php-mutation-gate/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer/) |
| | Every run says what it saved against a full run in one job: reach and proofs, and what sharding saved in waiting and cost in runner time | yes | [0017](https://nightworksio.github.io/php-mutation-gate/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved/) |
| | Progress and an ETA during a run | yes | [0017](https://nightworksio.github.io/php-mutation-gate/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved/) |
| | Survivors in GitLab's Code Quality report | yes | [0024](https://nightworksio.github.io/php-mutation-gate/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation/) |
| | The sticky comment and its planned state on GitLab merge requests and Bitbucket pull requests, with survivors in Bitbucket's Code Insights | yes | [0024](https://nightworksio.github.io/php-mutation-gate/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation/), [0048](https://nightworksio.github.io/php-mutation-gate/decisions/0048-the-gitlab-and-bitbucket-comments-relay-the-delivery-and-bitbucket-finds-its-own-by-editing/) |
| | A benchmark against plain Pest and Infection on four open-source projects: cold, warm and per pull request, losses included | yes, in `bench.yml`, with the sharded arm and `composer bench`; no result is committed, so the [table](#performance) shows none | [0017](https://nightworksio.github.io/php-mutation-gate/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved/) |
| | An organisation dashboard: a static site of every repository's trends, floors and savings | yes | [0024](https://nightworksio.github.io/php-mutation-gate/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation/) |

## Performance

The [benchmark](https://nightworksio.github.io/php-mutation-gate/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved/)
runs the gate against plain Infection and plain Pest on four pinned
open-source projects, with `bench.yml`: a cold full run, a warm full run, a
replay of each project's recent pull requests and an unchanged re-run, every
arm three times on one GitHub-hosted machine, and the gate sharded across
machines. Every scenario is shown, those where the gate is slower too.
`composer bench -- <project>` runs the same harness here.

<!-- bench:start -->

No benchmark result is committed. `bench.yml` measures one, and a pull request brings it here.

<!-- bench:end -->

## Its own gate

php-mutation-gate gates its own pull requests through its reusable workflow,
and its default branch publishes the badge above and this trend:

<p align="center">
  <img alt="php-mutation-gate's mutation score on its default branch, run by run" src="https://raw.githubusercontent.com/nightworksio/php-mutation-gate/mutation-gate/trend.svg" width="480">
</p>

## Contributing and security

- [CONTRIBUTING.md](https://github.com/nightworksio/php-mutation-gate/blob/main/CONTRIBUTING.md) says what a contribution needs and how
  to run the checks locally. [ARCHITECTURE.md](https://github.com/nightworksio/php-mutation-gate/blob/main/ARCHITECTURE.md) is the
  structure every change keeps to.
- Ask a question in the [Q&A category of Discussions](https://github.com/nightworksio/php-mutation-gate/discussions/categories/q-a).
- Report a vulnerability privately, as [SECURITY.md](https://github.com/nightworksio/php-mutation-gate/blob/main/SECURITY.md) says.
- Everyone here keeps the [code of conduct](https://github.com/nightworksio/php-mutation-gate/blob/main/CODE_OF_CONDUCT.md).

## Licence

MIT. See [LICENSE](https://github.com/nightworksio/php-mutation-gate/blob/main/LICENSE). php-mutation-gate is made by
[NightWorksIO](https://nightworks.io).
