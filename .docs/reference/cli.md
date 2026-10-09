# Command line

Every command and option of `vendor/bin/mutation-gate`. `mutation-gate <command>
--help` prints one command's arguments and options in your terminal. The *Decided in* column links
the design decision behind each option.

## Commands

| Command | What it does |
|---------|--------------|
| `mutation-gate` or `mutation-gate run` | Plan, run and judge in one process: what changed since the last commit that passed, or everything where none passed yet or `run.full` asks for it. With `--changed-since=<ref>`, only what the change since `<ref>` reaches; with `--full`, everything. With `--budget=<duration>`, the riskiest code first, within that time. |
| `coverage [--into=<dir>]` | Run the suite under coverage and write the gate's own map, `<dir>/map.json.gz` (`.mutation-gate/coverage` by default), for a later `plan --coverage=<dir>` |
| `plan` | Work out the reach, drop proved units, cut shards and print the plan for a CI (`--ci=github\|gitlab\|buildkite\|circleci\|azure\|bitbucket\|jenkins\|json`, or `--shards=<n>` for a fixed count; `--coverage=<dir>` reads the map `coverage` wrote instead of running the suite) |
| `run --plan=<file> [--shard=<id>]` | Mutate one shard: the one `--shard` names, or the one the CI's environment names |
| `verdict --plan=<file> --results=<dir>` | Merge every shard's results, judge the floors, write reports and the ledger |
| `deliver [--from=<dir>]` | Send what a run left in `<dir>` (`.mutation-gate/delivery` by default), with credentials that run never held: write the ledger to the store, post the comment and the alerts, export OTLP. It runs from the gate's own installation and loads none of the project's code. Exits 2 where it cannot start or read the delivery, or where a trusted run's ledger is not written |
| `fetch [--to=<dir>]` | Read the default branch's ledger from the store with a key that only reads, and write it into `<dir>` (`.mutation-gate/ledger` by default), where the `directory` store reads it. It runs from the gate's own installation and loads none of the project's code. Exits 0 where `MUTATION_GATE_STORE` names no store, the ledger cannot be read or the job holds no key, saying why, and 2 where it cannot start, locate the store it names, name the default branch or write the ledger |
| `baseline [--write]` | Show, or write, floors raised to what was measured |
| `survivors [--plan=<file>]` | On a branch's run, run the last run's survivors again before the shards: those the comment lists, on the change's lines first, at most `survivorsFirst.max`, each found again by its id or reported gone. It writes the comment over its planned state and the step summary, and says how many still survive. It re-checks what the plan reaches, or plans first without one; it writes no ledger and judges nothing, so it exits 0, or 2 where it cannot re-check |
| `reproduce <id>` | Run one recorded mutant again, alone, and show why it survives, with the runner's own output; the id may be a unique prefix of 6 or more. Exits 1 where the run finds other than what was recorded, and 2 where no ledger holds it or the run no longer makes it |
| `explain <id> [--format=text\|json]` | Show one mutant's diff, hint, covering tests and their outcomes, for a kill by static analysis the analyser and its finding's file, code and message, how the last run took its unit, and its history, from the ledgers and the last run, running nothing; the id may be a unique prefix of 6 or more, or a cluster's id. `--format=json` is described by [`resources/explain.schema.json`](../../resources/explain.schema.json). Exits 2 where no record holds it |
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
| `migrate [--write]` | Show the diff that moves the config and the baseline to the current release, and with `--write`, write it. A PHP config keeps its comments and layout, and only the builder calls a release retired change. A JSON config and the baseline change key by key. A YAML or NEON config is written again, and its comments are not kept. The baseline is the one the config names, or `mutation-gate.baseline.json` while the config does not read. A change migrate cannot make is listed for a hand edit. Exits 0 where every file is current or written, 1 where a change is pending without `--write` or left for a hand edit, and 2 where a file cannot be read |
| `pest:patch` | Apply the optional Pest patches ([ADR-0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md)) |
| `infection:patch` | Give Infection the gate's mutant limit ([ADR-0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md)) |

## Options

| Option | Accepted by | What it does | Decided in |
|--------|-------------|--------------|------------|
| `--config=<path>` | every command | Read this config file instead of looking for one | [0002](../decisions/0002-one-typed-config-from-several-formats.md) |
| `--no-extensions` | every command | Load only the extensions of this package and its first-party plugins, and no third-party code | [0001](../decisions/0001-a-framework-free-core-behind-nine-ports.md) |
| `--deliver-later` | `plan`, `survivors`, `verdict` | Send nothing that needs a credential and write no store: leave the ledger and the comment, alert and OTLP payloads in `.mutation-gate/delivery/planned`, `.mutation-gate/delivery/survivors` or `.mutation-gate/delivery/verdict`, begun empty, for `deliver`; on a pull request the comment is left whether or not the job holds a token | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `--runner=<name>` | every command | Set `runner` | [0002](../decisions/0002-one-typed-config-from-several-formats.md) |
| `--report=<name>:<path>` | every command, repeatable | Add a file report to `reports` | [0002](../decisions/0002-one-typed-config-from-several-formats.md) |
| `--changed-since=<ref>` | `plan`, `run` without a plan, `affected` | Mutate, or list the tests of, only what the change since `<ref>` reaches; `last-passed`, the default of `plan` and `run`, is the newest passing commit; `last-run`, for `plan` and `run` off the default branch, reads the change from the commit the scope's last run of the same kind judged, falling back to the fetched default branch | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `--full` | `plan`, `run` without a plan | Mutate everything, whatever the event and `run.full`; an error beside `--changed-since` | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md) |
| `--budget=<duration>` | `run` | Stop after this long, riskiest code first | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `--coverage=<dir>` | `plan`, `run` without a plan, `affected` | Read the coverage an earlier job wrote instead of running the suite | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--ci=<name>` | `plan`, `run` | Set `ci.plan`: this CI's format instead of the detected one | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--shards=<n>` | `plan`, `run` without a plan | Cut exactly n shards | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--security` | `plan`, `run` without a plan | Make mutants with the security-tagged mutators alone, and judge only the security sets: each tree is shown exempt, and no commit is recorded as passed. Every shard and the verdict follow a plan made with it | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `--suite=<name>` | `plan`, `run` without a plan | Judge the mutants by this PHPUnit `<testsuite>`'s tests alone, the coverage run's among them, and hold no floor: each tree and security set is shown exempt, unless `--security` keeps the security sets held, and no commit is recorded as passed. Takes no `--coverage`. Every shard and the verdict follow a plan made with it | [0025](../decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| `--plan=<file>` | `run`, `verdict` | The plan the shards and the verdict follow | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--shard=<id>` | `run` with a plan | The shard to mutate, instead of the one the CI names | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--results=<dir>` | `verdict` | Where every shard's result is | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `--output=problems` | `run`, `watch`, `pre-push` | Print one `<path>:<line>:<col>: <severity>: <message> [<rule>] <id>` line per result, for editors | [0015](../decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `--only=changed` | `run`, `watch`, `pre-push` with `--output=problems` | Print only the mutants on changed lines | [0015](../decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `--write` | `stub` | Append the stub to its nearest covering test file, or create one; never overwrite | [0015](../decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `--write` | `migrate` | Write the migrated config and baseline | [0026](../decisions/0026-configs-and-baselines-move-forward-with-one-command.md) |
| `--style=pest\|phpunit` | `stub` | The stub's style, instead of the nearest covering test's | [0015](../decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `--stdout` | `init` with `--ci` or `--editor` | Print the files instead of writing them | [0015](../decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `--sharded`, `--single` | `init --ci=github` | The reusable workflow or the one-step action, instead of the one the estimated cost picks | [0015](../decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md) |
| `--native=allow\|refuse` | `init` | Answer the native-markers question: write `ignores.native` | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `--hook`, `--no-hook` | `init` | Answer the pre-push hook question | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `--no-measure` | `init` | Estimate the first run from lines of code, without the coverage run | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `--dry-run` | `init` | Print every file instead of writing it | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `--measure` | `doctor` | Add one coverage run: a green suite, a working driver and the hot paths | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `--online` | `doctor` | Also read GitHub's settings with the token: the required verdict, the fork approval policy, the schedule | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `--kill-matrix=first\|full` | `plan`, `run` | `full` records every test that kills each mutant, for the redundant-test report (Pest and the PHPUnit runner) | [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| `--publish-dir=<dir>` | `verdict`, `run` without a plan | Where the badge and trend are written, `.mutation-gate/publish` by default | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |

## Exit codes

| Code | Meaning |
|------|---------|
| `0` | Passed |
| `1` | Failed |
| `2` | Could not judge |

*Could not judge* means, for example, an invalid config, an opening test run
that failed, or a shard with no results. `run` with a plan exits `0` once its shard's result is written,
because the verdict judges it, and `2` when it cannot write it, the plan
belongs to another commit, or the CI's shard count differs from the plan's.
