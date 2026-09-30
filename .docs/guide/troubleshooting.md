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

The PHP the runner starts could not describe itself. A run starts the same PHP
with the same options, so it would fail the same way.

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

## native-markers-refused

The source or the runner's config holds a runner's own ignore marker, such as
`@pest-mutate-ignore` or `@infection-ignore-all`, and `ignores.native` is
`refuse`, as it is by default. A marker hides mutants with no reason and no
end, so a run refuses it (ADR-0008, decision 4). `doctor` lists each marker
with its file, its line and the function it is in.

Replace each marker with the `ignores.entries` entry `doctor` shows for it,
and write why no test can tell its mutants apart. To adopt the gate first and
replace the markers later, set `ignores.native: allow`: every run then says
how many markers hide mutants (ADR-0017, decision 7).

## mirrored-path-repository

A `path` repository in `composer.json` sets `"symlink": false`, and a tree
the gate mutates is inside it. Composer copies such a package into the vendor
directory, so the tests load the copy, and a mutant of the tree changes code
no test runs. Every such mutant survives.

Remove `"symlink": false` from that repository's `options`, or set it to
`true`, then run `composer update` for the packages it holds.

## ignores-expiring

An entry of `ignores.entries` has expired, or expires within 14 days
(ADR-0008, decision 4). An ignore lasts only as long as its reason, so it has
an end, and its mutants count again once it passes.

Check each entry's reason again. Where it still holds, move `expires` later.
Where a test can now tell the mutants apart, remove the entry.

## tree-without-floor

A tree has no floor in the config, and the baseline records none for it.
There is no default floor, so the first CI run measures such a tree, offers
the baseline it measured, and fails (ADR-0017, decision 6). Where no CI
definition runs the gate yet, this is advice.

Declare a floor for the tree, as `trees: [{path: src, floor: 80}]`, or
commit the `mutation-gate.baseline.json` the first CI run measures.

## ledger-too-large

A ledger the proof store keeps is over 11 MB compressed. A run reads no
ledger past that, so each run of its scope judges without it. The limit is
twice what a ledger at the retention cap measures, so a ledger this large is
not one the gate keeps: its retention was not applied, or it is another file.

Delete it. The next run of its scope writes it afresh, within the retention
cap.

## coverage-run-failed

`doctor --measure` could not measure the suite. It finds the units and runs
the whole suite once under coverage, as every run begins, and one of those
steps failed. The finding quotes why: a failing test, a driver the runner's
PHP cannot load, or a runner that cannot list its groups.

Run `vendor/bin/mutation-gate coverage` to see the same failure, and fix
the test or the driver it names.

## coverage-empty

`doctor --measure` ran the whole suite under coverage, and the suite passed
but covered no line of any file. A mutant no test covers is never killed, so
no run could judge one.

Collect coverage where the gate runs, with `XDEBUG_MODE=coverage` or
`pcov.enabled=1`, and list the trees in the `<source>` of `phpunit.xml`.

## hot-path-unheld

Most of the suite runs through a file, and nothing holds it (ADR-0005,
decision 11). Each of its mutants runs most of the suite, which costs time
and never a verdict. `doctor --measure` finds it over the coverage run it
measured. The time at stake is what the ledgers learned the file takes, or
else what its covering tests take once, for each of its mutants.

Hold it with the tests that assert what it does: `#[Holds('<path>')]` on
them, or the `holds:<path>` group under Pest.

## verdict-not-required

`doctor --online` found that the repository's default branch does not require
the check-run the verdict reports under, `ci.check`, in any ruleset or branch
protection rule. So a pull request whose verdict failed can still be merged.

Require the status check `mutation / verdict`, or whatever `ci.check` names,
on the default branch in a ruleset or a branch protection rule.

## fork-approval-weak

`doctor --online` found that the workflows of a pull request from a fork run
without a maintainer's approval unless its author is a first-time
contributor. A fork's pull request runs its own code in the gate's jobs, and
can spend the runners' minutes.

Require approval for all external contributors, under Settings, Actions,
General, in the approval for running fork pull request workflows from
contributors.

## schedule-not-running

`doctor --online` found that no GitHub workflow that runs the gate ran on a
schedule in the last 8 days, or that GitHub disabled one after 60 days
without activity in the repository. The scheduled run keeps the default
branch's ledger current, which every pull request starts from.

Enable a disabled workflow again, with `gh workflow enable` or in the Actions
tab. Otherwise run the gate on a schedule, such as
`on: schedule: [{cron: '0 3 * * 1'}]` in its workflow.

## online-unread

GitHub did not show `doctor --online` one of the settings it reads, which is
usually a matter of the token's permissions. doctor cannot tell whether that
setting is set as the gate needs, and a run is not affected.

Run `doctor --online` with a `GITHUB_TOKEN` or `GH_TOKEN` that has the
permission the finding names: `contents: read` for the required checks,
`administration: read` for the fork approval policy, and `actions: read`
for the scheduled runs.

## no-github-repository

`doctor --online` found no repository on GitHub to read: `GITHUB_REPOSITORY`
is not set, and git's `origin` remote is not on GitHub's host. Every other
check still ran.

Run `doctor --online` where `GITHUB_REPOSITORY` names the repository, as
`owner/name`, or where the `origin` remote is on GitHub. On an Enterprise
server, set `GITHUB_SERVER_URL` and `GITHUB_API_URL`.
