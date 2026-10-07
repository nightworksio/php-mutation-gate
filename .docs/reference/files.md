# Files

The files the gate reads and writes. `.mutation-gate/` holds local results
and proofs; it belongs in `.gitignore`, and `init` adds it there. Commit the
config and `mutation-gate.baseline.json`.

| Path | What it is | Decided in |
|------|------------|------------|
| `mutation-gate.php`, `.json`, `.yaml`, `.yml` or `.neon` | The config | [0002](../decisions/0002-one-typed-config-from-several-formats.md) |
| `mutation-gate.baseline.json` | The committed floors | [0003](../decisions/0003-a-floor-only-rises.md) |
| The file `staticCheck.config` names, or else the analyser's own: `mago.toml`, `mago.yaml` or `mago.json`; `phpstan.neon`, `phpstan.neon.dist` or `phpstan.dist.neon` | The static analyser's config. Every proof key holds the digest of the configuration the analyser resolves from it and of each file that configuration names, such as a baseline, an included config, or a bootstrap, stub or scanned file; where the analyser cannot say its configuration, of the config file alone | [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `.mutation-gate/plan.json`, `.mutation-gate/coverage/`, `.mutation-gate/results/<id>.json` | The plan, its coverage and each shard's result, which `plan` and a run in one process leave and `explain` reads as the last run | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| `.mutation-gate/pipeline.yml` | GitLab's child pipeline | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `.mutation-gate/ledger/<scope>/ledger.json.gz` | The proof ledger of one ref; outside CI, the one a run writes whatever store the config names | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md), [0010](../decisions/0010-the-gate-runs-while-you-work-and-before-you-push.md) |
| `.mutation-gate/mutants/<native id>.php` | The mutated file of a mutant judged by reference (Pest) | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `infection.json5`, `infection.json`, `infection.json5.dist` or `infection.json.dist` | The project's own Infection config, the first found, whose mutators, `bootstrap`, `phpUnit`, `initialTestsPhpOptions`, `testFrameworkExtraArgs` and static analysis the gate keeps | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `.mutation-gate/mutators/<runner>/bridges.php` | The bridges through which Pest or Infection makes the mutants of the registered mutators the config turns on, which the gate writes for each run | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `.mutation-gate/infection/` | The config the gate writes for each run of Infection, Infection's logs, its temporary files, and the coverage the adapter runs PHPUnit for | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `.mutation-gate/phpunit/` | The PHPUnit runner's override, each mutant's mutated file, the tests its run selects, what the extension recorded and the guard, the coverage it runs PHPUnit for, and its memory cap | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `.mutation-gate/publish/badge.json`, `trend.json`, `trend.svg` | The badge and trend | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `.mutation-gate/publish/savings.json` | A shields.io endpoint with the time saved in the last 30 days | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `.mutation-gate/baseline.measured.json` | The baseline a CI run measured for trees with no floor, to commit as `mutation-gate.baseline.json` | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| A `json`, `junit`, `sarif`, `gitlab`, `sonar` or `kill-matrix` report's `path` | That report | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| An `html` report's `path`: `index.html`, `mutation-report.json` | The HTML report and its data | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| A `tests` report's `path`, and the same path with `.md` | The useless, removable and weakly asserting tests, as JSON and Markdown | [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
