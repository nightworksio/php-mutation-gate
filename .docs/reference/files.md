# Files

The files the gate reads and writes. `.mutation-gate/` holds local results
and proofs; it belongs in `.gitignore`, and `init` adds it there. Commit the
config and `mutation-gate.baseline.json`.

| Path | What it is | Decided in |
|------|------------|------------|
| `mutation-gate.php`, `.json`, `.yaml`, `.yml` or `.neon` | The config | [0002](../decisions/0002-one-typed-config-from-several-formats.md) |
| `mutation-gate.baseline.json` | The committed floors, in the [baseline format](#the-baseline). A CI run that finds a tree with no floor prints the baseline it measured, ready to commit | [0003](../decisions/0003-a-floor-only-rises.md), [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| The file `staticCheck.config` names, or else the analyser's own: `mago.toml`, `mago.yaml` or `mago.json`; `phpstan.neon`, `phpstan.neon.dist` or `phpstan.dist.neon`; `psalm.xml` or `psalm.xml.dist` | The static analyser's config. Every proof key holds the digest of the configuration the analyser resolves from it and of each file that configuration names, such as a baseline, an included config, or a bootstrap, stub or scanned file; where the analyser cannot say its configuration, of the config file alone | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `.mutation-gate/plan.json`, `.mutation-gate/coverage/map.json.gz`, `.mutation-gate/results/<id>.json` | The plan, its coverage and each shard's result, which `plan` and a run in one process leave and `explain` reads as the last run. A shard's result keeps each kill's evidence where its runner gives it: how far the mutant's own run went, and how a process that names no killer ended, with what it printed only where that holds no secret; it judges nothing | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| `.mutation-gate/coverage/shard-<n>/map.json.gz`, `.mutation-gate/coverage/shard-<n>/killers.json`, `.mutation-gate/coverage/verdict/map.json.gz` | The part of the plan's coverage map each shard and the verdict open on, and the tests each shard's run found killing first, which the next run orders by | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md) |
| `.mutation-gate/delivery/planned/`, `survivors/`, `verdict/`: `delivery.json`, `ledger.json.gz`, `coverage.json.gz` | What a run left for `deliver` with `--deliver-later`: the comment, alert and OTLP payloads, and the ledger to write | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `.mutation-gate/pipeline.yml` | GitLab's child pipeline | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `.mutation-gate/ledger/<scope>/ledger.json.gz` | The proof ledger of one ref; outside CI, the one a run writes whatever store the config names | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md), [0010](../decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| `.mutation-gate/mutants/<native id>.php` | The mutated file of a mutant judged by reference (Pest) | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `infection.json5`, `infection.json`, `infection.json5.dist` or `infection.json.dist` | The project's own Infection config, the first found, whose mutators, `bootstrap`, `phpUnit`, `initialTestsPhpOptions`, `testFrameworkExtraArgs` and static analysis the gate keeps | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `.mutation-gate/mutators/<runner>/bridges.php` | The bridges through which Pest or Infection makes the mutants of the registered mutators the config turns on, which the gate writes for each run | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `.mutation-gate/infection/` | The config the gate writes for each run of Infection, Infection's logs, its temporary files, and the coverage the adapter runs PHPUnit for | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `.mutation-gate/phpunit/` | The PHPUnit runner's override, each mutant's mutated file, the tests its run selects, what the extension recorded and the guard, the coverage it runs PHPUnit for, and its memory cap | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `.mutation-gate/phpunit-cache/` | PHPUnit's caches, its test run history and its code coverage cache, for every PHPUnit the gate starts under the PHPUnit, Pest and Infection runners, whatever directory the project's PHPUnit config names, so no run writes a file a content key reads | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `.mutation-gate/phpspec/` | The PhpSpec runner's override, each mutant's mutated file, the examples its run selects, what the extension recorded and the guard, the coverage and the list of examples it runs PhpSpec for, and its memory cap | [0027](../decisions/0027-codeception-phpspec-and-testo-get-native-runners.md) |
| `.mutation-gate/codeception/` | The Codeception runner's override, each mutant's mutated file, the tests its run selects, what the extension recorded and the guard, the coverage and the list of tests it runs Codeception for, and its memory cap | [0027](../decisions/0027-codeception-phpspec-and-testo-get-native-runners.md) |
| `.mutation-gate/publish/badge.json`, `trend.json`, `trend.svg`, `summary.json`, or the same in the `badge` report's `path` | The badge, the trend, and the run's summary an organisation's dashboard reads | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `.mutation-gate/publish/savings.json` | A shields.io endpoint with the time saved in the last 30 days | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| A `json`, `junit`, `sarif`, `digest`, `gitlab`, `sonar` or `kill-matrix` report's `path` | That report | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `.mutation-gate/report.json` | The last run's JSON report, which every verdict keeps and `digest` renders | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| An `html` report's `path`: `index.html`, `mutation-report.json` | The HTML report, and the report in the `mutation-testing-report-schema` format | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| A `tests` report's `path`, and the same path with `.md` | The useless, removable and weakly asserting tests, as JSON and Markdown | [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| `<out>/index.html`, `<out>/dashboard.json` | An organisation's dashboard, which `dashboard --out=<out>` writes | [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |

## What `init` writes

`init` writes each file only where it does not exist, and otherwise prints
what to add to it ([ADR-0015](../decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md),
[ADR-0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md)).

| Path | What it is |
|------|------------|
| The config: `mutation-gate.php`, or the format `--format` names | The runner and preset it found, and the trees listed in a comment |
| `.gitignore` | Gains `.mutation-gate/` |
| `composer.json` | Gains a `mutate` script, where you answer yes |
| `.github/workflows/mutation.yml` | The GitHub Actions workflow, `--ci=github` |
| `.gitlab/mutation-gate.yml`, or the file `ci.gitlab.template` names | The GitLab template, with the lines to add to `.gitlab-ci.yml` printed |
| `.buildkite/mutation-gate.yml` | The Buildkite steps, with what to add to the pipeline Buildkite runs printed |
| `.azure/mutation-gate.yml` | The Azure Pipelines template, with what to add to `azure-pipelines.yml` printed |
| `.circleci/config.yml`, `bitbucket-pipelines.yml`, the Jenkinsfile `ci.jenkins.definition` names | Not written: what to add to each is printed |
| `.vscode/tasks.json`, `.vscode/extensions.json` | VS Code's watch task and the recommended SARIF Viewer, `--editor=vscode` |
| `captainhook.json`, `grumphp.yml`, `.pre-commit-config.yaml` | The hook manager's config calling the gate, `--hook=captainhook`, `grumphp` or `pre-commit`; an existing `grumphp.yaml`, `grumphp.yml.dist`, `grumphp.yaml.dist`, `grumphp.dist.yml` or `grumphp.dist.yaml` is read instead |
| `pre-push`, and `pre-commit` with `hook install --pre-commit`, in git's hooks directory | git's own hooks, in the directory `git rev-parse --git-path hooks` names, `core.hooksPath` included |

## The baseline

`mutation-gate.baseline.json` holds each tree's floor, and each package's
security floor, one key per tree in byte order and each floor on a line of
its own, so a moved floor is a one-line diff:

```json
{
    "format": 1,
    "trees": {
        "app/Domain": { "floor": 100 },
        "app/Legacy": {
            "floor": 61.2,
            "lowered": { "from": 64.5, "reason": "The export feature and its tests were removed together" }
        }
    },
    "security": {
        "packages/billing": { "floor": 100 }
    }
}
```

| Key | What it holds |
|-----|---------------|
| `format` | `1` |
| `trees` | Each tree, by its path, with its `floor`: a percentage from 0 to 100, at most two decimals |
| `security` | Each package's security set, by its path, held as a tree is; left out where there is none |
| `lowered` | Beside a floor a pull request lowers: `from`, the floor on the default branch, and `reason`, why it may fall |

A floor only rises ([ADR-0003](../decisions/0003-a-floor-only-rises.md)).
`baseline --write` raises the floors to what was measured, and a pull
request may lower one only by saying so: on a pull request the gate compares
the baseline with the default branch's, and fails where

- a floor is lower and its entry has no `lowered`, or its `reason` is empty;
- `lowered.from` is not the default branch's floor;
- a tree leaves the file while its path is still a tree, or a security set
  while its package is still there.
