<picture>
  <source media="(prefers-color-scheme: dark)" srcset=".docs/brand/mark-dark.svg">
  <source media="(prefers-color-scheme: light)" srcset=".docs/brand/mark-light.svg">
  <img alt="mutation-gate" src=".docs/brand/mark-light.svg" height="48">
</picture>

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
Infection. For PHPUnit, Codeception, PhpSpec and Testo on their own, the gate
makes the mutants itself.

Each tree of your code has a floor, the lowest score it may have. The floor
only rises. New code has a floor of its own, 100 by default. A pull request
mutates what it reaches, and every surviving mutant arrives with the one command
that reproduces it and a sentence saying what the tests miss.

## What it does

| | Feature | Decided in |
|---|---------|------------|
| **Adoption** | Zero-config start: trees from `phpunit.xml`'s `<source>`, and an optional config file | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| | A guided `init`: it detects the runner, preset and CI, asks only what it cannot tell, writes the config and the CI, and estimates the first run from one coverage run | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | `doctor`: what would fail or run slowly, and the fix, before a run finds out | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | A first CI run with no baseline measures, then hands over the baseline to commit | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | `init --from=infection.json5`: a config taken over from Infection's | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| | `init --ci`: a ready, pinned workflow for GitHub, GitLab, Buildkite or CircleCI | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| | Floors that only rise: a committed baseline, which fails on regression and rises on improvement | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| | Pull-request mode: changed lines and what the change reaches, with a stricter floor for new code | [0003](.docs/decisions/0003-a-floor-only-rises.md), [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| | Monorepos: a floor per package and module, with reach that follows the dependencies | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| | `migrate`: a config and a baseline moved to the current format, in any of the four formats | [0026](.docs/decisions/0026-configs-and-baselines-move-forward-with-one-command.md) |
| | A signed PHAR and a multi-arch container image beside the Composer package | [0022](.docs/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| | `composer mutate`, and recipes for CaptainHook, GrumPHP and the pre-commit framework | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| **CI and speed** | A cost model that learns how long each file takes from earlier shards | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| | Sharding on any CI: GitHub Actions, GitLab, Buildkite, CircleCI, or a JSON plan | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| | A proof cache keyed by content, stored in the GitHub cache, a directory or S3/R2 | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| | Likely killers first: each mutant's tests ordered by which of them killed it before | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| | A shard count chosen from a target wall time | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| | A fork's pull request reads the default branch's proofs, read-only | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| | `affected`: the tests a change can reach, for plain test runs | [0020](.docs/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| | A static analyser (Mago, PHPStan or Psalm) kills the mutants it rejects, before their tests where that saves time | [0020](.docs/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| | A new push re-checks the previous survivors first, for a signal within minutes | [0020](.docs/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| | A sampled mode for huge repositories: each tree estimated with a confidence interval, judged on its bounds | [0020](.docs/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| | Coverage re-measured only for the tests whose inputs moved | [0023](.docs/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| | Every core of a shard's runner busy, and one warm worker per core for native runners | [0023](.docs/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| | Mutators that never let a mutant through are pruned on unchanged code, and audited weekly | [0025](.docs/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| | Merge queues: a `merge_group` run judges what will land, trusting no pull request's own proofs | [0022](.docs/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| | Sharding on Bitbucket Pipelines, Azure DevOps and Jenkins | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| | Proofs in Google Cloud Storage or Azure Blob, with OIDC and a public read path for forks | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| **Reporting** | JSON, JUnit and SARIF, and line annotations on GitHub | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | A useless-test report: tests that cover code and kill none of it | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| | A redundant-test report: tests whose every kill another test also makes | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| | A kill-matrix export, as CSV and in the JSON and HTML reports | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| | Holding tests: Pest `holds:` groups and a `#[Holds]` attribute, a check that a group covers what it holds, and a warning for code every test runs through that nothing holds | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| | Infection as well as Pest | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| | Native runners for PHPUnit, Codeception, PhpSpec and Testo, on the gate's own mutants | [0023](.docs/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md), [0027](.docs/decisions/0027-codeception-phpspec-and-testo-get-native-runners.md) |
| | Weak assertions, found and paired with the survivors they let through | [0025](.docs/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| | A score per test suite | [0025](.docs/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| | Survivors grouped by code owner, with owners mentioned and floors per owner | [0022](.docs/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| | Survivors with one cause clustered into one item with one suggested test | [0022](.docs/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| | A SonarQube report of survivors, in Sonar's generic external-issues format | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| **Local use** | Watch mode and a pre-push hook | [0010](.docs/decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| | A score change before each commit, from a hook that runs nothing and never blocks | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| | Survivors inline in VS Code and PhpStorm | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| | `stub`: a failing Pest or PHPUnit test to fill in, for a survivor | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| | One command that reproduces each survivor | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md), [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | For each survivor, what the tests miss | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | `explain`: a mutant's diff, tests, their outcomes and its history, without running it | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| **Run control** | A time budget that runs the riskiest code first, and reports anything unjudged instead of passing it | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Triage of timeouts and flaky tests | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Ignores for equivalent mutants, each with a reason and an optional expiry | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Survivors the compiler proves equivalent, left out of the score | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| | Presets for Laravel, Symfony and plain libraries | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Custom mutators, written once for Pest and Infection against a typed SDK | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| | Laravel and Symfony mutator sets, turned on by their presets | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| | Security mode: mutators for authorisation, CSRF, escaping and constant-time comparisons, held to a floor of their own | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| | A hint that a surviving removal may be dead code | [0025](.docs/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| **Visibility** | An HTML report | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | A sticky comment on the pull request, posted when the plan is made with the units it mutates, the time it should take and the changed lines no test covers, then updated with the verdict | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md), [0019](.docs/decisions/0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md) |
| | A cost estimate in the PR comment: time planned, measured and spared, and money at the team's rate | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| | Chat alerts to Slack, Discord or a webhook when the default branch fails, cannot be judged, or recovers | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| | Run metrics as OpenTelemetry traces and metrics, and in the JSON report | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| | A badge (a shields.io endpoint) and a trend on the default branch | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | Every run says what it saved against a full run in one job: reach and proofs, and what sharding saved in waiting and cost in runner time | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | Progress and an ETA during a run | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | A benchmark against plain Pest and Infection on four open-source projects: cold, warm and per pull request, losses included | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | The sticky comment and its planned state on GitLab merge requests and Bitbucket pull requests, with survivors in GitLab's Code Quality report and Bitbucket's Code Insights | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| | An organisation dashboard: a static site of every repository's trends, floors and savings | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |

## Install

```sh
composer require --dev nightworksio/mutation-gate -W
```

`-W` lets Composer move `pestphp/pest-plugin-mutate` to the release the gate
has tested, which its `conflict` pins exactly.

Requirements:

- PHP 8.5 or a later 8.x, with pcov or Xdebug for coverage.
- One of the two runners:
  - **Pest**: `pestphp/pest` ^5.1 with `pestphp/pest-plugin-mutate` ^5.0, on
    the PHPUnit 13 release Pest pins;
  - **Infection**: `infection/infection` ~0.35.0, with PHPUnit 12 or 13. The
    adapter reads the project's `infection.json5` and PHPUnit's XML coverage
    with `colinodell/json5` and `ext-dom`, which Infection itself requires.

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

- **trees**: from the `<source>` of `phpunit.xml` (or `phpunit.dist.xml`, or
  `phpunit.xml.dist`);
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
raises the floor. In CI, a tree with no floor at all fails the run with exit
code 2, after mutating: the step summary and the PR comment carry the baseline
it measured, ready to commit.

`vendor/bin/mutation-gate init` sets a project up in one command, and
`vendor/bin/mutation-gate doctor` says what would fail or run slowly before a
run does.

## Commands

| Command | What it does |
|---------|--------------|
| `mutation-gate` or `mutation-gate run` | Plan, run and judge in one process. With `--changed-since=<ref>`, only what the change reaches. With `--budget=<duration>`, the riskiest code first, within that time. |
| `plan` | Work out the reach, drop proved units, cut shards and print the plan for a CI (`--ci=github\|gitlab\|buildkite\|circleci\|json`, or `--shards=<n>` for a fixed count) |
| `run --plan=<file> [--shard=<id>]` | Mutate one shard: the one `--shard` names, or the one the CI's environment names |
| `verdict --plan=<file> --results=<dir>` | Merge every shard's results, judge the floors, write reports and the ledger |
| `baseline [--write]` | Show, or write, floors raised to what was measured |
| `reproduce <id>` | Run one mutant again and show why it survives |
| `explain <id> [--format=text\|json]` | Show one mutant's diff, hint, covering tests and their outcomes, and its history, from the ledgers, running nothing; the id may be a unique prefix of 6 or more |
| `tests` | Print the useless-test and redundant-test report from the ledgers, running nothing |
| `triage <path> [--repeat=<n>] [--order=runner\|killers-first]` | Run a unit n times (5 by default) and list every mutant whose result varied, with each mutant's tests in the order `--order` names (`tests.order` by default) |
| `watch` | Re-judge what each save reaches |
| `pre-push` | Judge the commits being pushed, as CI will, after printing each reached tree's score change |
| `pre-commit` | Print each reached tree's score change from the local ledger; runs nothing and always exits 0 |
| `hook install [--pre-commit]` / `hook uninstall` | Add or remove the pre-push hook, and with `--pre-commit` the pre-commit hook too |
| `init [--format=php\|json\|yaml\|neon] [--ci[=github\|gitlab\|buildkite\|circleci]] [--editor=vscode]` | Detect the runner, preset, trees, CI, an Infection config and native markers, and ask only what detection cannot settle; write a config holding the runner, the preset and the answers (PHP by default, or the file `--config` names, in the format of its extension), with the trees it found as a comment, and add `.mutation-gate/` to `.gitignore`; with `--ci`, a pinned CI definition (`--ci` alone takes the detected CI), and with `--editor`, VS Code's watch task, each only where none exists; then print the first run's estimate |
| `init --from[=<file>]` or `import [<file>]` | Write a config from an Infection config, the file named or the one Infection itself would read, over what zero-config found; say of each of its keys whether it was imported, stays in that file or was dropped, and which keys to delete |
| `doctor [--measure] [--online] [--format=text\|json]` | Report what would fail, run slowly or deserves attention, each with its fix, reading only files and earlier runs; exits 1 when something would fail |
| `stub <id>` | Print a failing Pest or PHPUnit test for a survivor or an uncovered mutant, in the style of its nearest covering test |
| `config:show [--format=…]` / `config:schema` | Print the effective config (JSON by default), or the JSON Schema |
| `pest:patch` | Apply the optional Pest patches ([ADR-0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md)) |

Options:

| Option | Accepted by | What it does | Decided in |
|--------|-------------|--------------|------------|
| `--config=<path>` | every command | Read this config file instead of looking for one | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `--no-extensions` | every command | Load only the package's own adapters | [0001](.docs/decisions/0001-a-framework-free-core-behind-nine-ports.md) |
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
| `--output=problems` | `run`, `watch`, `pre-push` | Print one `<path>:<line>:<col>: <severity>: <message> [<rule>] <id>` line per result, for editors | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `--only=changed` | `run`, `watch`, `pre-push` with `--output=problems` | Print only the mutants on changed lines | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `--write` | `stub` | Append the stub to its nearest covering test file, or create one; never overwrite | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `--style=pest\|phpunit` | `stub` | The stub's style, instead of the nearest covering test's | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `--stdout` | `init` with `--ci` or `--editor` | Print the files instead of writing them | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `--sharded`, `--single` | `init --ci=github` | The reusable workflow or the one-step action, instead of the one the estimated cost picks | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `--native=allow\|refuse` | `init` | Answer the native-markers question: write `ignores.native` | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `--hook`, `--no-hook` | `init` | Answer the pre-push hook question | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `--no-measure` | `init` | Estimate the first run from lines of code, without the coverage run | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `--dry-run` | `init` | Print every file instead of writing it | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `--measure` | `doctor` | Add one coverage run: a green suite, a working driver and the hot paths | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `--online` | `doctor` | Also read GitHub's settings with the token: the required verdict, the fork approval policy, the schedule | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `--kill-matrix=first\|full` | `run` | `full` records every test that kills each mutant, for the redundant-test report (Pest only) | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |
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

Every path and glob a config file writes is named from the file's own
directory, and one that goes up out of the project, or is absolute, is
refused
([ADR-0002](.docs/decisions/0002-one-typed-config-from-several-formats.md)).
Only the command line names a file outside the project, by its absolute
path: `--report json:/tmp/mutation.json`.

A setting that names an adapter takes either a registered name (`"pest"`,
`"sarif"`) or a class with its options (`{"use": "Acme\\Gate\\SlackReporter",
"with": {"channel": "#ci"}}`). A `use` with a backslash is a class, so a class
in the global namespace is written `"\\SlackReporter"`. The adapter reads its
options through `NightWorksIO\MutationGate\Core\Config\Options`, one key at a
time as a type: `$options->text(Key::of('channel'))` answers the text,
`NotGiven`, or the problem at `channel`. `$options->path(Key::of('cache'))`
names a path from the config file's directory, as the gate names its own,
and answers a path that lands outside the project as a problem. Packages that offer adapters are found through
`extra.mutation-gate.extensions` in their `composer.json`
([ADR-0001](.docs/decisions/0001-a-framework-free-core-behind-nine-ports.md)).
An extension's `Extensions` registry is made with the `Origin` of its package,
`NightWorksIO\MutationGate\Core\Registry\Origin`. A config loader an
extension registers decodes its format into JSON and reads it with
`ConfigFile::read()`, so the gate's own definition judges every file, and
`ConfigLoaderContract::failures()` holds it to what every loader answers. It
reads one fixture of each case in the loader's format, from a directory the
extension names: `valid`, `invalid`, `dated`, `up`, `adapter` and `broken`,
and `unquoted` where the format can write a number as a mutant id. Its
docblock says what each one writes. A
preset an extension registers is a layer of config, such as
`Gate::configure()->…->layer(ProjectRoot::origin())` builds
([ADR-0002](.docs/decisions/0002-one-typed-config-from-several-formats.md)).

### Reports

Every report renders the same verdict
([ADR-0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md)).
Each reporter is registered by a name:

| Name | When it runs | What it writes |
|------|--------------|----------------|
| `console` | Always | The verdict, each tree and new-code set, the units, the reach, every mutant counted as not killed with its diff, judging tests, hint, and reproduce and explain commands, the ignores, the mutants proven equivalent, the floors that can rise, failures and warnings, and last what a timed run took and saved |
| `json` | Listed in `reports` | The gate's own report at `path`, `"format": 1`, described by [`resources/report.schema.json`](resources/report.schema.json); every score and floor in it is a percentage with at most two decimals, truncated; the tests are listed once, and each mutant points at those that cover and killed it; a timed run adds its `run`, `cost` and `savings` |
| `junit` | Listed in `reports` | JUnit XML at `path`: a suite per tree with a `floor` test case, a `new code` suite, and a `run` suite for failures no floor decides |
| `sarif` | Listed in `reports` | SARIF 2.1.0 at `path`, for code scanning; with `CI` unset it also names the repository's root as a `file://` URI, for an editor's SARIF viewer |
| `html` | Listed in `reports` | `mutation-report.json` and a self-contained `index.html` under the `path` directory, shown with Stryker's viewer |
| `gitlab` | Listed in `reports` | GitLab's Code Quality JSON at `path`: an issue per mutant counted as not killed, `major` in a set that failed and `minor` otherwise |
| `kill-matrix` | Listed in `reports` | CSV at `path`: a record per mutant and covering test, with what the test did with the mutant in place |
| `tests` | Listed in `reports` | JSON at `path`, described by [`resources/tests.schema.json`](resources/tests.schema.json), and Markdown beside it: the tests that kill nothing they judged, those never the first to kill, and, from a full kill matrix, those that can go together without losing a kill |
| `problems` | With `--output=problems` | One `<path>:<line>:<col>: <error\|warning>: <message> [<rule>] <id>` line per result, between `mutation-gate: judging` and `mutation-gate: judged`, for an editor's problem matcher |
| `slack` | In CI, on the default branch, when its state changes | A Slack message to the URL `MUTATION_GATE_SLACK_URL` holds, or the variable `with: {urlEnv: …}` names: the change, the trees below their floor, the failures, up to five survivors, and the run |
| `discord` | In CI, on the default branch, when its state changes | The same as one Discord embed to the URL `MUTATION_GATE_DISCORD_URL` holds, red, green or grey, mentioning no one |
| `webhook` | In CI, on the default branch, when its state changes | JSON described by [`resources/webhook.schema.json`](resources/webhook.schema.json) to the URL `MUTATION_GATE_WEBHOOK_URL` holds, signed in `X-Mutation-Gate-Signature` ([verifying it](#verifying-a-webhook)) where `MUTATION_GATE_WEBHOOK_SECRET`, or the variable `with: {secretEnv: …}` names, holds a secret |
| `otlp` | Listed in `reports` | The run's trace (`plan`, each `shard <n>` with its `opening run` and `mutate`, and `verdict`) and the verdict's metrics as OTLP/HTTP JSON, to `/v1/traces` and `/v1/metrics` under `with: {endpoint: …}` or `OTEL_EXPORTER_OTLP_ENDPOINT`, with `OTEL_EXPORTER_OTLP_HEADERS`, `OTEL_SERVICE_NAME` and `OTEL_RESOURCE_ATTRIBUTES` |
| `github-annotations` | Under GitHub Actions | Up to 10 error, 10 warning and 10 notice annotations, changed lines first |
| `github-summary` | Under GitHub Actions | The step summary: what a timed run took and saved, what the default branch saved over 30 days, and every mutant counted as not killed in one table |
| `github-comment` | On a pull request, with `GITHUB_TOKEN` | One sticky comment, updated in place, with what a timed run took and saved under the verdict and what it cost folded at the end; `with: {identity: …}` names the account it is found by when the token is not `GITHUB_TOKEN` |
| `badge` | In CI, on the default branch | `badge.json`, `trend.json`, `trend.svg` and `savings.json` in `--publish-dir` |

Every mutant the score counts as not killed carries its reproduce command,
`vendor/bin/mutation-gate reproduce <id>`, and a sentence saying what the
tests miss. The console, JSON and HTML reports also give
`vendor/bin/mutation-gate explain <id>`. A survivor proven equivalent
([ADR-0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md))
is left out of the score and listed as *equivalent, proven*.

### Configuration reference

Every key, with its type, its default and the decision that sets it. Durations
are written `90s`, `15m` or `1h30m`, and dates `YYYY-MM-DD`. A key that
chooses an adapter takes a registered name or `{"use": <name or class>,
"with": <options>}`. A glob of paths is matched against the whole path: `*`
and `?` match within one directory, and `**` across any number of them.

| Key | Type | Default | Decided in |
|-----|------|---------|------------|
| `extensions` | list of class names | `[]` | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `preset` | a preset name, or a list of them: `library`, `laravel`, `symfony` | chosen from `composer.json` | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `runner` | adapter: `pest`, `infection` | the one installed | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `runner.withhold` | list of environment-variable names or globs the runner never hands the project's tests, added to those every run withholds | `[]` | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `treeSource` | adapter: `phpunit`, `composer` | `phpunit` | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `treeSource.with.fallback` | list of paths, the trees when `phpunit.xml` has no `<source>` | `[]`, or the preset's; `[]` takes the `autoload` paths of `composer.json` | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `trees` | list of `{path, floor, reason}` | the tree source's trees | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `trees[].floor` | number, 0 to 100 | the nearest manifest's, if any | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `trees[].reason` | string | none; required when `floor` is 0, and refused beside any other | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `trees[].exclude` | list of globs, each matching a file in the tree | `[]` | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `newCode.floor` | number, 0 to 100 | `100` | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `uncovered` | `count` or `exclude` | `count` | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `baseline.path` | path | `mutation-gate.baseline.json` | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `baseline.improvement` | `require` or `report` | `require` | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `packages` | list of globs | `[]` | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `reach.everything` | list of globs, from the repository's root | `[]`, plus the preset's | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `holds.hotPath` | number, 0 to 1 | `0.8` | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `shards.seconds` | integer | `600` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `shards.max` | integer | `20` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `shards.target` | duration; replaces `shards.seconds`, and setting both is an error | none | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `shards.setup` | duration: each shard's CI setup before the gate starts | `1m` | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `costs.secondsPerLine` | map of path prefix to number | `{"": 0.2}` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `costs.perRunnerMinute` | `{amount, currency}` | none | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `ci.plan` | adapter: `github`, `gitlab`, `buildkite`, `circleci`, `json` | detected from the environment | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.defaultBranch` | branch name | the CI's answer, else git's `origin/HEAD`, else `main` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.check` | the check-run name the verdict reports under | `mutation / verdict` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `ci.gitlab.template` | path | `.gitlab/mutation-gate.yml` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.buildkite.step` | map of step keys | `{}` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.buildkite.definition` | path of the pipeline file that runs the gate under Buildkite | `.buildkite/pipeline.yml` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `proofs.store` | adapter: `directory`, `s3` | `directory` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.path` (`directory`) | path | `.mutation-gate/ledger` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.bucket` (`s3`) | string | none; required | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.prefix` (`s3`) | string | `mutation-gate` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.region` (`s3`) | string | `us-east-1` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.endpoint` (`s3`) | `http://` or `https://` URL | AWS's own | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
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
| `reports` | list of `{use, path, with}`; built-in `json`, `junit`, `sarif`, `html`, `tests`, `kill-matrix`, `gitlab`, and without a `path` `slack`, `discord`, `webhook`, `otlp` | `[]` | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `badge.colors` | map of shields.io colour to lowest score | `{"brightgreen": 90, "green": 80, "yellow": 70, "orange": 60}`, red below | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `pest.patch` | boolean | `false` | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `pest.canary` | group name, with no whitespace | `mutation-canary` | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `local.watchBudget` | duration | `1m` | [0010](.docs/decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| `local.prePushBudget` | duration | `5m` | [0010](.docs/decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |

In a `composer.json`, under `extra.mutation-gate`:

| Key | Type | Default | Decided in |
|-----|------|---------|------------|
| `extensions` | list of class names | `[]` | [0001](.docs/decisions/0001-a-framework-free-core-behind-nine-ports.md) |
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
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`, `AWS_ROLE_ARN` | The S3 proof store's credentials, and its only ones: no `~/.aws` file, instance, container or web identity role is read. With `AWS_ROLE_ARN` set, those keys assume that role | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `MUTATION_GATE_SLACK_URL`, `MUTATION_GATE_DISCORD_URL`, `MUTATION_GATE_WEBHOOK_URL` | The webhook URLs of the `slack`, `discord` and `webhook` reporters, unless their `with.urlEnv` names other variables | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `MUTATION_GATE_WEBHOOK_SECRET` | Signs each `webhook` request, with the time it was sent, as `X-Mutation-Gate-Signature`, unless `with.secretEnv` names another variable | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `OTEL_EXPORTER_OTLP_ENDPOINT`, `OTEL_EXPORTER_OTLP_HEADERS`, `OTEL_SERVICE_NAME`, `OTEL_RESOURCE_ATTRIBUTES` | Where and how the `otlp` reporter sends | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `MUTATION_GATE_RESULTS` | Set by the Pest adapter for its own plugin; not for users | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `MUTATION_GATE_SHARED_COVERAGE`, `MUTATION_GATE_SUITE_SECONDS`, `MUTATION_GATE_CANARY` | Set by the Pest adapter for the lines `pest:patch` writes into pest-plugin-mutate: the planning job's coverage map, its suite's seconds and the canary group; not for users | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |

Files the gate reads and writes:

| Path | What it is | Decided in |
|------|------------|------------|
| `mutation-gate.php`, `.json`, `.yaml`, `.yml` or `.neon` | The config | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `mutation-gate.baseline.json` | The committed floors | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `.mutation-gate/plan.json`, `.mutation-gate/coverage/`, `.mutation-gate/results/<id>.json` | The plan, its coverage and each shard's result | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `.mutation-gate/pipeline.yml` | GitLab's child pipeline | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `.mutation-gate/ledger/<scope>/ledger.json.gz` | The proof ledger of one ref | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `.mutation-gate/mutants/<native id>.php` | The mutated file of a mutant judged by reference (Pest) | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `infection.json5`, `infection.json`, `infection.json5.dist` or `infection.json.dist` | The project's own Infection config, the first found, whose mutators, `bootstrap`, `phpUnit`, `initialTestsPhpOptions`, `testFrameworkExtraArgs` and static analysis the gate keeps | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `.mutation-gate/infection/` | The config the gate writes for each run of Infection, Infection's logs, its temporary files, and the coverage the adapter runs PHPUnit for | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `.mutation-gate/publish/badge.json`, `trend.json`, `trend.svg` | The badge and trend | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `.mutation-gate/publish/savings.json` | A shields.io endpoint with the time saved in the last 30 days | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `.mutation-gate/baseline.measured.json` | The baseline a CI run measured for trees with no floor, to commit as `mutation-gate.baseline.json` | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| A `json`, `junit`, `sarif`, `gitlab` or `kill-matrix` report's `path` | That report | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| An `html` report's `path`: `index.html`, `mutation-report.json` | The HTML report and its data | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| A `tests` report's `path`, and the same path with `.md` | The useless and removable tests, as JSON and Markdown | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |

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
With an AWS OIDC role, make its trust policy match a GitHub environment that
only the default branch may deploy to, or the verdict workflow's
`job_workflow_ref`, never `ref: refs/heads/main` alone: every job a workflow
runs on the default branch carries that `ref`, whatever started it
([ADR-0019](.docs/decisions/0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md)).

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
proof store, `MUTATION_GATE_SLACK_URL`, `MUTATION_GATE_DISCORD_URL`,
`MUTATION_GATE_WEBHOOK_URL` and `MUTATION_GATE_WEBHOOK_SECRET` for chat alerts,
and `OTEL_EXPORTER_OTLP_ENDPOINT` and `OTEL_EXPORTER_OTLP_HEADERS` for
OpenTelemetry. Its outputs are `verdict`, `scores` and `plan`, and it uploads the
reports as the artifact `mutation-gate-reports`, and a baseline measured for
trees with no floor as `mutation-gate-baseline`.

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

### Verifying a webhook

A signed `webhook` request carries `X-Mutation-Gate-Signature:
t=<unix seconds>,sha256=<hex>`, the HMAC-SHA256 of `<t>.<body>` under the
secret. A receiver checks it before it trusts the body:

```php
[$t, $mac] = sscanf($_SERVER['HTTP_X_MUTATION_GATE_SIGNATURE'] ?? '', 't=%d,sha256=%64s');
$body = file_get_contents('php://input');
$fresh = abs(time() - (int) $t) <= 300;
$valid = hash_equals(hash_hmac('sha256', "{$t}.{$body}", $secret), (string) $mac);

if (! $fresh || ! $valid) {
    http_response_code(401);
    exit;
}
```

## Local use

```sh
vendor/bin/mutation-gate watch          # re-judges what each save reaches, within 60 seconds
vendor/bin/mutation-gate hook install   # a pre-push hook: judges the pushed commits within 5 minutes
vendor/bin/mutation-gate hook install --pre-commit   # also shows the score change at each commit
vendor/bin/mutation-gate init --editor=vscode        # survivors as problems in VS Code
```

`.mutation-gate/` holds local results and proofs. It belongs in `.gitignore`,
and `init` adds it there.

## How it is built

The design is recorded as [decisions](.docs/decisions/README.md):

- a framework-free core behind its ports;
- Pest and Infection as adapters;
- a content-keyed proof ledger;
- the toolchain the package holds itself to.

That toolchain is:

- its own gate at 100%;
- PHPStan at max;
- arch tests, with a planted violation for every rule.

## Licence

MIT. See [LICENSE](LICENSE).
