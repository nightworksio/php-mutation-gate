# Install

The gate is a Composer package, `nightworksio/mutation-gate`. Each release
also publishes a signed PHAR and a container image built from it.

## Requirements

- PHP 8.4 or a later 8.x, with pcov or Xdebug for coverage, and the sodium
  extension, which the gate encodes with because it is timing-safe.
- Linux. macOS is best-effort: expected to work, not tested by CI. Windows is
  not supported.
- One of the runners:
  - **Pest**: `pestphp/pest` ^5.1 with `pestphp/pest-plugin-mutate` ^5.0.2, on
    the PHPUnit 13 release Pest pins;
  - **Infection**: `infection/infection` ~0.35.0, with PHPUnit 12 or 13;
  - **PHPUnit** on its own: `phpunit/phpunit` 13.2 or later;
  - **PhpSpec**: `phpspec/phpspec` 8.2 or later, with
    `phpunit/php-code-coverage` 14;
  - **Codeception**: `codeception/codeception` 5.3.6 or later, with
    `phpunit/php-code-coverage` 14, for the suites that run in the test's
    own process;
  - **Testo**: `testo/testo` ~0.10.56, which measures coverage itself.

  Infection and the PHPUnit runner do not run Pest suites, so a Pest project
  uses Pest's own mutation testing. Beside the gate, Composer installs no
  PHPUnit before 12.5.8, and neither 12.5.21 nor 13.1.5.

Optional packages, each needed only for what it enables:

- `symfony/yaml` for a YAML config;
- `nette/neon` for a NEON config;
- `async-aws/s3` for proofs kept in S3 or R2;
- `colinodell/json5` to import an `infection.json5`;
- `ext-pcntl`, and for Pest `ext-posix` too, for the warm workers each
  mutant's run and each run that verifies it forks from.

`vendor/bin/mutation-gate doctor` checks every one of these before a run
needs it, and says what to install.

## With Composer

The package is installed from this repository, which Composer reads as a
`vcs` repository:

```sh
composer config repositories.mutation-gate vcs https://github.com/nightworksio/php-mutation-gate
composer require --dev "nightworksio/mutation-gate:dev-main" -W
```

The first command adds the repository to your `composer.json`, so CI installs
the gate with the rest of your dependencies. `-W` lets Composer move
`pestphp/pest-plugin-mutate` up to 5.0.2, the least release the gate allows.
`composer.lock` holds the commit it installed, and
`composer update nightworksio/mutation-gate` moves it on.

A run refuses releases its runner does not drive before it starts, and
`doctor` says so first, with what to require. It also advises where
`pestphp/pest-plugin-mutate` is newer than any release the gate is tested
with.

## The PHAR

Each release publishes `mutation-gate.phar` and its detached signature,
`mutation-gate.phar.asc`, as assets of its GitHub release. The PHAR keeps its
dependencies apart from the project's, but for `nikic/php-parser` and
`psr/clock`, which mutators and extensions share with the gate. The gate loads
the PHAR's autoloader ahead of the project's in every test run it starts, so a
project that requires another major of either runs its tests with the PHAR's
copy. Such a project installs the gate through Composer.

[Phive](https://phar.io) installs the PHAR and checks its signature:

```sh
phive install nightworksio/php-mutation-gate
```

[Security](../../SECURITY.md#verifying-a-release) gives the release
key's fingerprint and the commands that verify the signature and GitHub's
build provenance by hand.

## The container image

Each release publishes `ghcr.io/nightworksio/mutation-gate`, for `linux/amd64`
and `linux/arm64`, tagged with the release's version, its minor and its major,
such as `1.4.2`, `1.4` and `1`. It holds PHP's CLI with pcov, pcntl, git,
unzip and Composer, and the gate's PHAR on the path as `mutation-gate`, which
is its entry point. It runs in `/app`:

```sh
docker run --rm -v "$PWD":/app ghcr.io/nightworksio/mutation-gate:<version> doctor
```

The image runs the project's own tests, so a project whose suite needs more
PHP extensions builds its own image from it:

```dockerfile
FROM ghcr.io/nightworksio/mutation-gate:<version>
RUN docker-php-ext-install pdo_mysql
```

## Next

[Get started](README.md) takes the installed gate to a first verdict.
