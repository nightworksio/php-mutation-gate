# Troubleshooting

Every refusal, failure and warning the gate prints ends with a link to its
section here. The section is named by the message's slug, which never changes
meaning once released (ADR-0018, decision 8). `mutation-gate doctor` finds most
of these before a run does (ADR-0017, decision 9).

## no-coverage-driver

The PHP the runner runs its tests on loads neither pcov nor Xdebug. The gate
learns which tests run each line from coverage, so without a driver no run can
judge a mutant.

Load pcov: `pecl install pcov`, then add `extension=pcov` to the php.ini that
PHP loads. `php --ini` names it.

## xdebug-slows-tests

Xdebug is loaded in a mode other than `coverage` or `off`, or it collects
coverage where pcov is installed beside it. Every other mode slows each test,
and the runner runs tests again for every mutant. pcov collects line coverage
several times faster than Xdebug.

Set `XDEBUG_MODE=coverage` where the gate runs, or `xdebug.mode=coverage` in
php.ini. Where pcov is installed, add `extension=pcov`: php-code-coverage
collects with pcov wherever both are loaded.

## opcache-on-the-command-line

`opcache.enable_cli` is on, or `opcache.file_cache` names a directory. OPcache
could then serve a file's original in place of its mutant, so a mutant judged
by reference is left unjudged (ADR-0004, decision 8).

Set `opcache.enable_cli=0` and `opcache.file_cache=` in the php.ini the runner's
PHP loads.

## two-runners

Both `pestphp/pest-plugin-mutate` and `infection/infection` are installed, and
neither the config nor the command line chooses one. Zero-config chooses the
runner from what is installed, so with both it cannot.

Set `runner: pest` or `runner: infection` in the config, or pass `--runner`.

## no-tree

The tree source finds no tree to mutate. A tree is what the gate mutates and
holds to a floor, so with none a run judges nothing.

Name one in the config, as `trees: [{path: src}]`, or declare a `psr-4`
autoload path in `composer.json`.

## config-refused

The config cannot be used, and every command reads it first. The message names
each problem at its path.

Correct each problem, then run `mutation-gate config:show` to see the config
the gate would use.

## php-not-read

The PHP the runner starts could not describe itself with `-m` and `-i`. A run
starts the same PHP with the same options, so it would fail the same way.

Run that PHP with `-m` by hand, with the runner's options, and correct what
stops it.

## workspace-not-ignored

`.gitignore` does not name `.mutation-gate/`. The gate keeps each run's
coverage maps, results and ledger there, and none of them belongs in git.

Add the line `.mutation-gate/` to `.gitignore`, as `mutation-gate init` does.

## infection-config-to-import

The project's Infection config sets a `minMsi`, or ignores mutants by
Infection's own rules. The gate reads neither: floors replace `minMsi`, and
`ignores.entries`, each with a reason, replaces Infection's ignores
(ADR-0016).

Run `mutation-gate init --from=infection.json5`, naming the config, which
writes both into a config and says how each key maps.
