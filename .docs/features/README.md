# Features

Everything the gate does, by what it is for. Each section links the page that
explains it, and each feature the decision that says why it works as it does.

## Floors and the score

More in [floors and the score](floors.md).

| Feature | Decided in |
|---------|------------|
| Floors that only rise: a committed baseline, which fails on regression and rises on improvement | [0003](../decisions/0003-a-floor-only-rises.md) |
| A first CI run with no baseline measures, then hands over the baseline to commit | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| Floors per owner, judged over every file CODEOWNERS gives the owner | [0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| Security mode: mutators for authorisation, CSRF, escaping and constant-time comparisons, held to a floor of their own | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| Ignores for equivalent mutants, and by its id for a mutant whose file loads before Pest can put it in place, each with a reason and an optional expiry | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| Survivors the compiler proves equivalent, left out of the score | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| A score per test suite | [0025](../decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |

## What a change reaches

More in [what a change reaches](reach.md).

| Feature | Decided in |
|---------|------------|
| Pull-request mode: changed lines and what the change reaches, with a stricter floor for new code | [0003](../decisions/0003-a-floor-only-rises.md), [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| Monorepos: a floor per package and module, with reach that follows the dependencies | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `affected`: the tests a change can reach, for plain test runs | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| Holding tests: Pest `holds:` groups and a `#[Holds]` attribute, a check that a group covers what it holds, and a warning for code every test runs through that nothing holds | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |

## Trust

More in [why a verdict can be trusted](trust.md).

| Feature | Decided in |
|---------|------------|
| A kill counts only with evidence: a test named as its killer (one that failed or errored, raised an issue the runner fails the run on, or whose dataset the mutant left empty), or a signal or a fatal error that ended its process; any other is unjudged, with its exit code and output | [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| A named killer must pass on the unmutated code, served as its mutant was, or the kill is unjudged | [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| Triage of timeouts and flaky tests: a timeout counts as a kill only where its tests, run unmutated under the same limit, finish within it | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| A mutant out of the memory cap counts as a kill only where its tests, run unmutated under the same cap, finish under it, measured alike for every runner | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| A time budget that runs the riskiest code first, and reports anything unjudged instead of passing it | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| Once a survivor of a floor of 100 makes a pull request's verdict certain to fail, after static analysis has not cleared it, its shard fails: by default after judging every unit, or at once under `run.failFast: stop`, or cancelling the other shards under `cancel`; a run that judged on says what stopping would have saved | [0103](../decisions/0103-a-doomed-shard-fails-fast.md) |
| A fork's pull request reads the default branch's proofs, read-only | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| Merge queues: a `merge_group` run judges what will land, trusting no pull request's own proofs | [0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |

## Speed

More in [speed](speed.md).

| Feature | Decided in |
|---------|------------|
| A cost model that learns how long each file takes from earlier shards | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| Sharding on any CI: GitHub Actions, GitLab, Buildkite, CircleCI, Azure DevOps, Bitbucket Pipelines, Jenkins, or a JSON plan | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| A proof cache keyed by content, stored in the GitHub cache, a directory, S3/R2, Google Cloud Storage or Azure Blob, with OIDC and a public read path for forks | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md), [0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| Likely killers first: each mutant's tests ordered by which of them killed it before | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| A shard count chosen from a target wall time | [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| A static analyser (Mago, PHPStan or Psalm) kills the mutants it rejects, before their tests where that saves time | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| A new push re-checks the previous survivors first, before its shards run | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| A sampled mode for huge repositories: each tree estimated with a confidence interval, judged on its bounds | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| Coverage re-measured only for the tests whose inputs moved | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| Every core of a shard's runner busy, and one warm worker per core for native runners | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| Mutators that never let a mutant through are pruned on unchanged code, and audited weekly | [0025](../decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| Mutants that leave their file alike run once and share one verdict, under Pest and the PHPUnit, PhpSpec, Codeception and Testo runners | [0025](../decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| A benchmark against plain Pest and Infection on four open-source projects: cold, warm and per pull request, losses included, run by `bench.yml` and `composer bench` | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |

## Runners and mutators

More in [runners and mutators](mutators.md).

| Feature | Decided in |
|---------|------------|
| Infection as well as Pest | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| A native runner for PHPUnit, on the gate's own mutants | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| Native runners for PhpSpec and Codeception, on the gate's own mutants | [0027](../decisions/0027-codeception-phpspec-and-testo-get-native-runners.md) |
| A native runner for Testo, on the gate's own mutants | [0027](../decisions/0027-codeception-phpspec-and-testo-get-native-runners.md), [0047](../decisions/0047-testos-runner-holds-a-minor-range-and-its-plugin-selects-stops-and-times.md) |
| Presets for Laravel, Symfony and plain libraries | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| Custom mutators, written once for Pest and Infection against a typed SDK | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| Laravel and Symfony mutator sets, turned on by their presets | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| A hint that a surviving removal may be dead code | [0025](../decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |

## Reporting

More in [reporting](reporting.md).

| Feature | Decided in |
|---------|------------|
| JSON, JUnit and SARIF, and line annotations on GitHub | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| An HTML report | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| A digest for agents: the verdict one fact a line, the same verdict as the JSON and HTML reports at a cost in tokens that does not grow with the run, with depth one file, mutant or cluster at a time | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| A sticky comment on the pull request, posted when the plan is made with the units it mutates, the time it should take and the changed lines no test runs, then updated with the verdict, which links the run's HTML report | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md), [0019](../decisions/0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md) |
| A cost estimate in the PR comment: time planned, measured and spared, and money at the team's rate | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| Every run says what it saved against a full run in one job: reach and proofs, and what sharding saved in waiting and cost in runner time | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| Progress and an ETA during a run | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| A badge (a shields.io endpoint) and a trend on the default branch | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| Chat alerts to Slack, Discord or a webhook when the default branch fails, cannot be judged, or recovers | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| Run metrics as OpenTelemetry traces and metrics, and in the JSON report | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| Survivors in GitLab's Code Quality report | [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| The sticky comment and its planned state on GitLab merge requests and Bitbucket pull requests, with survivors in Bitbucket's Code Insights | [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md), [0048](../decisions/0048-the-gitlab-and-bitbucket-comments-relay-the-delivery-and-bitbucket-finds-its-own-by-editing.md) |
| Survivors in SonarQube's issue list, through its generic external-issues format | [0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| A useless-test report: tests that cover code and kill none of it | [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| A redundant-test report: tests whose every kill another test also makes | [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| A kill-matrix export, as CSV and in the JSON and HTML reports | [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| Weak assertions, found and paired with the survivors they let through | [0025](../decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| Survivors grouped by code owner, from the CODEOWNERS at the base, with each group's score in the JSON report | [0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| Owners mentioned once per pull request | [0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md), [0049](../decisions/0049-an-owner-is-mentioned-once-and-a-merge-group-trusts-its-pull-request-as-a-merged-one.md) |
| Survivors with one cause clustered into one item with one suggested test | [0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| One command that reproduces each survivor | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md), [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| For each survivor, what the tests miss | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `Try: …`: a concrete way to kill each survivor, found by rules from its diff and its file, in the comment, the report and `stub` | [0101](../decisions/0101-rules-find-a-concrete-way-to-kill-each-survivor.md) |
| An organisation dashboard: a static site of every repository's trends, floors and savings | [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `mutators`: what each mutator costs and finds, from the ledger, and each repository's ten costliest on the organisation dashboard | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| Heatmap: every file's and function's score against the base, in the HTML report, the lowest files on the organisation dashboard, and the files that fell in the pull request comment | [0100](../decisions/0100-a-heatmap-scores-every-file-and-function-against-the-base.md) |
| `config:suggest`: config changes with their evidence, turning off mutators that find nothing or make noise and giving slow directories a longer timeout, as a diff it never applies | [0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md) |
| `suggest.enabled` turns every suggestion off, and a context block for each CI (`github`, `gitlab`, `bitbucket`, `azure`, `buildkite`, `jenkins`, `circleci`) lets any key, suggestions among them, differ by CI | [0104](../decisions/0104-suggestions-can-be-turned-off-and-each-ci-has-a-context.md) |

## Adoption

More in [get started](../using/README.md).

| Feature | Decided in |
|---------|------------|
| Zero-config start: trees from `phpunit.xml`'s `<source>`, and an optional config file | [0002](../decisions/0002-one-typed-config-from-several-formats.md) |
| `init`: it detects the runner, preset and CI, and writes the config and the CI | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `init`'s questions: the runner, the CI, an Infection config's import, native markers, the pre-push hook and `composer mutate`, asked where detection cannot settle them, with `--native`, `--hook`, `--no-hook` and `--dry-run` | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `init`'s first-run estimate from one coverage run, with its hot paths, or from lines of code with `--no-measure`; and the trees listed as a comment rather than written | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `doctor`: what would fail or run slowly, and the fix, before a run finds out | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `init --from=infection.json5`: a config taken over from Infection's | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `init --ci`: a ready, pinned workflow for GitHub, GitLab, Buildkite, CircleCI, Azure DevOps, Bitbucket Pipelines or Jenkins | [0015](../decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md), [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `migrate`: a config and a baseline moved to the current format, in any of the four formats | [0026](../decisions/0026-configs-and-baselines-move-forward-with-one-command.md) |
| A signed PHAR and a multi-arch container image beside the Composer package, published by each release | [0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md) |
| `init --hook`: the gate in CaptainHook, GrumPHP and the pre-commit framework | [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `composer mutate`: a root script `init` adds to `composer.json` | [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `doctor --fix`: applies what doctor found after one diff and one question: opcache, the coverage driver, the PHPUnit config, `.gitignore`, the runner patches and stale CI pins | [0083](../decisions/0083-doctor-fixes-what-it-found-after-a-diff.md) |

## Local use

More in [local use](../using/local.md).

| Feature | Decided in |
|---------|------------|
| Watch mode and a pre-push hook | [0010](../decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| A score change before each commit, from a hook that runs nothing and never blocks | [0015](../decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| Survivors inline in VS Code and PhpStorm | [0015](../decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `stub`: a failing Pest or PHPUnit test or PhpSpec example to fill in, for a survivor | [0015](../decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `explain`: a mutant's diff, tests, their outcomes and its history, without running it | [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
