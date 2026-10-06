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

## infection-unpatched

The runner is Infection, and the installed Infection does not carry
`infection:patch`. Infection then gives each mutant its own limit, 5 s plus
five times its covering tests' time under `timeouts.most`, with no
`timeouts.seconds` floor, so a mutant whose tests take a fraction of a second
can run out of time on a busy runner and count as killed by timeout. Every
run says so in its report (ADR-0008, decision 2).

Add `@php vendor/bin/mutation-gate infection:patch` to `post-install-cmd` and
`post-update-cmd` in `composer.json`, and run `composer install`. The patch
applies only to the Infection releases the gate supports, and says which
where the installed one is another.

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

## memory-limit-low

The PHP that runs the gate may take less memory than reading and writing
ledgers as large as a run reads can take, and the gate could not raise its
own `memory_limit`. The gate's command raises it for its own process where
PHP lets it, so this is a PHP that refuses `ini_set`, or the gate running
inside another process. Past the limit, PHP stops a run over a ledger that
large.

Set `memory_limit` to the size the finding names, or to `-1`, for the PHP
that runs the gate.

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

## memory-uncapped

`runner.memory` is `-1`, so no PHP process of a mutation run has a memory
cap. A mutant that runs away with memory then takes the machine down, with
every mutant still to run on it (ADR-0004, decision 9).

Set `runner.memory` above what the suite needs, such as `1G`.
`doctor --measure` says what the suite needs.

## memory-cap-lifted

The project's PHPUnit config sets `memory_limit` higher than
`runner.memory`, or to `-1`, with `<ini name="memory_limit">` under `<php>`.
PHPUnit sets it as it starts, after PHP has read the cap, so each mutant runs
under the config's limit in place of the cap.

Take the `<ini name="memory_limit">` out of the PHPUnit config, or set it no
higher than `runner.memory`, and raise `runner.memory` where the suite needs
more. An `ini_set('memory_limit', ...)` in a bootstrap file wins over the cap
the same way; doctor cannot see one.

## memory-cap-near

Under `doctor --measure`, the suite's processes held over half the memory
`runner.memory` allows, by the most resident memory the system counts for
them. Each mutant runs under the cap, so a mutant that needs more than it is
stopped by the cap rather than by a test, and a plan refuses a suite whose
coverage run held more than the cap. Resident memory is an upper bound on
what `memory_limit` counts, so a suite near the cap may be refused though
its mutants would fit.

Set `runner.memory` to at least what the finding names: twice what the suite
held.

## shallow-clone

The checkout is a shallow clone: it holds only the newest commits, and not
the history before them. Git cannot say what changed since a commit the
clone does not hold. So a change-scoped run from an older base mutates
everything, and a kill proved at an older commit does not carry for a unit
a time budget never started, which leaves that unit unjudged.

Clone the whole history. On GitHub Actions, give `actions/checkout`
`fetch-depth: 0`; on GitLab, set `GIT_DEPTH: 0`.

## warm-boot-refused

The last run's warm workers forked nothing, so each mutant ran in a fresh
process and paid the whole boot again (ADR-0023, decision 13). A warm worker
boots the autoloader and the bootstrap once and forks a child for each
mutant. It refuses a boot that leaves a socket open, such as a database
connection, which every child would share; one that started PHPUnit's
events, as PHPUnit before 13.4 does to report a deprecation in its
configuration, whose buffered events every child would report again; and one
that loads a file the run mutates, which no child could serve its mutant in
place of. The finding names the bootstrap file, and its line where PHP can
tell: the line that autoloaded a class, where the bootstrap did.

Have the bootstrap open its connections lazily and leave the code under test
unloaded, migrate a deprecated PHPUnit configuration with
`vendor/bin/phpunit --migrate-configuration`, or set `runner.workers: fresh`
to stop trying.

## anonymous-reads-refused

`doctor --online` asked the `azure` store's public container for its
properties without credentials, as a fork's run reads it, and the storage
account answered 409: its `AllowBlobPublicAccess` is off. That setting
overrides every container's anonymous access level, so a run without
credentials reads nothing from `publicUrl` and mutates everything a pull
request reaches.

Allow anonymous access on the account, with
`az storage account update --name <account> --allow-blob-public-access true`,
and keep only the public container at the `Blob` access level. Every other
container keeps the default, which allows no anonymous access.

## outside-sonar-sources

A `sonar` report is listed in `reports`, and a tree lies outside every path
`sonar.sources` names in `sonar-project.properties`. SonarQube indexes only
the files under `sonar.sources`, and drops an imported issue on a file it did
not index, with one log line that counts those files and names at most five.
The survivors in that tree never reach SonarQube.

Add the tree to `sonar.sources`, or leave it out of the gate's trees.

## survived

The tests that run the mutant's line all passed with it in place, so none of
them checks what the change undid. It counts as not killed. The report's
hint says what the tests miss, and `vendor/bin/mutation-gate explain <id>`
shows the mutant's diff and the tests that ran it.

Add an assertion that fails with the mutant in place, in a test that runs
its line, and check it with `vendor/bin/mutation-gate reproduce <id>`. A
mutant no test could tell from the code, such as one that changes only how
fast it runs, is ignored in `ignores.entries` with a reason.

## uncovered

No test runs the mutant's line, so no test could fail with it in place. It
counts as not killed.

Write a test that runs the line and asserts what it does.

## unjudged

The run did not judge the mutant: the run stopped first, as a time budget
stops it, or the mutant's tests take too long or hold too much memory for a
timeout or the memory cap to say anything about it. It counts as not killed.

Run again with more time, or without `--budget`. For a mutant too slow to
judge, hold its code with a group of the tests that assert on it, or raise
`timeouts.seconds`. For one too heavy to judge, raise `runner.memory`.

## flaky

The mutant's tests killed it on one run and let it survive on another, so a
test's outcome does not depend on the code alone. It counts as not killed.

Make the mutant's judging tests, which the report names, give the same
answer on every run: no shared state between tests, no clock or random value
they do not control, and no order they rely on.
