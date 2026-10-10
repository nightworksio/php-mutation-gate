# Contributing

This page says what a contribution needs, how to check one before you push
it, and where the design is written down. A vulnerability is reported
privately, as [SECURITY.md](SECURITY.md) says, and everyone here keeps the
[code of conduct](CODE_OF_CONDUCT.md).

## Setup

- **PHP 8.5 with pcov.** The coverage gate runs under pcov.
- **`composer install`.** It sets `core.hooksPath` to `.githooks` and runs
  `bin/mutation-gate pest:patch`.
- **Python 3** runs the scripts under `.github/scripts` and their tests.
- **Node** runs markdownlint, locked in `.github/bot/markdownlint`.

The hooks in `.githooks` say early what CI says later:

| Hook | Refuses |
|------|---------|
| `pre-commit` | a staged PHP file Pint would change |
| `commit-msg` | a subject that is not conventional, a line that credits an assistant, and a `Spec:` number that is no decision |
| `pre-push` | a push straight to `main`, and a push that would leave a branch with no commit of its own |

## The local gates

Every CI job has an entry in [`.github/gates.json`](.github/gates.json). Its
`reproduce` is the command that runs the job here, and its `fix`, where a fix
is mechanical, the command that makes it. This prints the one for `checks`:

```sh
jq -r '.gates.checks.reproduce' .github/gates.json
```

Before each push, run the entries for what you changed:

| You changed | Run |
|-------------|-----|
| anything | `hygiene/typos` and `hygiene/lines` |
| PHP, `composer.json`, `phpstan.neon`, `phpstan/layers.neon`, `rector.php` or `pint.json` | `checks` and `rules`, and the tests of the code you changed: `vendor/bin/pest <test files>` |
| Markdown | `hygiene/markdown` and `hygiene/links` |
| `ARCHITECTURE.md`, `.docs/reference/`, `.docs/guide/ci/` or `.docs/guide/troubleshooting.md` | the Markdown entries, and `composer test`, because tests read them |
| a workflow | `hygiene/actionlint` and `hygiene/zizmor` |
| `.github/scripts` | `scripts` |

`tests/Unit` mirrors `src`, so the tests of `src/Core/Plan/Cut.php` are in
`tests/Unit/Core/Plan/CutTest.php`, and each plugin's are under
`plugins/<name>/tests`. Where they would pass the line cap, they are split by
concern into `tests/Unit/Core/Plan/Cut/`, beside the first file, with what the
files share in a class of `tests/Support`.

The whole suite runs locally too, more slowly:

| Command | Runs |
|---------|------|
| `composer test` | every suite but Guards, in parallel |
| `composer test:coverage` | the same, with every line of `src` and of each plugin's `src` covered (`tests`) |
| `composer test:guards` | a planted violation of every rule, which each rule must refuse (`guards`) |
| `composer ci` | validate, normalize, audit, lint, analyse, refactor, deps, `test:guards` and `test:report`, in that order |

Leave these to CI:

- `lowest dependencies`, because it rewrites `composer.lock` and `vendor`;
- `runner contracts`, because each of its legs installs a fixture library at
  one end of a supported range first. On a pull request, a runner's legs run
  only where its adapter, its fixture or what every runner shares changed;
  `warm workers` runs only where the PHPUnit runner, its worker or what every
  runner shares changed (`.github/scripts/scope.py`). Every leg runs on `main`
  and every night;
- `sonarcloud` and `gate`, which ask SonarCloud;
- `analyze`, CodeQL's analysis of the workflows.

## Commits and pull requests

- **Conventional subjects.** Every commit subject, and the pull request's
  title, is `type(scope): subject`. The type is one of `feat`, `fix`, `docs`,
  `refactor`, `test`, `chore`, `ci`, `perf`, `build`, `style` and `revert`.
  The scope is optional and lowercase, and a `!` before the colon marks a
  breaking change. `commitlint` checks them.
- **The title and body become the commit on `main`.** `main` takes only
  squash merges, whose subject is the pull request's title and whose message
  is its body.
- **`Spec:` in the body.** A change that follows a decision names it on a
  line of its own, `Spec: 0006`, or several numbers, `Spec: 0002, 0011`. Each
  number must be a decision in `.docs/decisions`, which `description` checks.
  A commit may carry the same trailer, and the `commit-msg` hook checks its
  numbers.
- **A breaking change says how to move.** A title with `!` needs a
  `## Migration` section in the body, saying what a user changes.
- **No assistant is credited.** No commit and no pull request body may carry
  a `Co-authored-by` trailer naming an assistant, or a line saying the work
  was generated with or written by one. The work is the author's own.
  `attribution` refuses either: remove the line, rewording the commit that
  holds it.
- **Signing is not required, and there is no sign-off.** Every commit on
  `main` is signed, because `main` takes only squash commits and GitHub signs
  each one. Your own commits need not be signed, and need no
  `Signed-off-by` line.
- **Open the pull request as a draft,** and mark it ready for review once its
  checks are green.

CI on a pull request from someone who is neither a member of the organisation
nor a collaborator on this repository starts once a maintainer approves the
run. The pull request template holds the checklist.

### Commands in comments

A comment whose first line is one of these runs it:

| Command | Does | Who |
|---------|------|-----|
| `/update` | GitHub merges the base branch into the pull request's branch, and signs the merge commit, which the squash leaves out of `main` | someone with write access, or the pull request's author |
| `/rebase` | the same as `/update`, because GitHub cannot sign rebased commits | someone with write access, or the pull request's author |
| `/retest` | re-runs the failed jobs of the latest CI run on the pull request's head, at most three times per head | someone with write access, or the pull request's author |
| `/docs <words>` | replies with at most three links to headings under `.docs` that match the words | anyone, on an issue or a pull request |

## Finding your way

- **[ARCHITECTURE.md](ARCHITECTURE.md) is the contract.** It lays out the
  layers, `Core`, `Attribute`, `Port`, `Mutator`, `Config`, `Extension`,
  `Adapter` and `Cli`, each naming only itself and those before it. Every rule
  in it names what enforces it, and `tests/Guards` plants a violation of each
  one.
- **[The decisions](.docs/decisions/README.md) are the design.** Each ADR
  gives a decision, the alternatives and why they lost. A commit or pull
  request that implements one names it in `Spec:`.
- **The tests are laid out by kind.** `tests/Unit` mirrors `src`.
  `tests/Contract` holds one suite per port, which runs against the port's
  fake in `tests/Fakes` and every adapter. `tests/Arch` holds the rules the
  suite enforces, and `tests/Docs` holds the documentation to the code.

## Adding an adapter, a preset or an extension

- **An adapter** implements one of the interfaces in `src/Port`, in a
  directory of its own under `src/Adapter`, and names no other adapter.
  `src/Cli/FirstParty.php` registers it under the name a config chooses it
  by. Its port's suite in `tests/Contract` is run against it, and its unit
  tests mirror it under `tests/Unit/Adapter`.
- **A preset** is a layer of config. The presets this package ships are in
  `src/Cli/Config/Presets.php`, named by `Core\Config\BuiltinPreset`. An
  extension registers its own with `Extensions::withPreset()`.
- **An extension** is a class implementing
  `NightWorksIO\MutationGate\Extension\Extension`, constructed with no
  arguments and named in its package's `composer.json` under
  `extra.mutation-gate.extensions`. Its `extend()` answers the registry with
  its adapters, presets and mutator sets added. A first-party mutator set is
  a plugin, a package of its own under `plugins/<name>`, as `plugins/default`
  is ([ADR-0021](.docs/decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md)).

[Writing an adapter](.docs/guide/extending/writing-an-adapter.md) says how a
config names an adapter, and how an adapter reads its options.

## Maintainers and decisions

- **There is one maintainer,** as [`.github/CODEOWNERS`](.github/CODEOWNERS)
  says.
- **Design is decided by ADR.** Each decision is recorded in
  `.docs/decisions`. An accepted decision changes only through a new ADR that
  supersedes it, linked both ways, as
  [the decisions' rules](.docs/decisions/README.md) say.
- **A proposal becomes an ADR by pull request.** It adds a decision record
  under the next unused number, written as the others are, with its row in
  the index. The maintainer accepts it by merging it.
