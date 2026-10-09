<picture>
  <source media="(prefers-color-scheme: dark)" srcset=".docs/brand/mark-dark.svg">
  <source media="(prefers-color-scheme: light)" srcset=".docs/brand/mark-light.svg">
  <img alt="mutation-gate" src=".docs/brand/mark-light.svg" height="48">
</picture>

# mutation-gate

mutation-gate turns mutation testing into a check your pull requests must pass.
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

> **Not released yet.** No version is tagged and nothing is on Packagist, so
> install it from this repository, as the quick start shows. The first
> release is 1.0.0, tagged when every feature in the [table](#features) is
> built.

## Requirements

- PHP 8.5 or a later 8.x, with pcov or Xdebug for coverage.
- One of the runners:
  - **Pest**: `pestphp/pest` ^5.1 with `pestphp/pest-plugin-mutate` ^5.0, on
    the PHPUnit 13 release Pest pins;
  - **Infection**: `infection/infection` ~0.35.0, with PHPUnit 12 or 13;
  - **PHPUnit** on its own: `phpunit/phpunit` 13.2 or later.

  Infection and the PHPUnit runner do not run Pest suites, so a Pest project
  uses Pest's own mutation testing.

Optional packages, each needed only for what it enables:

- `symfony/yaml` for a YAML config;
- `nette/neon` for a NEON config;
- `async-aws/s3` for proofs kept in S3 or R2.

## Quick start

Add this repository to your project's Composer repositories, and require the
gate from its `main` branch:

```sh
composer config repositories.mutation-gate vcs https://github.com/nightworksio/php-mutation-gate
composer require --dev "nightworksio/mutation-gate:dev-main" -W
```

`-W` lets Composer move `pestphp/pest-plugin-mutate` to the release the gate
has tested, which it pins exactly. To pin a commit instead of following
`main`, require `dev-main#<commit>`.

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
    No test uses a value at the boundary of `$amount < $limit`. It is judged by `MoneyTest::fits`, `MoneyTest::refuses`, `PriceTest::adds` and 1 more.
    Reproduce: vendor/bin/mutation-gate reproduce 3f9a1c2b7d04
    Explain: vendor/bin/mutation-gate explain 3f9a1c2b7d04
```

The first local run writes `mutation-gate.baseline.json`, holding each tree's
floor at the score it measured. Commit it. From then on a score may not fall
below its floor, and `vendor/bin/mutation-gate baseline --write` raises the
floors when scores improve. `vendor/bin/mutation-gate doctor` says what would
fail or run slowly before a run finds out.

## In CI

A CI run plans the work, mutates each shard in parallel, and judges the
result in a verdict job, which is the check to require.
`vendor/bin/mutation-gate init --ci` writes the definition for GitLab CI,
Buildkite, CircleCI, Azure DevOps, Bitbucket Pipelines or Jenkins, and one
for GitHub Actions that does not run yet. [Running the gate in
CI](.docs/guide/ci/README.md) has a page for each, and the commands for any
other CI.

## Documentation

| Read | For |
|------|-----|
| [Running the gate in CI](.docs/guide/ci/README.md) | A page per CI, and what every setup needs |
| [Proofs and trust](.docs/guide/concepts/proofs-and-trust.md) | Where proofs are kept, and keeping pull requests from writing what the default branch trusts |
| [Holding tests](.docs/guide/concepts/holding-tests.md) | Mutating code every test runs through against the tests that hold it |
| [Pre-push hook and watch mode](.docs/guide/recipes/pre-push-and-watch.md) | Judging a change before you push it |
| [Survivors in your editor](.docs/guide/recipes/editors.md) | VS Code and PhpStorm |
| [Troubleshooting](.docs/guide/troubleshooting.md) | Every refusal, failure and warning the gate prints |
| [Writing an adapter](.docs/guide/extending/writing-an-adapter.md) | Adding a runner, reporter or store from your own package |
| [Command line](.docs/reference/cli.md) | Every command, option and exit code |
| [Configuration](.docs/reference/configuration.md) | The config formats and every key |
| [Reports](.docs/reference/reports.md) | Every report the gate writes |
| [Environment variables](.docs/reference/environment.md) | Every variable the gate reads |
| [Files](.docs/reference/files.md) | Every file the gate reads and writes |
| [Decisions](.docs/decisions/README.md) | The design, and why |

## Features

The first release is tagged when every row says *yes*.

| | Feature | Built | Decided in |
|---|---------|-------|------------|
| **Adoption** | Zero-config start: trees from `phpunit.xml`'s `<source>`, and an optional config file | yes | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| | `init`: it detects the runner, preset and CI, and writes the config and the CI | yes | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | `init`'s questions: the runner, the CI, an Infection config's import, native markers, the pre-push hook and `composer mutate`, asked where detection cannot settle them, with `--native`, `--hook`, `--no-hook` and `--dry-run` | yes | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | `init`'s first-run estimate from one coverage run, with `--no-measure`; and the trees listed as a comment rather than written | not yet | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | `doctor`: what would fail or run slowly, and the fix, before a run finds out | yes | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | A first CI run with no baseline measures, then hands over the baseline to commit | yes | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | `init --from=infection.json5`: a config taken over from Infection's | yes | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| | `init --ci`: a ready, pinned workflow for GitHub, GitLab, Buildkite, CircleCI, Azure DevOps, Bitbucket Pipelines or Jenkins | yes; the GitHub workflows use an action that is not on `main` | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md), [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| | Floors that only rise: a committed baseline, which fails on regression and rises on improvement | yes | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| | Pull-request mode: changed lines and what the change reaches, with a stricter floor for new code | yes | [0003](.docs/decisions/0003-a-floor-only-rises.md), [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| | Monorepos: a floor per package and module, with reach that follows the dependencies | yes | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| | `migrate`: a config and a baseline moved to the current format, in any of the four formats | yes | [0026](.docs/decisions/0026-configs-and-baselines-move-forward-with-one-command.md) |
| | A signed PHAR and a multi-arch container image beside the Composer package | not yet | [0022](.docs/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| | `init --hook`: the gate in CaptainHook, GrumPHP and the pre-commit framework | yes | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| | `composer mutate`, from an optional Composer plugin | not yet: the plugin is in `plugins/composer`, and is not published | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| **CI and speed** | A cost model that learns how long each file takes from earlier shards | yes | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| | Sharding on any CI: GitHub Actions, GitLab, Buildkite, CircleCI, Azure DevOps, Bitbucket Pipelines, Jenkins, or a JSON plan | yes | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| | A proof cache keyed by content, stored in the GitHub cache, a directory, S3/R2, Google Cloud Storage or Azure Blob, with OIDC and a public read path for forks | yes | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md), [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| | Likely killers first: each mutant's tests ordered by which of them killed it before | yes | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| | A shard count chosen from a target wall time | yes | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| | A fork's pull request reads the default branch's proofs, read-only | yes | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| | `affected`: the tests a change can reach, for plain test runs | yes | [0020](.docs/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| | A static analyser (Mago, PHPStan or Psalm) kills the mutants it rejects, before their tests where that saves time | yes | [0020](.docs/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| | A new push re-checks the previous survivors first, for a signal within minutes | yes | [0020](.docs/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| | A sampled mode for huge repositories: each tree estimated with a confidence interval, judged on its bounds | not yet | [0020](.docs/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| | Coverage re-measured only for the tests whose inputs moved | yes | [0023](.docs/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| | Every core of a shard's runner busy, and one warm worker per core for native runners | yes | [0023](.docs/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| | Mutators that never let a mutant through are pruned on unchanged code, and audited weekly | yes | [0025](.docs/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| | Mutants that leave their file alike run once and share one verdict, under Pest and the PHPUnit runner | yes | [0025](.docs/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| | Merge queues: a `merge_group` run judges what will land, trusting no pull request's own proofs | not yet | [0022](.docs/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| **Reporting** | JSON, JUnit and SARIF, and line annotations on GitHub | yes | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | A useless-test report: tests that cover code and kill none of it | yes | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| | A redundant-test report: tests whose every kill another test also makes | yes | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| | A kill-matrix export, as CSV and in the JSON and HTML reports | yes | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| | Holding tests: Pest `holds:` groups and a `#[Holds]` attribute, a check that a group covers what it holds, and a warning for code every test runs through that nothing holds | yes | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| | Infection as well as Pest | yes | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| | A native runner for PHPUnit, on the gate's own mutants | yes | [0023](.docs/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| | Native runners for Codeception, PhpSpec and Testo | not yet | [0027](.docs/decisions/0027-codeception-phpspec-and-testo-get-native-runners.md) |
| | Weak assertions, found and paired with the survivors they let through | yes | [0025](.docs/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| | A score per test suite | yes | [0025](.docs/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| | Survivors grouped by code owner, with owners mentioned and floors per owner | not yet | [0022](.docs/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| | Survivors with one cause clustered into one item with one suggested test | yes | [0022](.docs/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| | Survivors in SonarQube's issue list, through its generic external-issues format | yes | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| **Local use** | Watch mode and a pre-push hook | yes | [0010](.docs/decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| | A score change before each commit, from a hook that runs nothing and never blocks | yes | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| | Survivors inline in VS Code and PhpStorm | yes | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| | `stub`: a failing Pest or PHPUnit test to fill in, for a survivor | yes | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| | One command that reproduces each survivor | yes | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md), [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | For each survivor, what the tests miss | yes | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | `explain`: a mutant's diff, tests, their outcomes and its history, without running it | yes | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| **Run control** | A time budget that runs the riskiest code first, and reports anything unjudged instead of passing it | yes | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | A pull request's shards stop once a survivor of a floor of 100 makes the verdict certain to fail, once static analysis has not cleared it, and say so for a CI to cancel the rest | yes | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | A kill counts only with evidence: a test named as its killer (one that failed or errored, raised an issue the runner fails the run on, or whose dataset the mutant left empty), or a signal or a fatal error that ended its process; any other is unjudged, with its exit code and output | yes | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| | A named killer must pass on the unmutated code, served as its mutant was, or the kill is unjudged | yes | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| | Triage of timeouts and flaky tests: a timeout counts as a kill only where its tests, run unmutated under the same limit, finish within it | yes | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | A mutant out of the memory cap counts as a kill only where its tests, run unmutated under the same cap, finish under it, measured alike for every runner | yes | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| | Ignores for equivalent mutants, and by its id for a mutant whose file loads before Pest can put it in place, each with a reason and an optional expiry | yes | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Survivors the compiler proves equivalent, left out of the score | yes | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| | Presets for Laravel, Symfony and plain libraries | yes | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Custom mutators, written once for Pest and Infection against a typed SDK | yes | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| | Laravel and Symfony mutator sets, turned on by their presets | yes | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| | Security mode: mutators for authorisation, CSRF, escaping and constant-time comparisons, held to a floor of their own | yes | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| | A hint that a surviving removal may be dead code | yes | [0025](.docs/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| **Visibility** | An HTML report | yes | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | A sticky comment on the pull request, posted when the plan is made with the units it mutates, the time it should take and the changed lines no test runs, then updated with the verdict | yes | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md), [0019](.docs/decisions/0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md) |
| | A cost estimate in the PR comment: time planned, measured and spared, and money at the team's rate | yes | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| | Chat alerts to Slack, Discord or a webhook when the default branch fails, cannot be judged, or recovers | yes | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| | Run metrics as OpenTelemetry traces and metrics, and in the JSON report | yes | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| | A badge (a shields.io endpoint) and a trend on the default branch | yes | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | Every run says what it saved against a full run in one job: reach and proofs, and what sharding saved in waiting and cost in runner time | yes | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | Progress and an ETA during a run | yes | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | Survivors in GitLab's Code Quality report | yes | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| | The sticky comment and its planned state on GitLab merge requests and Bitbucket pull requests, with survivors in Bitbucket's Code Insights | not yet | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| | A benchmark against plain Pest and Infection on four open-source projects: cold, warm and per pull request, losses included | `lcobucci/jwt` against plain Infection and `pinkary.com`'s `app/Actions` against plain Pest, cold and full, in `bench.yml`; not yet the other two projects, the warm and pull request scenarios and the sharded arm | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | An organisation dashboard: a static site of every repository's trends, floors and savings | not yet | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |

## Contributing and security

- [CONTRIBUTING.md](CONTRIBUTING.md) says what a contribution needs and how
  to run the checks locally. [ARCHITECTURE.md](ARCHITECTURE.md) is the
  structure every change keeps to.
- Report a vulnerability privately, as [SECURITY.md](SECURITY.md) says.
- Everyone here keeps the [code of conduct](CODE_OF_CONDUCT.md).

## Licence

MIT. See [LICENSE](LICENSE). mutation-gate is made by
[NightWorksIO](https://nightworks.io).
