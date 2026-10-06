<picture>
  <source media="(prefers-color-scheme: dark)" srcset=".docs/brand/mark-dark.svg">
  <source media="(prefers-color-scheme: light)" srcset=".docs/brand/mark-light.svg">
  <img alt="mutation-gate" src=".docs/brand/mark-light.svg" height="48">
</picture>

# mutation-gate

> **In development, not yet released.** This README describes what the
> decisions in [`.docs/decisions`](.docs/decisions/README.md) settle, and
> nothing more. Nothing is tagged until every feature below is built, tested
> and gated at 100%, and the first release is 0.1.0.

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
| | `init --ci`: a ready, pinned workflow for GitHub, GitLab, Buildkite, CircleCI, Azure DevOps, Bitbucket Pipelines or Jenkins | [0015](.docs/decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md), [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| | Floors that only rise: a committed baseline, which fails on regression and rises on improvement | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| | Pull-request mode: changed lines and what the change reaches, with a stricter floor for new code | [0003](.docs/decisions/0003-a-floor-only-rises.md), [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| | Monorepos: a floor per package and module, with reach that follows the dependencies | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| | `migrate`: a config and a baseline moved to the current format, in any of the four formats | [0026](.docs/decisions/0026-configs-and-baselines-move-forward-with-one-command.md) |
| | A signed PHAR and a multi-arch container image beside the Composer package | [0022](.docs/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| | `composer mutate`, and recipes for CaptainHook, GrumPHP and the pre-commit framework | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| **CI and speed** | A cost model that learns how long each file takes from earlier shards | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| | Sharding on any CI: GitHub Actions, GitLab, Buildkite, CircleCI, Azure DevOps, Bitbucket Pipelines, Jenkins, or a JSON plan | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
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
| | Ignores for equivalent mutants, and by its id for a mutant whose file loads before Pest can put it in place, each with a reason and an optional expiry | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Survivors the compiler proves equivalent, left out of the score | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| | Presets for Laravel, Symfony and plain libraries | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| | Custom mutators, written once for Pest and Infection against a typed SDK | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| | Laravel and Symfony mutator sets, turned on by their presets | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| | Security mode: mutators for authorisation, CSRF, escaping and constant-time comparisons, held to a floor of their own | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| | A hint that a surviving removal may be dead code | [0025](.docs/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| **Visibility** | An HTML report | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | A sticky comment on the pull request, posted when the plan is made with the units it mutates, the time it should take and the changed lines no test runs, then updated with the verdict | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md), [0019](.docs/decisions/0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md) |
| | A cost estimate in the PR comment: time planned, measured and spared, and money at the team's rate | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| | Chat alerts to Slack, Discord or a webhook when the default branch fails, cannot be judged, or recovers | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| | Run metrics as OpenTelemetry traces and metrics, and in the JSON report | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| | A badge (a shields.io endpoint) and a trend on the default branch | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| | Every run says what it saved against a full run in one job: reach and proofs, and what sharding saved in waiting and cost in runner time | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | Progress and an ETA during a run | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | A benchmark against plain Pest and Infection on four open-source projects: cold, warm and per pull request, losses included | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| | The sticky comment and its planned state on GitLab merge requests and Bitbucket pull requests, with survivors in GitLab's Code Quality report and Bitbucket's Code Insights | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| | An organisation dashboard: a static site of every repository's trends, floors and savings | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| | Survivors in SonarQube's issue list, through its generic external-issues format | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |

## Install

```sh
composer require --dev nightworksio/mutation-gate -W
```

`-W` lets Composer move `pestphp/pest-plugin-mutate` to the release the gate
has tested, which its `conflict` pins exactly.

Requirements:

- PHP 8.5 or a later 8.x, with pcov or Xdebug for coverage.
- One of the runners:
  - **Pest**: `pestphp/pest` ^5.1 with `pestphp/pest-plugin-mutate` ^5.0, on
    the PHPUnit 13 release Pest pins;
  - **Infection**: `infection/infection` ~0.35.0, with PHPUnit 12 or 13. The
    adapter reads the project's `infection.json5` and PHPUnit's XML coverage
    with `colinodell/json5` and `ext-dom`, which Infection itself requires;
  - **PHPUnit** on its own: `phpunit/phpunit` 13.2 or later, the first with
    `--test-id-filter-file`. The gate makes each mutant with its `default`
    mutator set, and the project's PHPUnit runs the tests that cover it, a
    mutant on each core at once, loading the gate's extension from the
    project's vendor directory, where `composer require --dev` puts it. With
    `ext-pcntl` in the PHP it starts, each mutant is forked from a worker that
    booted once (`runner.workers`).

  Infection and the PHPUnit runner do not run Pest suites, so a Pest project
  uses Pest's own mutation testing.

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
  `phpunit.xml.dist`), one per directory a glob such as `plugins/*/src`
  matches;
- **runner**: whichever of Pest's mutation plugin and Infection is installed,
  or the PHPUnit runner where neither is, PHPUnit is, and Pest is not;
- **preset**: Laravel, Symfony or library, from your `composer.json`.

It runs the suite once under coverage, mutates every tree and prints each tree's
score. Every surviving mutant is shown with its diff, the tests that ran it,
what they miss, and the command that reproduces it:

```sh
vendor/bin/mutation-gate reproduce 3f9a1c2b7d04
```

On a first local run there is no baseline yet, so the gate writes
`mutation-gate.baseline.json` with each tree's floor, and each package's
security floor, at the score it measured, and asks you to commit it. From then
on a score may not fall below its floor. When the score improves,
`vendor/bin/mutation-gate baseline --write` raises the floor. In CI, a tree or
security set with no floor at all fails the run with exit code 2, after
mutating: the step summary and the PR comment carry the baseline it measured,
ready to commit.

`vendor/bin/mutation-gate init` sets a project up in one command, and
`vendor/bin/mutation-gate doctor` says what would fail or run slowly before a
run does.

## Commands

| Command | What it does |
|---------|--------------|
| `mutation-gate` or `mutation-gate run` | Plan, run and judge in one process. With `--changed-since=<ref>`, only what the change reaches. With `--budget=<duration>`, the riskiest code first, within that time. |
| `coverage [--into=<dir>]` | Run the suite under coverage and write the gate's own map, `<dir>/map.json.gz` (`.mutation-gate/coverage` by default), for a later `plan --coverage=<dir>` |
| `plan` | Work out the reach, drop proved units, cut shards and print the plan for a CI (`--ci=github\|gitlab\|buildkite\|circleci\|azure\|bitbucket\|jenkins\|json`, or `--shards=<n>` for a fixed count; `--coverage=<dir>` reads the map `coverage` wrote instead of running the suite) |
| `run --plan=<file> [--shard=<id>]` | Mutate one shard: the one `--shard` names, or the one the CI's environment names |
| `verdict --plan=<file> --results=<dir>` | Merge every shard's results, judge the floors, write reports and the ledger |
| `deliver [--from=<dir>]` | Send what a run left in `<dir>` (`.mutation-gate/delivery` by default), with credentials that run never held: write the ledger to the store, post the comment and the alerts, export OTLP. It runs from the gate's own installation and loads none of the project's code. Exits 2 where it cannot start or read the delivery, or where a trusted run's ledger is not written |
| `fetch [--to=<dir>]` | Read the default branch's ledger from the store with a key that only reads, and write it into `<dir>` (`.mutation-gate/ledger` by default), where the `directory` store reads it. It runs from the gate's own installation and loads none of the project's code. Exits 0 where `MUTATION_GATE_STORE` names no store, the ledger cannot be read or the job holds no key, saying why, and 2 where it cannot start, locate the store it names, name the default branch or write the ledger |
| `baseline [--write]` | Show, or write, floors raised to what was measured |
| `survivors [--plan=<file>]` | On a branch's run, run the last run's survivors again before the shards: those the comment lists, on the change's lines first, at most `survivorsFirst.max`, each found again by its id or reported gone. It writes the comment over its planned state and the step summary, and says how many still survive. It re-checks what the plan reaches, or plans first without one; it writes no ledger and judges nothing, so it exits 0, or 2 where it cannot re-check |
| `reproduce <id>` | Run one recorded mutant again, alone, and show why it survives, with the runner's own output; the id may be a unique prefix of 6 or more. Exits 1 where the run finds other than what was recorded, and 2 where no ledger holds it or the run no longer makes it |
| `explain <id> [--format=text\|json]` | Show one mutant's diff, hint, covering tests and their outcomes, for a kill by static analysis the analyser and its finding's file, code and message, how the last run took its unit, and its history, from the ledgers and the last run, running nothing; the id may be a unique prefix of 6 or more, or a cluster's id. `--format=json` is described by [`resources/explain.schema.json`](resources/explain.schema.json). Exits 2 where no record holds it |
| `affected [--changed-since=<ref>] [--coverage=<dir>] [--format=files\|files0\|ids\|json]` | List the tests the change since the coverage map was measured can make fail, and with `--changed-since` the change since `<ref>` besides, running nothing: each test file a line (`files`, for PHPUnit 13's `--test-files-file`), each ended by a NUL byte (`files0`, for `xargs -0 vendor/bin/pest`, or `vendor/bin/phpunit` on PHPUnit 12), each test id a line (`ids`, for PHPUnit 13.2's `--test-id-filter-file`), or the JSON answer. The reasons go to standard error but in JSON. A map that is missing, does not say where it was measured or was measured in a dirty tree lists every test. Exits 0 whenever it answered, and 2 where git cannot tell what changed or `ids` cannot select the tests |
| `tests` | Print the tests report of the last run, judged again from the ledgers and the coverage map it left, running nothing: the useless, redundant and weakly asserting tests. Exits 0 whatever it finds, and 2 where no run has left one |
| `triage <path> [--repeat=<n>] [--order=runner\|killers-first]` | Run a unit, a file of a tree or a held path, n times (5 by default, 2 or more) and list every mutant whose result varied, with the runs that gave each result, each mutant's tests in the order `--order` names (`tests.order` by default). Exits 1 where a mutant varied, 0 where none did, and 2 where the unit cannot be run |
| `watch` | Re-judge what each save reaches |
| `pre-push` | Judge the commits being pushed, as CI will, after printing each reached tree's score change |
| `pre-commit` | Print each reached tree's score change from the local ledger; runs nothing and always exits 0 |
| `hook install [--pre-commit]` / `hook uninstall` | Add or remove the pre-push hook, and with `--pre-commit` the pre-commit hook too |
| `init [--format=php\|json\|yaml\|neon] [--ci[=github\|gitlab\|buildkite\|circleci\|azure\|bitbucket\|jenkins]] [--editor=vscode]` | Detect the runner, preset, trees, CI, an Infection config and native markers, and ask only what detection cannot settle; write a config holding the runner, the preset and the answers (PHP by default, or the file `--config` names, in the format of its extension), with the trees it found as a comment, and add `.mutation-gate/` to `.gitignore`; with `--ci`, a pinned CI definition (`--ci` alone takes the detected CI), and with `--editor`, VS Code's watch task, each only where none exists; then print the first run's estimate |
| `init --from[=<file>]` or `import [<file>]` | Write a config from an Infection config, the file named or the one Infection itself would read, over what zero-config found; say of each of its keys whether it was imported, stays in that file or was dropped, and which keys to delete |
| `doctor [--measure] [--online] [--format=text\|json]` | Report what would fail, run slowly or deserves attention, each with its fix, reading only files and earlier runs; exits 1 when something would fail |
| `stub <id> [--style=pest\|phpunit] [--write]` | Print a failing Pest or PHPUnit test for a survivor or an uncovered mutant, or one for a cluster, in the style of its nearest covering test, found as `explain` finds the mutant; with `--write`, add it to that file, or create one, never overwriting. Exits 2 where there is nothing to stub, with the next step where there is one |
| `config:show [--format=…]` / `config:schema` | Print the effective config (JSON by default), or the JSON Schema |
| `pest:patch` | Apply the optional Pest patches ([ADR-0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md)) |
| `infection:patch` | Give Infection the gate's mutant limit. Exits 1 where the installed Infection is a release it does not patch, which keeps Infection's own limit, and 2 where it cannot patch a release it supports ([ADR-0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md)) |

Options:

| Option | Accepted by | What it does | Decided in |
|--------|-------------|--------------|------------|
| `--config=<path>` | every command | Read this config file instead of looking for one | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `--no-extensions` | every command | Load only the extensions of this package and its first-party plugins, and no third-party code | [0001](.docs/decisions/0001-a-framework-free-core-behind-nine-ports.md) |
| `--deliver-later` | `plan`, `survivors`, `verdict` | Send nothing that needs a credential and write no store: leave the ledger and the comment, alert and OTLP payloads in `.mutation-gate/delivery/planned`, `.mutation-gate/delivery/survivors` or `.mutation-gate/delivery/verdict`, begun empty, for `deliver`; on a pull request the comment is left whether or not the job holds a token | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `--runner=<name>` | every command | Set `runner` | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `--report=<name>:<path>` | every command, repeatable | Add a file report to `reports` | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `--changed-since=<ref>` | `plan`, `run` without a plan, `affected` | Mutate, or list the tests of, only what the change since `<ref>` reaches; `last-passed` is the newest passing commit | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `--full` | `plan`, `run` without a plan | Mutate everything, whatever the event; an error beside `--changed-since` | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `--budget=<duration>` | `run` | Stop after this long, riskiest code first | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `--coverage=<dir>` | `plan`, `run` without a plan, `affected` | Read the coverage an earlier job wrote instead of running the suite | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--ci=<name>` | `plan`, `run` | Set `ci.plan`: this CI's format instead of the detected one | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--shards=<n>` | `plan`, `run` without a plan | Cut exactly n shards | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--security` | `plan`, `run` without a plan | Make mutants with the security-tagged mutators alone, and judge only the security sets: each tree is shown exempt, and no commit is recorded as passed. Every shard and the verdict follow a plan made with it | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `--suite=<name>` | `plan`, `run` without a plan | Judge the mutants by this PHPUnit `<testsuite>`'s tests alone, the coverage run's among them, and hold no floor: each tree and security set is shown exempt, unless `--security` keeps the security sets held, and no commit is recorded as passed. Takes no `--coverage`. Every shard and the verdict follow a plan made with it | [0025](.docs/decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
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
| `--kill-matrix=first\|full` | `plan`, `run` | `full` records every test that kills each mutant, for the redundant-test report (Pest and the PHPUnit runner) | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |
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
A runner an extension registers answers `behaviour()` with
`RunnerBehaviour::standard()` unless it behaves otherwise, and answers
`coverage()` for a `CoverageRun`, the
tests it runs under coverage, and for a `CoverageRead`, the directory of a map
another job wrote; the runner contract in `tests/Contract/Runner` holds it to
both ([ADR-0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md)).

### Reports

Every report renders the same verdict
([ADR-0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md)).
Each reporter is registered by a name:

| Name | When it runs | What it writes |
|------|--------------|----------------|
| `console` | Always | The verdict, each tree, new-code set and package's security set, what each suite alone kills where the PHPUnit config declares two or more, the units, the reach, every mutant counted as not killed with its diff, judging tests, hint, and reproduce and explain commands, the ignores, the mutants proven equivalent, the floors that can rise, failures and warnings, and last what a timed run took and saved |
| `json` | Listed in `reports` | The gate's own report at `path`, `"format": 2`, described by [`resources/report.schema.json`](resources/report.schema.json); every score and floor in it is a percentage with at most two decimals, truncated; the tests are listed once, and each mutant points at those that cover and killed it; a mutant a static analyser killed has its `rejection`: the analyser, the `file` its finding sits in, and the finding's `code` and `message`; `suites` gives each suite's `covered`, `killed`, `score` and whether it is `exact`; a timed run adds its `run`, `cost` and `savings` |
| `junit` | Listed in `reports` | JUnit XML at `path`: a suite per tree with a `floor` test case, a `new code` suite, a `security` suite with a `floor` test case per package, a `run` suite for failures no floor decides, and an `ignored` suite with a skipped test case per ignored mutant, its message why |
| `sarif` | Listed in `reports` | SARIF 2.1.0 at `path`, for code scanning, with each security mutant's result marked `properties.security: true`, and each ignored mutant a `note` result suppressed with why: `external` for the config's ignores, `inSource` for a runner's own marker; with `CI` unset it also names the repository's root as a `file://` URI, for an editor's SARIF viewer |
| `html` | Listed in `reports` | `mutation-report.json` and a self-contained `index.html` under the `path` directory, shown with Stryker's viewer, with what each suite alone kills above it |
| `gitlab` | Listed in `reports` | GitLab's Code Quality JSON at `path`: an issue per mutant counted as not killed, `major` in a set that failed and `minor` otherwise |
| `sonar` | Listed in `reports` | SonarQube's generic external-issues format at `path`, for SonarQube Server 10.3 or later and SonarQube Cloud: an issue per mutant counted as not killed, at its lines and columns, under the rule SARIF reports it by, or under `survived-security` where it is a surviving security mutant, each rule a medium reliability issue of the engine `mutation-gate`, and `survived-security` a medium security one; the line saying where it wrote also says how many issues are under each top directory |
| `kill-matrix` | Listed in `reports` | CSV at `path`: a record per mutant and covering test, with what the test did with the mutant in place |
| `tests` | Listed in `reports` | JSON at `path`, described by [`resources/tests.schema.json`](resources/tests.schema.json), and Markdown beside it: the tests that kill nothing they judged, those never the first to kill, from a full kill matrix those that can go together without losing a kill, and those that assert only existence or shape beside the survivors they let through, with the assertion of value to write |
| `problems` | With `--output=problems` | One `<path>:<line>:<col>: <error\|warning>: <message> [<rule>] <id>` line per result, between `mutation-gate: judging` and `mutation-gate: judged`, for an editor's problem matcher |
| `slack` | In CI, on the default branch, when its state changes | A Slack message to the URL `MUTATION_GATE_SLACK_URL` holds, or the variable `with: {urlEnv: …}` names: the change, the trees below their floor, the failures, up to five survivors, and the run |
| `discord` | In CI, on the default branch, when its state changes | The same as one Discord embed to the URL `MUTATION_GATE_DISCORD_URL` holds, red, green or grey, mentioning no one |
| `webhook` | In CI, on the default branch, when its state changes | JSON described by [`resources/webhook.schema.json`](resources/webhook.schema.json) to the URL `MUTATION_GATE_WEBHOOK_URL` holds, signed in `X-Mutation-Gate-Signature` ([verifying it](#verifying-a-webhook)) where `MUTATION_GATE_WEBHOOK_SECRET`, or the variable `with: {secretEnv: …}` names, holds a secret |
| `otlp` | Listed in `reports` | The run's trace (`plan`, each `shard <n>` with its `opening run` and `mutate`, and `verdict`) and the verdict's metrics as OTLP/HTTP JSON, to `/v1/traces` and `/v1/metrics` under `with: {endpoint: …}` or `OTEL_EXPORTER_OTLP_ENDPOINT`, with `OTEL_EXPORTER_OTLP_HEADERS`, `OTEL_SERVICE_NAME` and `OTEL_RESOURCE_ATTRIBUTES` |
| `github-annotations` | Under GitHub Actions | Up to 10 error, 10 warning and 10 notice annotations, changed lines first, and one for each cluster of survivors |
| `github-summary` | Under GitHub Actions | The step summary: what a timed run took and saved, what the default branch saved over 30 days, what each suite alone kills, and every mutant counted as not killed in one table, a cluster of survivors as one row, and every ignored mutant with why in another |
| `github-comment` | On a pull request, with `GITHUB_TOKEN` | One sticky comment, updated in place, with what a timed run took and saved under the verdict, up to 20 ignored mutants with why, and what it cost folded at the end, within the 65,536 characters GitHub takes: each list shows fewer entries where it must, and each diff and hint at most 1,500 characters; `with: {identity: …}` names the account it is found by when the token is not `GITHUB_TOKEN` |
| `badge` | In CI, on the default branch | `badge.json`, `trend.json`, `trend.svg` and `savings.json` in `--publish-dir` |

SonarQube imports the `sonar` report from the path the scanner's
`sonar.externalIssuesReportPaths` names, as in `sonar-project.properties`:

```properties
sonar.externalIssuesReportPaths=build/mutation-sonar.json
```

with `{"use": "sonar", "path": "build/mutation-sonar.json"}` in `reports`.
SonarQube drops an issue on a file outside `sonar.sources`, and `doctor`
names each tree that lies outside it
([ADR-0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md)).

Every mutant the score counts as not killed carries its reproduce command,
`vendor/bin/mutation-gate reproduce <id>`, and a sentence saying what the
tests miss. The console, JSON and HTML reports also give
`vendor/bin/mutation-gate explain <id>`. A survivor proven equivalent
([ADR-0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md))
is left out of the score and listed as *equivalent, proven*.

Survivors that share one cause form a cluster: changes that overlap within
one statement, or changes of one kind in one function that the same tests
judge
([ADR-0022](.docs/decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md)).
The console, the comment, the step summary and the annotations show a
cluster once, with its members' diffs, one hint and
`vendor/bin/mutation-gate stub <cluster id>`. Every member still counts in
the score, and the JSON and SARIF reports keep each as an entry of its own
that names its cluster.

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
| `runner` | adapter: `pest`, `infection`, `phpunit` | the one installed: `phpunit` only where neither of the others is, and Pest is not | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md), [0023](.docs/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `runner.withhold` | list of environment-variable names or globs the runner never hands the project's tests, added to those every run withholds; a guard against accidents, not a sandbox | `[]` | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `runner.memory` | the `memory_limit` of every PHP process a mutation run starts, as PHP writes it (`512M`, `1G`), or `-1` for none; a `memory_limit` the project sets in `phpunit.xml` or a bootstrap file wins over it. Under Infection and the PHPUnit runner it also sets `display_errors=stdout`, so a warning raised outside a test, such as in a bootstrap file, also prints on standard output | `1G` | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `runner.workers` | how the PHPUnit runner starts each mutant's run: `fork`, from a worker per process that boots the autoloader and the bootstrap once, or `fresh`, a new PHP process per mutant. `fork` needs `pcntl` in the PHP the runner starts, and mutants run fresh without it. A boot that leaves a socket or database connection open, starts PHPUnit's events or loads a file the run mutates is refused: its mutants run fresh, and the run warns of why, as `doctor` does. Survivors are always confirmed in a fresh process. Pest and Infection start their own processes, so it changes nothing for them | `fork` | [0023](.docs/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `treeSource` | adapter: `phpunit`, `composer` | `phpunit` | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `treeSource.with.fallback` | list of paths, the trees when `phpunit.xml` has no `<source>` | `[]`, or the preset's; `[]` takes the `autoload` paths of `composer.json` | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `trees` | list of `{path, floor, reason, exclude}`, laid over the tree source's trees: a listed path takes its floor, reason and exclude from here | the tree source's trees | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `trees[].floor` | number, 0 to 100 | the nearest manifest's, if any | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `trees[].reason` | string | none; required when `floor` is 0, and refused beside any other | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `trees[].exclude` | list of globs, each matching a file in the tree | `[]` | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `newCode.floor` | number, 0 to 100 | `100` | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| `security.floor` | number, 0 to 100 | none | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
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
| `ci.plan` | adapter: `github`, `gitlab`, `buildkite`, `circleci`, `azure`, `bitbucket`, `jenkins`, `json` | detected from the environment | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `ci.defaultBranch` | branch name | the CI's answer, else git's `origin/HEAD`, else `main` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.check` | the check-run name the verdict reports under | `mutation / verdict` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `ci.trustMergedPullRequests` | boolean: whether the default branch takes a merged pull request's own recorded pass as proof of its tree and spares it a re-check. It works only where pull request runs write their own scope's ledger, an untrusted write, so turning it on trusts the pull request's own computed result | `false` | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `ci.gitlab.template` | path | `.gitlab/mutation-gate.yml` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.buildkite.step` | map of step keys | `{}` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.buildkite.definition` | path of the pipeline file that runs the gate under Buildkite | `.buildkite/pipeline.yml` | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `ci.azure.definition` | path of the pipeline file that runs the gate under Azure DevOps | `azure-pipelines.yml` | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `ci.bitbucket.definition` | path of the pipeline file that runs the gate under Bitbucket Pipelines | `bitbucket-pipelines.yml` | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `ci.jenkins.definition` | path of the Jenkinsfile that runs the gate under Jenkins | `Jenkinsfile` | [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `proofs.store` | adapter: `directory`, `s3`, `gcs`, `azure` | `directory` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.path` (`directory`) | path | `.mutation-gate/ledger` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.bucket` (`s3`) | string | none; required | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.prefix` (`s3`) | string | `mutation-gate` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.region` (`s3`) | a region's name: lowercase letters and digits in parts joined by single hyphens, such as `eu-west-1`, or R2's `auto` | `us-east-1` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.endpoint` (`s3`) | `https://` URL, or `http://` where `insecureEndpoint` is true | AWS's own | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.insecureEndpoint` (`s3`) | boolean: whether an `http://` endpoint is allowed, for a store on a network you trust; it sends the signed requests and the ledgers in the clear | `false` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.store.with.publicUrl` (`s3`) | `https://` URL a run without credentials reads the default branch's ledger from, at `<publicUrl>/<prefix>/refs/heads/<default branch>/ledger.json.gz`; one with a user, a query or a fragment is refused | none | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `proofs.store.with.bucket` (`gcs`) | a Cloud Storage bucket's name | none; required | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.store.with.prefix` (`gcs`, `azure`) | string | `mutation-gate` | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.store.with.publicUrl` (`gcs`) | `https://storage.googleapis.com/<bucket>`, over a managed folder `<prefix>/refs/heads/<default branch>/` that `allUsers` may read | none | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.store.with.account` (`azure`) | a storage account's name | none; required | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.store.with.container` (`azure`) | the private container every scope but the default branch's is kept in | none; required | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.store.with.publicContainer` (`azure`) | a container at the `Blob` access level, which keeps the default branch's scope | none | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.store.with.publicUrl` (`azure`) | `https://<account>.blob.core.windows.net/<publicContainer>` | none | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `proofs.ignore` | list of globs | `[]` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `proofs.write` | `auto` or `never` | `auto` | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `coverage.incremental` | boolean: whether a run measures again only the test files whose coverage could have moved, keeping the rest from the default branch's kept map | `true` | [0023](.docs/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `budget` | duration | none | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.mode` | `confirm` or `unjudged` | `confirm` | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.seconds` | integer: the least a mutant's run is allowed, and what one whose covering tests were not all timed is; each mutant otherwise gets 5 s plus three times its covering tests' own time, within the two bounds. Infection does so under `infection:patch`; unpatched, it keeps its own limit under `timeouts.most`, with no floor, and every run warns of it | `10`; `30` in the `laravel` and `symfony` presets | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.most` | integer, at least `timeouts.seconds`: the most a mutant's run is allowed; unpatched Infection skips a mutant whose covering tests take as long | `300` | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `timeouts.retries` | integer | `20` | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `flaky.confirmSurvivors` | boolean | `true` | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `tests.order` | `killers-first` or `runner` | `killers-first` | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `survivorsFirst.max` | whole number, at least 0: how many of the last run's survivors a pull request's run re-checks before its shards, those on changed lines first; `0` re-checks none | `20` | [0020](.docs/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `ignores.entries` | list of `{mutant, reason, expires}` or `{path, mutator, reason, expires}` | `[]` | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `ignores.maxDays` | integer | none | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `ignores.native` | `refuse` or `allow` | `refuse` | [0008](.docs/decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `equivalence.static` | boolean | `true` | [0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `reports` | list of `{use, path, with}`; built-in `json`, `junit`, `sarif`, `html`, `tests`, `kill-matrix`, `gitlab`, `sonar`; `badge`, whose `path` is `--publish-dir` where it names none; and without a `path` `console`, `problems`, `slack`, `discord`, `webhook`, `otlp`, `github-annotations`, `github-summary`, `github-comment`. A config names every `path` inside the project | `[]` | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `badge.colors` | map of shields.io colour to lowest score | `{"brightgreen": 90, "green": 80, "yellow": 70, "orange": 60}`, red below | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `pest.patch` | boolean | `false` | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `pest.canary` | group name, with no whitespace | `mutation-canary` | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `staticCheck.tool` | adapter: `mago`, `phpstan`, `psalm`; or `auto`, the first installed and configured, or `none` | `auto` | [0020](.docs/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `staticCheck.config` | path | the analyser's own | [0020](.docs/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `staticCheck.seconds` | integer | `60` | [0020](.docs/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `mutators.sets` | list of mutator set names, never `default`, which is always on | `[]`, plus the preset's | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `mutators.except` | list of mutator names, `<set>/<Name>`, each held by a set in `mutators.sets` or by the default set; under Pest or Infection, by a set in `mutators.sets` | `[]` | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `local.watchBudget` | duration | `1m` | [0010](.docs/decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| `local.prePushBudget` | duration | `5m` | [0010](.docs/decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |

In a `composer.json`, under `extra.mutation-gate`:

| Key | Type | Default | Decided in |
|-----|------|---------|------------|
| `extensions` | list of class names | `[]` | [0001](.docs/decisions/0001-a-framework-free-core-behind-nine-ports.md) |
| `floor` | number, 0 to 100 | none | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `floorReason` | string | none; required when `floor` is 0 | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `newCodeFloor` | number, 0 to 100 | `newCode.floor` | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `securityFloor` | number, 0 to 100, in a package's `composer.json` only | `security.floor` | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |

Environment variables that change what the gate does:

| Variable | What it does | Decided in |
|----------|--------------|------------|
| `CI`, or Azure Pipelines' `TF_BUILD` | Set: a tree with no floor stops the run, `baseline.improvement` applies, and on the default branch the badge and trend are written. Unset: a full run writes missing floors and raises improved ones | [0003](.docs/decisions/0003-a-floor-only-rises.md), [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `GITHUB_ACTIONS`, `GITLAB_CI`, `BUILDKITE`, `CIRCLECI`, `TF_BUILD`, `BITBUCKET_BUILD_NUMBER`, `BUILD_TAG` | Choose the CI plan, and under GitHub Actions the annotations and step summary | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `SHARD`, `CI_NODE_INDEX`, `CI_NODE_TOTAL`, `BUILDKITE_PARALLEL_JOB`, `BUILDKITE_PARALLEL_JOB_COUNT`, `CIRCLE_NODE_INDEX`, `CIRCLE_NODE_TOTAL`, `BITBUCKET_PARALLEL_STEP`, `BITBUCKET_PARALLEL_STEP_COUNT`, `CI_JOB_NAME`, `PARENT_PIPELINE_ID` | Which shard a job is, and how GitLab's child pipeline finds the plan | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `GITHUB_REF`, `GITHUB_EVENT_NAME`, `GITHUB_EVENT_PATH`, `CI_COMMIT_REF_NAME`, `CI_MERGE_REQUEST_IID`, `CI_DEFAULT_BRANCH`, `BUILDKITE_BRANCH`, `BUILDKITE_PULL_REQUEST`, `BUILDKITE_PIPELINE_DEFAULT_BRANCH`, `CIRCLE_BRANCH`, `CIRCLE_PULL_REQUEST`, `BUILD_SOURCEBRANCH`, `BUILD_REASON`, `SYSTEM_PULLREQUEST_PULLREQUESTNUMBER`, `SYSTEM_PULLREQUEST_PULLREQUESTID`, `BITBUCKET_BRANCH`, `BITBUCKET_PR_ID`, `BITBUCKET_TAG`, `BRANCH_NAME`, `CHANGE_ID`, `TAG_NAME` | The run's ref, whether it is a pull request, and the default branch | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `GITHUB_WORKFLOW_REF`, `CI_CONFIG_PATH` | Which CI definition runs the gate, for reach and the proof key | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md), [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `GITHUB_OUTPUT`, `GITHUB_STEP_SUMMARY` | Where the GitHub plan and the step summary are written | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `GITHUB_RUN_ID`, `GITHUB_RUN_ATTEMPT`, `CI_PIPELINE_ID`, `BUILDKITE_BUILD_ID`, `CIRCLE_WORKFLOW_ID`, `BUILD_BUILDID`, `BITBUCKET_BUILD_NUMBER`, `BUILD_TAG` | The run a proof names | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md), [0024](.docs/decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `GITHUB_TOKEN` | Lets the sticky PR comment be posted, and the GitHub change source prove which pull request's run passed | [0005](.docs/decisions/0005-what-a-change-reaches-is-what-is-mutated.md), [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`, `AWS_ROLE_ARN` | The S3 proof store's credentials, and its only ones: no `~/.aws` file, instance, container or web identity role is read. With `AWS_ROLE_ARN` set, those keys assume that role | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `GOOGLE_APPLICATION_CREDENTIALS` | The external-account credentials file `google-github-actions/auth` writes, which the `gcs` store exchanges the CI's token through, impersonating the service account it names; only its `file` and `url` credential sources are read, and a file holding a service-account key or any other long-lived credential is refused (exit 2) | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `MUTATION_GATE_GCS_TOKEN`, `MUTATION_GATE_AZURE_TOKEN` | A ready bearer token for the `gcs` or `azure` store, which a CI with a federation of its own hands over | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `ACTIONS_ID_TOKEN_REQUEST_URL`, `ACTIONS_ID_TOKEN_REQUEST_TOKEN`, `AZURE_TENANT_ID`, `AZURE_CLIENT_ID` | The `azure` store's federation: GitHub's OIDC token, asked for the audience `api://AzureADTokenExchange`, exchanged at Microsoft Entra ID for the tenant and client `azure/login` reads | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `ACTIONS_ID_TOKEN_REQUEST_URL`, `ACTIONS_ID_TOKEN_REQUEST_TOKEN`, `MUTATION_GATE_GCS_PROVIDER`, `MUTATION_GATE_GCS_SERVICE_ACCOUNT` | The `gcs` store's federation on GitHub Actions: GitHub's OIDC token, asked for the provider's default audience, exchanged at `sts.googleapis.com` through the workload identity provider `projects/<number>/locations/global/workloadIdentityPools/<pool>/providers/<id>`, then for the token of the service account `<name>@<project>.iam.gserviceaccount.com` at `iamcredentials.googleapis.com`; any other provider or service account is refused (exit 2). It goes before `GOOGLE_APPLICATION_CREDENTIALS` where both are set | [0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `MUTATION_GATE_SLACK_URL`, `MUTATION_GATE_DISCORD_URL`, `MUTATION_GATE_WEBHOOK_URL` | The webhook URLs of the `slack`, `discord` and `webhook` reporters, unless their `with.urlEnv` names other variables | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `MUTATION_GATE_WEBHOOK_SECRET` | Signs each `webhook` request, with the time it was sent, as `X-Mutation-Gate-Signature`, unless `with.secretEnv` names another variable | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `OTEL_EXPORTER_OTLP_ENDPOINT`, `OTEL_EXPORTER_OTLP_HEADERS`, `OTEL_SERVICE_NAME`, `OTEL_RESOURCE_ATTRIBUTES` | Where and how the `otlp` reporter sends | [0016](.docs/decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `MUTATION_GATE_RESULTS` | Set by the Pest adapter for its own plugin, and by the PHPUnit runner for its extension; not for users | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md), [0023](.docs/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `MUTATION_GATE_MUTATORS` | Set by the Pest adapter for its own plugin: the file of bridges to the registered mutators the config turns on; not for users | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `MUTATION_GATE_MUTANT_FLOOR` | Set by the Infection adapter for every Infection run, for the lines `infection:patch` writes into Infection: the least seconds one mutant may run; not for users | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `MUTATION_GATE_MUTANT`, `MUTATION_GATE_MUTATED`, `MUTATION_GATE_GUARD` | Set by the PHPUnit runner for each mutant's run: the file its override serves the mutated file in place of, the mutated file, and where the override and the extension say what they served; not for users | [0023](.docs/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `MUTATION_GATE_SHARED_COVERAGE`, `MUTATION_GATE_SUITE_SECONDS`, `MUTATION_GATE_MUTANT_FLOOR`, `MUTATION_GATE_MUTANT_CAP`, `MUTATION_GATE_CANARY`, `MUTATION_GATE_ONLY` | Set by the Pest adapter for the lines `pest:patch` writes into pest-plugin-mutate: the planning job's coverage map, its suite's seconds, the least and the most seconds one mutant may run, the canary group, and the file listing the only mutants a run again makes; not for users | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `TEST_TOKEN`, `UNIQUE_TEST_TOKEN`, `PARATEST`, `LARAVEL_PARALLEL_TESTING` | Set, as paratest sets them for its workers, for each process the PHPUnit runner judges mutants in and the Pest runner tries its unexecutable mutants in, where the machine has more than one core and they run one per core: the process's place, its place and the run, and `1` for the other two. A suite that shares a database needs one database per token. Laravel creates it, named after its test database with `_test_<token>`, for each test case that refreshes, migrates, truncates or wraps the database in a transaction, so its database user must be allowed to create databases | [0023](.docs/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |

Files the gate reads and writes:

| Path | What it is | Decided in |
|------|------------|------------|
| `mutation-gate.php`, `.json`, `.yaml`, `.yml` or `.neon` | The config | [0002](.docs/decisions/0002-one-typed-config-from-several-formats.md) |
| `mutation-gate.baseline.json` | The committed floors | [0003](.docs/decisions/0003-a-floor-only-rises.md) |
| The file `staticCheck.config` names, or else the analyser's own: `mago.toml`, `mago.yaml` or `mago.json`; `phpstan.neon`, `phpstan.neon.dist` or `phpstan.dist.neon` | The static analyser's config. Every proof key holds the digest of the configuration the analyser resolves from it and of each file that configuration names, such as a baseline, an included config, or a bootstrap, stub or scanned file; where the analyser cannot say its configuration, of the config file alone | [0020](.docs/decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `.mutation-gate/plan.json`, `.mutation-gate/coverage/`, `.mutation-gate/results/<id>.json` | The plan, its coverage and each shard's result, which `plan` and a run in one process leave and `explain` reads as the last run | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| `.mutation-gate/pipeline.yml` | GitLab's child pipeline | [0006](.docs/decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `.mutation-gate/ledger/<scope>/ledger.json.gz` | The proof ledger of one ref; outside CI, the one a run writes whatever store the config names | [0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md), [0010](.docs/decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| `.mutation-gate/mutants/<native id>.php` | The mutated file of a mutant judged by reference (Pest) | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `infection.json5`, `infection.json`, `infection.json5.dist` or `infection.json.dist` | The project's own Infection config, the first found, whose mutators, `bootstrap`, `phpUnit`, `initialTestsPhpOptions`, `testFrameworkExtraArgs` and static analysis the gate keeps | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `.mutation-gate/mutators/<runner>/bridges.php` | The bridges through which Pest or Infection makes the mutants of the registered mutators the config turns on, which the gate writes for each run | [0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `.mutation-gate/infection/` | The config the gate writes for each run of Infection, Infection's logs, its temporary files, and the coverage the adapter runs PHPUnit for | [0004](.docs/decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `.mutation-gate/phpunit/` | The PHPUnit runner's override, each mutant's mutated file, the tests its run selects, what the extension recorded and the guard, the coverage it runs PHPUnit for, and its memory cap | [0023](.docs/decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `.mutation-gate/publish/badge.json`, `trend.json`, `trend.svg` | The badge and trend | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `.mutation-gate/publish/savings.json` | A shields.io endpoint with the time saved in the last 30 days | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `.mutation-gate/baseline.measured.json` | The baseline a CI run measured for trees with no floor, to commit as `mutation-gate.baseline.json` | [0017](.docs/decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| A `json`, `junit`, `sarif`, `gitlab`, `sonar` or `kill-matrix` report's `path` | That report | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| An `html` report's `path`: `index.html`, `mutation-report.json` | The HTML report and its data | [0009](.docs/decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| A `tests` report's `path`, and the same path with `.md` | The useless, removable and weakly asserting tests, as JSON and Markdown | [0014](.docs/decisions/0014-every-test-is-judged-by-what-it-kills.md) |

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

`vendor/bin/mutation-gate init --ci` writes the definition for GitHub Actions,
GitLab CI, Buildkite, CircleCI, Azure DevOps, Bitbucket Pipelines or Jenkins.
The package's CI holds each one's syntax: it runs the GitHub definitions
through `actionlint` and validates the YAML ones against their provider's
published JSON Schema. Jenkins publishes no schema for a Jenkinsfile, so a
snapshot test alone holds its template.

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
  job's coverage instead of running the whole suite again, and allows each
  mutant the time its own covering tests take, not the whole suite's.
- **The Infection patch.** With Infection as the runner, add
  `@php vendor/bin/mutation-gate infection:patch` to `post-install-cmd` and
  `post-update-cmd`, so each mutant gets the gate's limit, with its
  `timeouts.seconds` floor. It patches only the Infection releases the gate
  supports. Unpatched, each mutant keeps Infection's own limit, and every run
  says so in its report.

The action and the reusable workflow apply both patches in their own jobs,
the Pest patch where `pest.patch` is on and the Infection patch where the
runner is Infection, so on GitHub the Composer hooks are needed only for runs
elsewhere. An Infection release the patch does not patch runs unpatched: the
step warns, in its log and the step summary, and the run goes on with
Infection's own limit. A release it supports but cannot patch fails the step.

The proof ledger's trust boundary is the store's access control. Withholding a
variable keeps it out of the tests' environment, not out of the project's
reach: a job that runs the project's tests or loads its config hands its code
every secret the job holds. Give write credentials only to the default
branch's runs
([ADR-0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)).
On GitHub, cache scoping keeps a pull request from writing what the default
branch reads.
On GitLab, separate caches for protected branches do the same, and they also
keep merge requests from reading the default branch's ledger. On Azure DevOps a
pull request build reads the target branch's caches and cannot write them. On
Buildkite and CircleCI a branch's pipeline config picks its cache key,
Bitbucket's caches are shared by every branch, and Jenkins has no cache, so a
cache is no boundary there.
Wherever a pull request must read the default branch's proofs safely, keep the
ledger in S3, Cloud Storage or Azure Blob Storage, with credentials that can
write the default branch's prefix held only by default-branch runs
([ADR-0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)).
With an AWS OIDC role, make its trust policy match a GitHub environment that
only the default branch may deploy to, or the verdict workflow's
`job_workflow_ref`, never `ref: refs/heads/main` alone: every job a workflow
runs on the default branch carries that `ref`, whatever started it
([ADR-0019](.docs/decisions/0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md)).

The `gcs` and `azure` stores take their token from OIDC federation alone, over
plain HTTP, so they need no package beyond the gate. `gcs` exchanges the
external-account file `google-github-actions/auth` writes, and refuses a
service-account key (exit 2). `azure` exchanges GitHub's OIDC token for the
tenant and client `azure/login` reads, and has no option for an account key or
a shared access signature. Bind the identity to a GitHub environment that only
the default branch may deploy to, used only by the verdict job, or to the
verdict workflow's `job_workflow_ref`, never to a bare `ref`. A repository
created after 2026-07-15 issues `sub` as
`repo:<owner>@<owner id>/<repo>@<repo id>:…`, and a credential that matches
`sub` exactly has to use that form
([ADR-0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md)):

```sh
# Cloud Storage: a provider only the verdict environment's runs pass
gcloud iam workload-identity-pools create github --location=global
gcloud iam workload-identity-pools providers create-oidc github --location=global \
  --workload-identity-pool=github --issuer-uri=https://token.actions.githubusercontent.com \
  --attribute-mapping=google.subject=assertion.sub,attribute.repository_id=assertion.repository_id \
  --attribute-condition="assertion.repository_id == '<repo id>' && assertion.environment == 'mutation-verdict'"
gcloud storage buckets add-iam-policy-binding gs://<bucket> --role=roles/storage.objectUser \
  --member=principalSet://iam.googleapis.com/projects/<project number>/locations/global/workloadIdentityPools/github/attribute.repository_id/<repo id>

# Azure: a federated credential on the verdict environment's subject
az ad app federated-credential create --id <app id> --parameters '{"name": "mutation-verdict",
  "issuer": "https://token.actions.githubusercontent.com", "audiences": ["api://AzureADTokenExchange"],
  "subject": "repo:<owner>/<repo>:environment:mutation-verdict"}'
az role assignment create --assignee <app id> --role "Storage Blob Data Contributor" \
  --scope /subscriptions/<subscription>/resourceGroups/<group>/providers/Microsoft.Storage/storageAccounts/<account>
```

To bind the workflow instead, the Cloud Storage condition tests
`assertion.job_workflow_ref`, and on Azure the `sub` GitHub issues has to
include `job_workflow_ref`, or the credential is a flexible one.

A fork's pull request runs without credentials. On GitHub's cache it restores
the default branch's ledger read-only, as any pull request does. With S3, set
`proofs.store.with.publicUrl` and give the bucket a policy that allows a public
`GetObject` on `<prefix>/refs/heads/<default branch>/*` and nothing else. A run
without both `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` sends the bucket
no request. It reads the default branch's ledger alone, with an anonymous GET
from `<publicUrl>/<prefix>/refs/heads/<default branch>/ledger.json.gz`, writes
nothing, and says *read-only: no credentials; this run's proofs are not kept*.
A 404 reads as an empty ledger. Any other refusal, a redirect, no answer within
a minute, or a ledger past 11 MB, or past 38 MB decompressed, is not read, and
the verdict warns why. Until the default branch's first run writes its ledger,
S3 answers 403, and the verdict warns of that. Without `publicUrl`, such a run
reads nothing. On Cloud Storage, `publicUrl` is
`https://storage.googleapis.com/<bucket>`, over a managed folder that `allUsers`
may read. On Azure, the store writes the default branch's scope to
`publicContainer`, a container at the `Blob` access level, and every other
scope to `container`, and `publicUrl` is the public container's URL. An account
whose `AllowBlobPublicAccess` is off refuses every anonymous read, and
`doctor --online` reports it ([ADR-0028](.docs/decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md)):

```sh
gcloud storage buckets update gs://<bucket> --uniform-bucket-level-access --no-public-access-prevention
gcloud storage managed-folders create gs://<bucket>/mutation-gate/refs/heads/main/
gcloud storage managed-folders add-iam-policy-binding gs://<bucket>/mutation-gate/refs/heads/main/ \
  --member=allUsers --role=roles/storage.objectViewer

az storage account update -n <account> --allow-blob-public-access true
az storage container create -n <public container> --account-name <account> --public-access blob --auth-mode login
```

A fork can plant no proof that another run trusts. It can
influence only its own verdict, which its own workflow file could anyway, so
require approval before outside contributors' workflows run
([ADR-0013](.docs/decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)).

### Credentials in a job of their own

A job that runs the project's code hands it every secret the job holds. So
the store's keys, the comment's token, the alert URLs and the OpenTelemetry
headers can go to jobs of their own that run none of it. Two commands run in
such jobs, from the gate's own installation, with no config read and no
extension loaded. Each refuses to start through Composer's proxy, or from the
working directory's `vendor`
([ADR-0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)).

- **`deliver`** sends what a run left in `.mutation-gate/delivery`.
  `plan --deliver-later` leaves its comment's planned state in
  `.mutation-gate/delivery/planned`, `survivors --deliver-later` the
  re-checked survivors, which `deliver` writes only over that planned
  state, in `.mutation-gate/delivery/survivors`, and `verdict --deliver-later` its
  ledger, the coverage map it keeps, comment, alerts and export in
  `.mutation-gate/delivery/verdict`;
  each sends nothing that needs a credential and writes no store, and `deliver
  --from=<that directory>` sends it. A delivery directory holds
  `delivery.json`, payloads only: the ledger's scope, the
  comment's markdown, each alert's body (no more to one channel than a
  verdict sends), the OTLP export and the scope each kept object is for.
  Beside it are the ledger, `ledger.json.gz`, and the coverage map,
  `coverage.json.gz`. Each is read within its own byte limits, and a key `deliver` does not take, such as a URL, a host or a
  variable's name, refuses the whole delivery. Every destination and every
  credential comes from `deliver`'s own environment:
  - the comment goes to the pull request its own event names, with
    `GITHUB_TOKEN`;
  - each alert goes to `MUTATION_GATE_SLACK_URL`, `MUTATION_GATE_DISCORD_URL`
    or `MUTATION_GATE_WEBHOOK_URL`, signed with `MUTATION_GATE_WEBHOOK_SECRET`.
    A `with: {urlEnv: …}` or `with: {secretEnv: …}` in the config is not
    read, so set these default variables;
  - the export goes to `OTEL_EXPORTER_OTLP_ENDPOINT` with
    `OTEL_EXPORTER_OTLP_HEADERS`. A `with: {endpoint: …}` is not read;
  - the ledger goes to the store the variables below locate. `deliver`
    writes it only on GitHub Actions, only on a push, a schedule or a manual
    run of the default branch, and only where the delivery's scope is that
    branch's. It decides that from its own event and ref before it reads the
    delivery. The default branch is the one the event payload names, else
    `MUTATION_GATE_DEFAULT_BRANCH`. On any other run it writes no ledger and
    fails nothing. The coverage map is kept beside the ledger on the same
    runs, for the same scope.
- **`fetch`** reads the default branch's ledger, and no other scope's, from
  the store the variables below locate, with a key that only needs to read.
  It writes the ledger into `.mutation-gate/ledger`, where the `directory`
  store, the default, reads it, so the plan, the shards and the verdict hold
  no credential. It writes nothing to the store. A ledger it cannot read,
  or a job without the key, costs a run, never a verdict: `fetch` says why
  and exits 0, as it does where `MUTATION_GATE_STORE` names no store. A public repository can read the default branch's ledger from
  `publicUrl` instead, as a fork's run does.

`deliver` and `fetch` take the store's whole location from their own
environment, never from a delivery or a config:

| Variable | What it sets |
|----------|--------------|
| `MUTATION_GATE_STORE` | The store: `s3`, `gcs` or `azure` |
| `MUTATION_GATE_STORE_BUCKET` | `bucket`, for `s3` and `gcs` |
| `MUTATION_GATE_STORE_PREFIX` | `prefix` (`mutation-gate` by default) |
| `MUTATION_GATE_STORE_REGION` | `region`, for `s3` (`us-east-1` by default) |
| `MUTATION_GATE_STORE_ENDPOINT` | `endpoint`, for `s3` alone, and only an `https://` URL |
| `MUTATION_GATE_STORE_ACCOUNT` | `account`, for `azure` |
| `MUTATION_GATE_STORE_CONTAINER` | `container`, for `azure` |
| `MUTATION_GATE_STORE_PUBLIC_CONTAINER` | `publicContainer`, for `azure` |

The store's credentials are its usual variables: `AWS_ACCESS_KEY_ID` and
`AWS_SECRET_ACCESS_KEY` for `s3`, the federation's or
`MUTATION_GATE_GCS_TOKEN` for `gcs`, and the federation's or
`MUTATION_GATE_AZURE_TOKEN` for `azure`
([ADR-0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)).

On GitHub Actions, `gcs` and `azure` federate the job's own OIDC token, so
the jobs that run `deliver` and `fetch` hold no long-lived secret and run no
third-party action:

- `gcs` takes `MUTATION_GATE_GCS_PROVIDER`, the workload identity provider,
  `projects/<number>/locations/global/workloadIdentityPools/<pool>/providers/<id>`,
  and `MUTATION_GATE_GCS_SERVICE_ACCOUNT`, the service account it
  impersonates, `<name>@<project>.iam.gserviceaccount.com`. It asks GitHub for
  the token for the provider's default audience,
  `https://iam.googleapis.com/<provider>`, and sends it only to
  `sts.googleapis.com` and `iamcredentials.googleapis.com`.
- `azure` takes `AZURE_TENANT_ID` and `AZURE_CLIENT_ID`, as `azure/login`
  does.
- Each job that holds them needs `permissions: id-token: write`. A reusable
  workflow can grant it to a job only where the calling workflow grants it
  too, so the caller's own `permissions` holds `id-token: write`. Without it,
  GitHub hands the job no token, and the store says so.

### Use it in GitHub Actions

The repository is also a GitHub Action. Pin it to a full commit SHA, with its
tag in a comment. Each release has its own tag, such as `v0.1.0`, and a
release workflow moves the tag of its line, `v0.1`, to each new release of
0.1.

**One action in two jobs, for most projects.** In the first job the action
sets up PHP, installs your dependencies, keeps the proof ledger in the
Actions cache and runs the whole gate. It writes line annotations and the
step summary, and holds no token once your code runs. In the second job,
with `deliver: 'true'`, it runs none of your code. It posts the sticky PR
comment and, on a trusted run, writes the ledger to a store.

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
    name: mutation / verdict # the check ci.check names by default
    runs-on: ubuntu-latest
    permissions:
      contents: read
      actions: read
    steps:
      - uses: actions/checkout@<sha> # <tag>
        with:
          fetch-depth: 0
          persist-credentials: false
      - uses: nightworksio/php-mutation-gate@<sha> # v0.1.0
        with:
          php-version: '8.5'

  # Sends what the run left: the pull request comment, and on a trusted run
  # the ledger a store keeps. It checks nothing out and runs none of the
  # project's code.
  deliver:
    needs: mutation
    if: ${{ !cancelled() }}
    runs-on: ubuntu-latest
    permissions:
      contents: read
      pull-requests: write
      id-token: write # only for a store that signs in through OIDC
    steps:
      - uses: nightworksio/php-mutation-gate@<sha> # v0.1.0
        with:
          deliver: 'true'
          php-version: '8.5'
```

| Input | Default |
|-------|---------|
| `config` | the config file the gate finds |
| `php-version` | `8.5` |
| `runner` | the config's, or the one installed |
| `shard` | none: the whole gate runs |
| `mode` | `auto`: change-scoped on pull requests and pushes, full on schedules, manual runs, releases and tags; or `full`, or `changed` |
| `changed-since` | `last-run` on `pull_request` (the commit the pull request's last run judged, falling back to the default branch), the default branch on any other branch, `last-passed` on a push to the default branch; used when the mode is change-scoped |
| `budget` | none |
| `reports` | none; `<name>:<path>` lines, such as `sarif:build/mutation.sarif` |
| `cache` | `true`: keep the ledger in the Actions cache |
| `deliver` | `false`; `true` in a job of its own, after the run's, to send what the run left |

Its outputs are `verdict` (`passed`, `failed` or `cannot-judge`), `scores`
(JSON), `report-paths` (JSON) and `plan` (JSON). The one-step action writes
the badge and trend to `.mutation-gate/publish` but does not publish them,
because that needs `contents: write` in a job that also runs on pull requests.
The reusable workflow publishes them.

The run's job runs the project's tests, so it holds no token and no secret:
any step after the tests can be changed by them. What needs a credential, the
pull request comment and the ledger a store keeps, it leaves as the artifact
`mutation-gate-delivery`. The `deliver` job sends it with the job's token,
running the gate from its own copy at the action's path, installed from the
gate's own lock with no scripts or plugins, never from the project's `vendor`.
The directory store is the Actions cache, which the run's job keeps itself, so
a project that keeps its ledger there needs only that token.

For an S3 store, add `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` as secrets
of the environment `mutation-gate-store`, whose deployment branches are the
default branch alone, name the store in the repository variables
`MUTATION_GATE_STORE` and its location, as for the reusable workflow below, and
hand them to the `deliver` job's step. Azure and GCS sign in through GitHub's
OIDC instead, from the repository variables `AZURE_TENANT_ID` and
`AZURE_CLIENT_ID`, or `MUTATION_GATE_GCS_PROVIDER` and
`MUTATION_GATE_GCS_SERVICE_ACCOUNT`. The job `init --ci=github --single`
writes enters that environment on a push, a schedule or a manual run of the
default branch, and none on any other run, so a pull request's run reads the
store through `proofs.store.with.publicUrl`. A branch can edit the workflow,
so the environment's restriction, not the job's condition, keeps the keys from
other branches.

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
    uses: nightworksio/php-mutation-gate/.github/workflows/mutation-gate.yml@<sha> # v0.1.0
    permissions:
      contents: write        # used only by the default-branch publish job
      actions: read
      pull-requests: write
      id-token: write        # used only by fetch and deliver, for a store that signs in through OIDC
    with:
      php-version: '8.5'
```

It takes the action's inputs less `shard`. Its outputs are `verdict`, `scores`
and `plan`, and it uploads the reports as the artifact `mutation-gate-reports`.

The `plan`, `shard` and `verdict` jobs run the project's own code, so they hold
no secret and no token that can write. Whatever needs a credential runs in a
job that installs none of the project's code and runs the gate from its own
installation, at the workflow's commit:

- `fetch` reads the default branch's ledger from the proof store, and hands it
  to the plan and the verdict as the artifact `mutation-gate-fetched`, which
  they read where the `directory` store keeps it, so the project's config
  keeps `proofs.store` at `directory`. It runs
  where the repository variable `MUTATION_GATE_STORE` names a store, in the
  environment `mutation-gate-read`.
- `deliver-plan` posts the pull request comment's planned state, which the plan
  leaves as the artifact `mutation-gate-delivery-plan`.
- On a pull request, `survivors` runs the last run's survivors again beside
  the shards, and `deliver-survivors` writes the comment's *survivors
  re-checked* state over its planned one, from the artifact
  `mutation-gate-delivery-survivors`. The verdict judges nothing it found.
- `deliver` sends what the verdict leaves as the artifact
  `mutation-gate-delivery`. On every run it posts the comment. A trusted run, a
  push, schedule or dispatch on the default branch, enters the environment
  `mutation-gate-store`, and there it also writes the ledger, sends the alerts
  and exports the trace. GitHub refuses that environment to a run on any other
  branch, even one whose workflow names it.

To keep the ledger in S3, R2 or MinIO, set these under Settings:

1. **Repository variables**: `MUTATION_GATE_STORE` set to `s3`, and the
   store's location, `MUTATION_GATE_STORE_BUCKET`,
   `MUTATION_GATE_STORE_PREFIX`, `MUTATION_GATE_STORE_REGION` and, for R2 or
   MinIO, `MUTATION_GATE_STORE_ENDPOINT`, an `https://` URL. A location is no
   secret. The two jobs read it from these variables alone, never from a run
   of the project's code.
2. **The environment `mutation-gate-read`**, with no deployment branch rule and
   the secrets `MUTATION_GATE_READ_AWS_ACCESS_KEY_ID` and
   `MUTATION_GATE_READ_AWS_SECRET_ACCESS_KEY`. Their key may only read the
   default branch's ledger: `s3:GetObject` on
   `<bucket>/<prefix>/refs/heads/<default branch>/*`. Every run holds it, a
   pull request's among them. A fork's pull request is given no secret, and
   reads through `proofs.store.with.publicUrl` instead.
3. **The environment `mutation-gate-store`**: under Deployment branches and
   tags choose Selected branches and tags, and add the default branch's name
   as its one rule. Its secrets are `AWS_ACCESS_KEY_ID`,
   `AWS_SECRET_ACCESS_KEY` and, for temporary keys, `AWS_SESSION_TOKEN`,
   whose key may read and write the default branch's ledger alone:
   `s3:GetObject` and `s3:PutObject` on
   `<bucket>/<prefix>/refs/heads/<default branch>/*`. For alerts and traces,
   add `MUTATION_GATE_SLACK_URL`, `MUTATION_GATE_DISCORD_URL`,
   `MUTATION_GATE_WEBHOOK_URL`, `MUTATION_GATE_WEBHOOK_SECRET`,
   `OTEL_EXPORTER_OTLP_ENDPOINT` and `OTEL_EXPORTER_OTLP_HEADERS` there too.

Add the secrets to the environments, never to the repository: a repository
secret reaches a run on any branch.

A public repository may read the ledger without a key instead: make the
default branch's prefix public in the bucket policy, set
`proofs.store.with.publicUrl`, and give `mutation-gate-read` no secrets. The
plan and the verdict then read the ledger through that URL, and `fetch` hands
over none.

GitHub may give a scheduled run no default branch name. Set the repository
variable `MUTATION_GATE_DEFAULT_BRANCH` to that name, and a scheduled run reads
it there. Without either, a scheduled run holds no secrets and says so in the
deliver job's summary.

The reusable workflow reaches S3 with those key secrets only. To assume an AWS
role through OIDC instead, use the action's two jobs: the `deliver` job already
has `id-token: write`, so add `aws-actions/configure-aws-credentials` before
the action there, and trust the role as described above: an environment only the
default branch may deploy to, or the job's `job_workflow_ref`.

Here is what the examples rely on:

- **Branch protection** should require the verdict's check, `mutation /
  verdict` in both examples: the action's first job is named so, and the reusable
  workflow's `verdict` job shows as `<calling job> / verdict`. It is also
  `ci.check`'s default, the check through which a merged pull request's
  verdict proves its commit; a job named otherwise needs `ci.check` set to its
  name. The verdict says *cannot judge* (exit code 2) when the plan could not
  judge, or when any planned shard left no result.
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
    MUTATION_GATE_SOURCE: $CI_PIPELINE_SOURCE
  trigger:
    include:
      - artifact: .mutation-gate/pipeline.yml
        job: mutation-plan
    strategy: mirror
```

The child pipeline holds one job with `parallel: matrix` over the shards and
a verdict that runs even after a failed shard, and the `mutation` trigger job
takes its result. Keep `.mutation-gate/ledger` in a `cache:` keyed by branch,
such as `key: mutation-gate-ledger-$CI_COMMIT_REF_SLUG`.

For an S3 store, set `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` as
protected, masked CI/CD variables, which only protected branches' pipelines
see, never a merge request's; keep the default branch the only protected one,
or trust everyone who may push to one. The verdict is two jobs, of which one
runs: `mutation-gate-verdict-store` on a push, a schedule or a manual run of
the default branch, which `MUTATION_GATE_SOURCE` tells the child pipeline,
since a child's own `CI_PIPELINE_SOURCE` is `parent_pipeline`; and
`mutation-gate-verdict` on every other. The hidden job's `before_script` drops
every variable the S3 store reads before `composer install` in every job but
`mutation-gate-verdict-store`, so the plan and the shards, which run the
project's tests, read the store through `proofs.store.with.publicUrl`. A
branch can edit the pipeline, so the variables' protection, not the jobs'
conditions, keeps the keys from other branches.

### Buildkite

```yaml
steps:
  - label: "mutation: plan"
    artifact_paths: ".mutation-gate/**/*"
    command:
      - unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN AWS_ROLE_ARN
      - composer install
      - |
        if [ "$BUILDKITE_PULL_REQUEST" != "false" ]; then base="--changed-since=origin/$BUILDKITE_PULL_REQUEST_BASE_BRANCH"
        elif [ "$BUILDKITE_SOURCE" = "schedule" ]; then base=""
        else base="--changed-since=last-passed"; fi
        vendor/bin/mutation-gate plan --ci=buildkite $base | buildkite-agent pipeline upload
```

The uploaded steps are one step per shard, a `wait` that continues on failure,
then the verdict, as two steps of which one runs. Each is built from your step
template (`ci.buildkite.step`), and they pass the plan and results with
`buildkite-agent artifact`. Schedule the pipeline weekly for the full run.

A cache plugin keyed by branch can keep `.mutation-gate/ledger` between builds,
but any branch can save a cache under any key on Buildkite, so a branch can
plant proofs the default branch then trusts. Keep the ledger in S3 with
credentials only the default branch's runs hold, and drop the cache steps. For
an S3 store, hold `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` as the
cluster secrets `MUTATION_GATE_STORE_AWS_ACCESS_KEY_ID` and
`MUTATION_GATE_STORE_AWS_SECRET_ACCESS_KEY`, whose access policy allows only
builds of the default branch, and never set them on the agents. A tag build's
branch is the tag's name, so have the policy refuse tag builds, or keep anyone
from pushing a tag named as the default branch. The verdict step
`mutation-gate-verdict-store` fetches the keys with `buildkite-agent secret
get` only on a push, a schedule, an API or a manual build of the default branch
that is neither a pull request nor a tag; its keyless twin,
`mutation-gate-verdict`, runs on every other build. A branch can edit the pipeline, so the secrets' access
policy, not the step's condition, keeps the keys from other branches. The plan
runs the whole suite, so it drops the keys before `composer install`, and
reads the store through `proofs.store.with.publicUrl`.

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
  mutation-store:
    when: &trusted
      and:
        - or:
            - equal: [webhook, << pipeline.trigger_source >>]
            - equal: [scheduled_pipeline, << pipeline.trigger_source >>]
            - equal: [api, << pipeline.trigger_source >>]
        - equal: [main, << pipeline.git.branch >>]
    jobs:
      - mutation-plan
      - mutation: { requires: [mutation-plan] }
      - mutation-verdict: { context: [mutation-gate-store], requires: [{ mutation: terminal }] }
  mutation:
    unless: *trusted
    jobs:
      - mutation-plan
      - mutation: { requires: [mutation-plan] }
      - mutation-verdict: { requires: [{ mutation: terminal }] }
```

`main` stands for your default branch. Add a weekly scheduled pipeline for the
full run. The verdict requires `mutation` with the status `terminal`, so it
runs, and says *cannot judge*, even when a shard failed.

Any branch can save a cache under any key on CircleCI, so a branch can plant
proofs the default branch then trusts. Keep the ledger in S3 with credentials
only the default branch's runs hold, and drop the cache steps. For an S3
store, set `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` in the context
`mutation-gate-store`, restricted by the expression
`pipeline.git.branch == "main"`. Only `mutation-verdict` uses it, in the
workflow `mutation-store`, which runs on a push, a schedule or an API trigger
of the default branch; every other run takes the workflow `mutation`, with no
context. A branch can edit the config, so the context's restriction, not the
workflow's condition, keeps the keys from other branches. The plan runs the
whole suite, so it never holds the keys, and reads the store through
`proofs.store.with.publicUrl`, as every other run's verdict does.

### Azure DevOps

`init --ci=azure` writes the gate's jobs to `.azure/mutation-gate.yml` and
prints the lines that take them into the pipeline Azure DevOps runs. A config
it writes names that file as `ci.azure.definition`, and sets
`ci.defaultBranch`, since Azure DevOps names no default branch. Where a config
is kept, `init` says which of the two to set:

```yaml
jobs:
  - template: '.azure/mutation-gate.yml'
```

`plan --ci=azure` sets the plan step's output variable `matrix` to one leg per
shard, `{"s1": {"SHARD": "1"}, …}`, which the `mutation` job's
`strategy: matrix` reads. A plan with no shards sets one leg, `none`, whose
empty `SHARD` runs nothing, because Azure always makes at least one job. The
verdict runs with `condition: succeededOrFailed()`, so it says *cannot judge*
even when a shard failed. The `Cache@2` task keeps the ledger keyed by the
digest of the run's scope, and restores the default branch's second where
Azure lets the run read that branch's caches: a pull request reads its
target's, and any other run reads only `main`'s and `master`'s besides its
own. A cache is saved only by a job that succeeds, so a last job, which runs
whatever the verdict decided, saves the ledger the verdict wrote. A pull
request is named by `System.PullRequest.PullRequestNumber` where Azure sets it,
as for a GitHub repository, and by its id otherwise.

For an S3 store, the keys, `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`, are
secret variables of the variable group `mutation-gate-store`, whose Branch
control check allows the default branch alone. The verdict job takes the group
in, and maps the keys into its step, only on a push, a schedule or a manual run
of the default branch. A branch can edit the template, so the check, not the
template's condition, keeps the keys from other branches. The plan runs the
whole suite, so it never holds them. It, and the verdict step where
`System.PullRequest.IsFork` is `True`,
drop every variable the S3 store reads, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`, `AWS_ROLE_ARN`,
before the gate runs: a fork's build gets no secrets, and Azure hands it a
mapped one as the literal text `$(NAME)`. Every run without the keys
reads the default branch's ledger through `proofs.store.with.publicUrl`, so set
it as described above. The gate withholds `SYSTEM_ACCESSTOKEN` and
`AZURE_DEVOPS_EXT_PAT` from the tests. Schedule the pipeline on the
default branch twice a week, with `always: true`, for the full run.

### Bitbucket Pipelines

`init --ci=bitbucket` prints the pipelines to add to `bitbucket-pipelines.yml`:
the default branch's, every pull request's and the custom pipeline
`mutation-full`'s. A config it writes sets `ci.defaultBranch`, since Bitbucket
names no default branch, and where a config is kept without it, `init` says to
set it. Bitbucket's parallel steps are fixed in the file, so the plan
cuts exactly as many shards with `--shards`, and each step reads its shard
from `BITBUCKET_PARALLEL_STEP`, which counts from 0. A pull request is named
by `BITBUCKET_PR_ID`. Schedule the custom pipeline `mutation-full` on the
default branch twice a week for the full run.

Bitbucket's caches are shared by every branch, so the ledger lives in S3. Its
keys, `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`, are variables of the
deployment environment `mutation-gate-store`, and only a step that deploys to
it sees them. The verdicts of the default branch's pipeline and of
`mutation-full` deploy to it and write the ledger. Restrict the environment's
deployments to the default branch, which takes Bitbucket Premium: without that,
any branch whose pipeline names the environment gets the keys. Repository and
workspace variables, secured or not, reach every branch's pipeline, so they
cannot hold the keys.

Bitbucket runs a deployment only as an ordinary step, never as the `final` step
that runs after a failed one. On the default branch, a failed shard therefore
skips the verdict: the pipeline is red, with no *cannot judge* report. While
one pipeline deploys to `mutation-gate-store`, Bitbucket pauses any other at
its verdict; once the first ends, resume the paused pipeline or rerun it. A
pull request's verdict is a `final` step with no keys, which runs whatever the
shards did. Every step but the deploying verdict, the plan on the default
branch included, reads the default branch's ledger through
`proofs.store.with.publicUrl`, so set it as described above. A pull request
from a fork starts no pipeline. The gate withholds `BITBUCKET_STEP_OIDC_TOKEN`
from the tests.

With Premium, a dynamic pipeline sizes the parallel group to the plan instead.
The plan step runs `plan` without `--shards`, so the cost model picks the
count, then writes a pipeline with one step per shard of
`.mutation-gate/plan.json`, each running
`vendor/bin/mutation-gate run --plan=.mutation-gate/plan.json --shard=<id>`,
and the verdict after them, and uploads it with Atlassian's pipe:

```yaml
- step:
    name: 'mutation: plan'
    script:
      - composer install --no-interaction --no-progress
      - vendor/bin/mutation-gate plan --changed-since=last-passed
      - <your script that writes generated-pipeline.yml from .mutation-gate/plan.json>
      - pipe: atlassian/bitbucket-upload-generated-pipeline:1.0.0
        variables:
          GENERATED_PIPELINE_FILE: generated-pipeline.yml
```

### Jenkins

`init --ci=jenkins` prints a declarative pipeline to add to the Jenkinsfile
`ci.jenkins.definition` names, `Jenkinsfile` by default, which it never edits.
Run it from a multibranch pipeline: Jenkins names the branch in `BRANCH_NAME`,
a pull request in `CHANGE_ID` and its target in `CHANGE_TARGET`, and a tag in
`TAG_NAME`, and only a multibranch project sets them. A config `init` writes
sets `ci.defaultBranch`, since Jenkins names no default branch, and where a
config is kept without it, `init` says to set it. The pipeline takes the
Pipeline Utility Steps plugin, for `readJSON`, and the Credentials Binding
plugin.

`plan --ci=jenkins` prints the plan as JSON. The pipeline reads it with
`readJSON`, hands `parallel` one closure per shard, each on an agent of its
own and naming its shard in `SHARD`, and passes files between them with
`stash` and `unstash`. The verdict runs in `post { always { … } }`, whatever
the shards did. The pipeline's `cron` trigger starts the full run on the
default branch twice a week. The plan fetches the branch it compares against
with `git fetch`, so leave the clone whole and let the agents fetch from
`origin`.

Jenkins has no cache, so the ledger lives in S3. Its keys, `AWS_ACCESS_KEY_ID`
and `AWS_SECRET_ACCESS_KEY`, are the username and password of
the credentials `mutation-gate-store`, which only the default branch's verdict binds. A
credential reaches every build of the folder that holds it, and a branch's
author writes its Jenkinsfile, so any branch whose Jenkinsfile Jenkins runs can
bind it. To keep the keys from other branches, hold them in a folder whose
multibranch pipeline builds the default branch alone, apart from the one that
builds every other branch and pull request; otherwise trust only authors who
may push to the default branch, in the branch source's trust setting. Every
other step reads the default branch's ledger through
`proofs.store.with.publicUrl`, so set it as described above. Jenkins hands a
build no credential of its own, so the gate withholds nothing more from the
tests than every run does; add any other credential the pipeline binds to
`runner.withhold`.

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
branch. Proofs can also live in S3, R2, Cloud Storage or Azure Blob Storage
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

In VS Code, the task `init --editor=vscode` writes shows each survivor in
the Problems list, beside its line. In PhpStorm, add an External Tool
(*Settings | Tools | External Tools*):

- **Program:** `vendor/bin/mutation-gate`
- **Arguments:** `run --output=problems --only=changed`
- **Working directory:** `$ProjectFileDir$`
- **Output filter** (*Advanced Options*): `$FILE_PATH$:$LINE$:$COLUMN$`

Each survivor in the Run window then links to its line.

`.mutation-gate/` holds local results and proofs. It belongs in `.gitignore`,
and `init` adds it there.

A run under a budget, as `watch` and `pre-push` are, counts each unit it
never started by its newest result where that result still stands for the
code: its survivors always, and a kill proved at an earlier commit where
nothing that changed since reaches the unit, or a test that killed it, by a
name the code uses. For a kill by static analysis, the file the analyser's
finding sits in takes the place of the tests. A file that is not PHP, or a
PHP file that runs code when it is loaded, is named by the words of its file
name. A change to a file that decides how the gate runs, such as
`composer.json`, `composer.lock` or the gate's config, or to a file
`autoload.files` lists, carries no kill. Following names misses a class
wired only in YAML or XML service definitions, or that a container builds
for a key other than its name, a name built by concatenation, a call through
`__call` onto a class nothing names, a file read by a path built from pieces
or found by listing a directory; a full run judges those again. In CI, a
kill carries only where the clone holds the commit it was proved at, which
`fetch-depth: 0` makes sure of.

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
