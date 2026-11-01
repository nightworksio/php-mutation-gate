# ADR-0047: Testo's runner holds a minor range, and its plugin selects, stops and times its tests

**Status:** Accepted
**Date:** 2026-11-01
**Amends:** [0027](0027-codeception-phpspec-and-testo-get-native-runners.md): its Testo pin, selection, first failure and timings

## Context

ADR-0027, rule 5 gives the Testo runner an exact pin, `--filter` and
`--path` to select tests, `CancelTest` to stop after the first failure, and
`--log-junit` for each test's time. Testo 0.10.56, read at source, differs
on three of these:

- **Releases.** Testo is 0.x. Its 0.10 series has 57 releases, from 0.10.0
  on 2026-03-17 to 0.10.56 on 2026-10-13. An exact `conflict` refuses each
  new one to every user until a pull request takes it, though most change
  nothing the runner reads. Infection is 0.x too, and ADR-0011 holds it to a
  minor range, `~0.35.0`, which the runner canary (ADR-0017) widens.
- **Stopping.** `CancelTest` marks a test cancelled only where it escapes the
  test's own body. Thrown from an interceptor, it leaves the pipeline, and
  Testo counts it a pipeline failure. No event listener or interceptor can
  cancel the tests that remain with it.
- **Selection.** `--filter Class::method` and `--path` select by name and
  directory on the command line, so a mutant covered by many tests needs a
  filter for each. `--group` selects Testo's own `#[Group]` groups, and the
  holding groups of the gate's `#[Holds]` (ADR-0005) are not among them.

Testo does give a plugin what the runner needs: `PluginConfigurator`, a
`TestRunInterceptor` that sees each test before anything of it runs and can
return its result without running it, and an `ApplicationConfig` a PHP file
returns. It reads that file from the path `--config` names, which it
normalizes first: `phar:///gate.phar/config.php` becomes
`phar:/gate.phar/config.php`, which PHP cannot open, so no file inside the
gate's PHAR (ADR-0022) can be Testo's config. Its XML coverage holds no
times. Its JUnit log does, under names of Testo's own, which are not the ids
the XML coverage gives.

## Decision

1. **The pin is a minor range.**
   - **The range.** The runner drives Testo `~0.10.56`: 0.10.56, the first
     release the Runner contract suite passed, and every later 0.10 release.
     `composer.json` requires it for the package's own development and
     declares the `conflict` `<0.10.56 || >=0.11` for a user's project. The
     Testo fixture requires the same range, so its `runner contract` legs run
     0.10.56 and the release its lock holds.
   - **The runner checks it too.** The Testo runner cannot judge a project
     whose vendor directory holds a Testo release outside the range, and
     names it, so the PHAR (ADR-0022), which no `conflict` reaches, holds to
     it as well.
   - **The canary moves it.** The runner canary installs the newest Testo
     into the fixture every day and runs its contracts. Where a newer minor
     passes, it names both lines to write: the requirement widened by that
     minor, and the conflict's upper bound raised past it.
2. **The gate's launcher starts Testo with the gate's plugin.** The runner
   starts `bin/mutation-gate-testo` in place of Testo's own script, with the
   project's autoloader, as the PhpSpec runner starts its launcher, so it
   runs from the PHAR too. The launcher takes off the options the gate tells
   it (`--mutation-gate-*`) and runs Testo's `run` with `--config` naming a
   config the launcher writes for that run alone, into a file of its own in
   the system's temporary directory, and deletes once Testo ends. That
   config loads the project's own `testo.php` as it is written, or Testo's
   default where there is none, and adds the gate's plugin to the plugins
   every suite loads, told what the gate told the launcher.
3. **The plugin selects, stops and times.** Its interceptor stands just after
   Testo's filter, ahead of everything that prepares or repeats a test. It:
   - runs exactly the tests whose ids a file lists, `Class::method` as
     Testo's XML coverage names them, or those in the group or matching the
     filter that judges a unit, or those in the test files a coverage run
     names, and answers every other test as skipped without running any of
     it. A data set is never selected apart from its test;
   - once a test has failed or errored, answers every later test as
     cancelled without running it, unless the run asks for every killer;
   - records each test it runs to the file `MUTATION_GATE_RESULTS` names, as
     it starts and as it ended, which gives the first killer (ADR-0013);
   - times each test of a coverage run into a file beside the XML coverage;
   - in a listing run, lists every test with its `#[Group]` groups and the
     holding groups its `#[Holds]` names, and runs none.
4. **Coverage is Testo's `--coverage-xml`.** `Core\Coverage\PhpUnitXml` reads
   PHPUnit's XML layout for both the Testo runner and the Infection adapter,
   so neither adapter names the other (ADR-0001). Each test's time comes from
   the plugin's record, keyed by the ids the XML gives.

This amends ADR-0027, rules 5 and 8, for Testo. Its rules 1, 2, 6 and 7
stand.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **An exact pin, as ADR-0027 gives** | Every Testo release is refused to every user until a pull request takes it. The canary already reds the day a release breaks a contract, as it does for Infection's minor range. |
| **One series, `<0.10 \|\| >=0.11`** | It takes 0.10 releases below 0.10.56, which no contract leg runs. |
| **`--filter`, `--path` and `--group` for selection** | One filter per covering test makes the command line grow with the mutant's tests, and `--group` knows no holding group. The plugin is in every run to record and stop anyway, and a file of ids it reads is how the Codeception and PhpSpec runners select. |
| **Throwing `CancelTest` from the plugin** | Testo counts an exception from an interceptor a pipeline failure, not a cancelled test. |
| **Each test's time from `--log-junit`** | Its names are Testo's own, so a second reader would map them back to the XML's ids; the plugin is in every run and times each test by that id. |
| **Testo's own script with a `--config` of the project's** | The gate's plugin would have to be in the project's `testo.php`. The launcher adds it for the gate's runs alone. |
| **A config file in the gate's `bin`** | Composer's install can hand Testo its path, the PHAR cannot. |

## Consequences

**A user takes each Testo release of the series as it ships.** A release that
breaks a contract reds the canary that day, and the range or the runner moves
in a pull request.

**Two lines of `composer.json` hold Testo**, and the canary names both.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-nine-ports.md): adapters that name no other adapter
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): holding groups
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): supported versions, and Infection's minor range
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the first killer
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): the runner canary
- [ADR-0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md): the PHAR
- [ADR-0027](0027-codeception-phpspec-and-testo-get-native-runners.md): the native runners, amended here for Testo
