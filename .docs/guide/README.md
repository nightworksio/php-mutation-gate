# Guide

From nothing to a first verdict, then what to read for what you are doing.

## Install

The gate needs PHP 8.4 or a later 8.x with pcov or Xdebug and the sodium
extension, on Linux (macOS is best-effort), and one of the runners the
[requirements](../../README.md#requirements) list: Pest, Infection, PHPUnit,
PhpSpec, Codeception or Testo.

The gate is published to no package registry, and no version is tagged, so
there is no release PHAR or container image to download. Install it with
Composer, from this repository's `main` branch:

```sh
composer config repositories.mutation-gate vcs https://github.com/nightworksio/php-mutation-gate
composer require --dev "nightworksio/mutation-gate:dev-main" -W
```

The first command adds the repository to your `composer.json`, so CI installs
the gate with the rest of your dependencies. `-W` lets Composer move
`pestphp/pest-plugin-mutate` up to the least release the gate allows.
`composer.lock` holds the commit it installed, and
`composer update nightworksio/mutation-gate` moves it to the head of `main`.
A commit that no release holds carries no compatibility promise
([compatibility](../reference/compatibility.md)).

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

**New to mutation testing:** [reading the report](reading-the-report.md) says
what the report shows, what is good and what is not, and what to do next.

**Setting up CI:** [running the gate in CI](ci/README.md), then the page for
your CI, then [proofs and trust](concepts/proofs-and-trust.md) before you give
any job a credential.

**Speeding up a slow suite:** [holding tests](concepts/holding-tests.md) for
code every test runs through, and the `shards`, `budget` and `pest.patch`
keys in the [configuration](../reference/configuration.md#every-key).

**Working locally:** the [pre-push hook and watch mode](recipes/pre-push-and-watch.md),
and [survivors in your editor](recipes/editors.md).

**When the gate refuses, fails or warns:** each finding `doctor` prints links
to its section of [troubleshooting](troubleshooting.md).

**Extending the gate:** [writing an adapter](extending/writing-an-adapter.md).

The reference lists every [command](../reference/cli.md),
[configuration key](../reference/configuration.md),
[report](../reference/reports.md),
[environment variable](../reference/environment.md) and
[file](../reference/files.md), and says what an upgrade may change in
[compatibility](../reference/compatibility.md).
