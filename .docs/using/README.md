# Get started

From an installed gate to a first verdict, then to CI.

## Install

[Install](install.md) lists what the gate needs, and installs it with
Composer:

```sh
composer config repositories.mutation-gate vcs https://github.com/nightworksio/php-mutation-gate
composer require --dev "nightworksio/mutation-gate:dev-main" -W
```

## The first verdict

```sh
vendor/bin/mutation-gate init
vendor/bin/mutation-gate doctor
vendor/bin/mutation-gate
```

`init` detects your runner, preset and CI, asks what detection leaves open,
writes `mutation-gate.php`, and adds `.mutation-gate/` to `.gitignore`. It
also asks where to set up a `pre-push` hook that judges each push as CI will,
and the default answer is the hook manager the project already configures, or
git's own hooks. `init --no-hook`, or `--no-interaction`, sets up no hook, and
`vendor/bin/mutation-gate hook uninstall` removes git's. `doctor` says what
would fail or run slowly, each finding with its fix and a link to its section
of [troubleshooting](troubleshooting.md).

The run measures coverage once, mutates every tree and prints each tree's
score, then every mutant the tests let through, with its diff, the tests that
ran it, what they miss, and the commands that reproduce and explain it.
[Reading the report](reading-the-report.md) says what each part means.

The config `init` writes gives no tree a floor, so the first run passes and
warns. Write the floors it measured, and commit them with the config:

```sh
vendor/bin/mutation-gate baseline --write
git add .gitignore mutation-gate.php mutation-gate.baseline.json
```

From then on a score may not fall below its floor, and `baseline --write`
raises the floors when scores improve.

## In CI

`vendor/bin/mutation-gate init --ci` writes the definition for the CI it
detects. On GitHub Actions it uses this repository as an action, pinned to
the commit of the gate you installed, and the action runs the copy your
`composer.json` requires. The comment jobs `init` writes for GitLab and
Bitbucket run the gate's container image, which only a release publishes.
[Running the gate in CI](ci/README.md) has a page for each CI.

## What to read next

**What the gate does, and why its verdict can be trusted:**
[features](../features/README.md).

**New to mutation testing:** [reading the report](reading-the-report.md) says
what the report shows, what is good and what is not, and what to do next.

**Setting up CI:** [running the gate in CI](ci/README.md), then the page for
your CI, then [proofs and trust](proofs.md) before you give
any job a credential.

**Speeding up a slow suite:** [holding tests](../features/holding-tests.md) for
code every test runs through, and the `shards`, `budget` and `pest.patch`
keys in the [configuration](configuration.md#every-key).

**Working locally:** the [pre-push hook and watch mode](local.md),
and [survivors in your editor](editors.md).

**When the gate refuses, fails or warns:** each finding `doctor` prints links
to its section of [troubleshooting](troubleshooting.md).

**Extending the gate:** [extending the gate](../extending/README.md) lists
every extension point and its contract.

The reference lists every [command](cli.md),
[configuration key](configuration.md),
[report](reports.md),
[environment variable](environment.md) and
[file](files.md), and says what an upgrade may change in
[compatibility](compatibility.md).
