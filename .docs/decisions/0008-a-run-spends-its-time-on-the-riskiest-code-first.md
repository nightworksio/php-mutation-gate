# ADR-0008: A run spends its time on the riskiest code first, and never passes what it did not judge

**Status:** Accepted
**Date:** 2026-09-29

## Context

Four things decide whether a mutation gate can be trusted day to day, beyond
what it mutates.

- **Time.** A developer at a pre-push hook, or a CI job with a fixed slot, has
  minutes, not the hour a full run takes. A run that stops early must spend
  those minutes where a survivor is most likely. What it did not get to must
  never read as passed.
- **Timeouts.** The in-house gate's worst shard spends fifteen to thirty minutes
  mostly on timeouts. Those are mutants of code every test runs through, whose
  covering tests take nearly as long as the limit. A timeout there is not a test
  saying what broke. It is the clock running out.
- **Flaky tests.** A test that sometimes fails kills mutants it did not detect,
  and a test that sometimes passes lets real survivors go. Either way the score
  moves between two runs of the same code.
- **Equivalent mutants.** Some mutants cannot be killed because they do not
  change behaviour. Both runners have ignore markers (`// @pest-mutate-ignore`,
  `@infection-ignore-all`, Infection's `ignoreSourceCodeByRegex`). A marker
  carries no reason and no end date, and it removes the mutant from the count
  as if it never existed.

A framework app differs from a library in what boots, what every test runs
through and which files decide how the suite runs. The approved scope ships
presets for Laravel, Symfony and plain libraries.

## Decision

1. **A time budget orders the work by risk and stops honestly.**
   - **Setting it.** `budget`, a duration, or `--budget=<duration>` for one
     command. It applies to one process: a whole run, or one shard. There is
     none by default. Watch and pre-push set their own (ADR-0010).
   - **The order.** Units are ordered by risk. Ties go by path.
     1. Units with changed lines, whose mutants the new-code floor judges.
     2. Units whose last recorded result has a survivor or a mutant too slow
        or too heavy to judge.
     3. Units never mutated.
     4. Units reached by a changed test or test support.
     5. Everything else, most recently changed first, by the newest commit on
        `HEAD` that changed the unit (a held path by its newest file). A unit
        no commit changed comes after those one did.

     A run that knows no change, as a full run does not, has no unit in
     classes 1 and 4.
   - **Batches.** A runner orders mutants inside one invocation itself, so the
     gate controls order between invocations. It runs units in batches, each
     sized by the cost model (ADR-0006) to fit the remaining budget. Batches
     are as large as the budget allows, because each one pays the runner's
     opening run. When the next batch does not fit, none is started. At the
     deadline the runner is stopped, and a batch it could not judge once the
     deadline passed is left unjudged. A timeout retry or a survivor
     confirmation runs only as many mutants as fit in the time left, each at
     its whole limit.
   - **Unjudged mutants.** Every mutant the budget left without a result is
     *unjudged*. Such a mutant:
     - counts as not killed (ADR-0003);
     - is listed by unit in every report, with the command that judges it
       (`vendor/bin/mutation-gate run --budget=<duration>`);
     - keeps its unit out of the ledger (ADR-0007);
     - means its tree's floor is never raised by this run;
     - fails the verdict, naming its unit.

     Why a mutant is unjudged is one of three: the budget ran out before its
     unit was mutated, before its timeout could run again, or before its
     survival could be confirmed.
   - **Units the budget never started.** Each proof records, beside its key,
     the digests of its inputs (ADR-0007, decision 3): the unit's source,
     what decides its mutant set besides the source (the gate, the config,
     the runner, what is installed, the files that define the runner and the
     test bootstrap every key reads), and each test file that killed one of
     its mutants, with the support that file reads. A unit the budget never
     started counts by its newest result in the ledgers the run reads only
     where that result's source and mutant-set digests equal this run's. It
     fails the verdict, named with why and the command that judges it, where
     no ledger holds a result of it, where its newest result records no
     digests (a ledger of format 2), or where either digest differs. New code
     in such a unit is therefore never judged by a result of the code before.

     Where the result counts, each of its mutants stands or is unjudged:
     - a mutant the score counts as not killed, such as a survivor, stands,
       which can only make the verdict stricter. An uncovered one stands only
       where the run's coverage map shows no test covering its line now;
     - a kill stands only where every test that killed it is known, each of
       their files, with the support it reads, has the digest it had then,
       and either:
       - the result was established at this run's base, the one its content
         keys are built on (ADR-0007). The base leaves out what the key's
         exceptions leave out, such as `proofs.ignore`'s files, so a kill
         stands across a change to one of those; or
       - nothing that changed since the commit the result records (ADR-0007,
         decision 3) reaches the kill's unit or a test that killed it by a
         name. What changed is git's diff from that commit itself to the
         working tree, read once for each commit however many results share
         it. A changed file reaches every file that names a class,
         interface, trait, enum or function it declares, then or now, by the
         name PHP resolves in that file's namespace and imports, spelt in
         code or as a fully qualified name in a string, or a constant it
         declares, by its last segment; and every file that names what those
         declare in turn. A `use function` or `use const` imports a name and
         declares none. A mutated run can reach code the unmutated run never
         did, so nothing narrower than what the code names bounds what a kill
         depended on.

       A file that is not PHP is named by the words of its name, so a change
       to it reaches the PHP files whose strings spell one of those words, as
       a test that reads `fixtures/rates.json` spells `rates`, and what names
       those in turn. So, besides what it declares, is a PHP file that runs
       code when it is loaded, as a file that requires `data/rates.php`
       spells `rates`. A change the gate cannot follow by name reaches every
       kill: one to a file that decides how the gate runs (ADR-0005,
       decision 4, rule 1, which names the runner's definitions, each
       package's `composer.json`, the gate's config, and what the presets and
       `reach.everything` name), or to a package's `composer.lock`; and one
       to a file any `composer.json` of the repository lists under `files` in
       its `autoload` or `autoload-dev`, which Composer's autoloader loads in
       every process, whatever names it.

       A kill is unjudged, and the verdict warns why, where its killer is
       unknown, changed or gone; where its result records no commit, since
       the working tree then held more than its commit; where git cannot read
       that commit, as a shallow clone does not hold it, which `doctor` names
       too (ADR-0017); and where what changed since reaches it.

       Following names misses code reached with no name on the way: a class
       wired only in YAML or XML service definitions, or that a container
       builds for a key other than its name, a name built by concatenation,
       a call through `__call` or `__callStatic` onto a class nothing names,
       a class in the global namespace spelt only in a string, and a file
       read or required by a path built from pieces none of which is a word
       of its name, or found by listing a directory, as a framework finds its
       config and route files. A kill across such a change stands. A full run
       judges it again;
     - a timeout or a crash, which can count as a kill but names no test that
       caused it, is unjudged;
     - a kill by static analysis stands as a kill does, with the file the
       analyser's finding sits in, which its result records (ADR-0020,
       decision 10), in place of the tests that killed it: where the result
       was established at this run's base, or where nothing that changed
       since the commit it records reaches its unit or that file by a name.
       The mutant's file and the finding's are what the rejection depended
       on. The analyser, its version and the digest of the configuration it
       runs with, with every file that configuration references, such as a
       baseline, an included config or a bootstrap file, are in the mutation
       digest (ADR-0020, decision 14), so a result established before a
       change to any of them does not count. Where the analyser cannot say
       its configuration, its config file's digest stands for it, and a
       change to another file it reads is not followed. One whose result
       records no finding, as a kill Infection reports, or whose finding
       sits in a file outside the repository, whose changes git does not
       say, is unjudged.

     The one assumption left is ADR-0007's: a test's outcome depends on the
     files it reads, not on state another test in the same process leaves
     behind.
   - **Passing.** No run records `passed` for its commit while any mutant is
     unjudged, whatever left it so, so a later run from the last commit that
     passed still reaches what this one did not judge. A budgeted run passes,
     and records it, only where everything it did not run stood from a result
     of the same inputs. Its verdict still says the budget stopped it before
     every mutant was judged.

     A budgeted run can fail for lack of time. It can never pass a mutant it did
     not judge.

2. **Timeout triage tells a detection from the clock running out.** Each
   runner sets a limit per mutant (ADR-0004). The gate's rule,
   `Core\Runner\MutantLimit`'s standard one, is 5 s for the run to start,
   plus k times the covering tests' own time, with k = 3, kept between two
   bounds:
   - **The floor**, `timeouts.seconds`: an integer, 10 by default, and 30 in
     the Laravel and Symfony presets. No mutant is allowed less, and one whose
     covering tests were not all timed is allowed exactly this. It covers the
     few seconds a mutant's run spends before its first test, which a busy
     runner stretches.
   - **The most**, `timeouts.most`: an integer, 300 by default, and never
     less than `timeouts.seconds`, which the config refuses. No mutant is
     allowed more, so one mutant cannot hold a process for long.

   k is fitted from runs, not guessed. A mutant that breaks nothing runs its
   covering tests to the end, so its limit must stay above them on the
   busiest runner the gate meets. Between two self-gate runs of the same code
   the same mutants took 1.34 times as long at the median and 2.09 times at
   the 99th percentile, so k = 3 clears that with margin, and the 5 s
   start-up keeps a mutant whose tests take a few seconds clear too.
   k is fitted again from a measurement run whose limits are
   `max(timeouts.seconds, 10 × T)`, which leaves every run room to finish:
   the 99.5th percentile, over mutants whose covering tests take 3 s or more
   and that ended in a verdict, of the seconds past the start-up each took
   over its tests' own time, with a fifth more for load, rounded up.

   Per runner:
   - **Pest's**, under `pest.patch`, is the gate's rule, of the covering
     tests' own time as the coverage map Pest loaded timed them. The patched
     plugin records each mutant's limit in the results file, and the gate
     triages by what it recorded. It skips no mutant. Unpatched, it is Pest's
     own: the opening run's duration plus the larger of 5 s and 20%, which
     cannot be changed.
   - **Infection's** is Infection's own: the smaller of 5 s plus five times
     the covering test classes' time and its `timeout`, which the gate sets
     to `timeouts.most`. Infection skips, never runs, a mutant whose covering
     classes take at least `timeouts.most`. It has no floor.
   - **The PHPUnit runner's** is the gate's rule, of the covering tests' own
     time as the coverage map timed them. It skips no mutant.

   For every timed-out or skipped mutant the gate works out the limit that
   applied to it, and compares its judging tests' own time with it (ADR-0004,
   decision 5). That time comes from the coverage run: Pest's map, or the JUnit
   times of the judging test classes, which Infection sums the same way.
   The triage reads the limit's own k, so the two never drift apart:
   - **Under the limit at k times.** The limit allowed the covering tests k
     times their own time, the margin the limit itself grants for load. Tests
     that finish well inside that ran past it with the mutant in place, so the
     mutant broke something (a loop that never ends, say), and it is **killed
     by timeout**. It counts as killed and is reported under that name. This
     holds for every limit the gate's rule or the floor decided, which is at
     least 5 s more than k times the tests, and for every limit Infection's
     own formula decided.
   - **k times the tests reach the limit.** Only `timeouts.most` decides such
     a limit. It says nothing about this mutant, so it is **too slow to
     judge**, and counts as not killed. A skipped mutant that its retry does
     not resolve is always here. The hint points at holding the path with a
     group (ADR-0005) or raising `timeouts.most`.
   - **Retry.** Before the rule is applied, each timed-out or skipped mutant
     whose limit `timeouts.most` decided is run once more with it doubled,
     narrowed to its file and mutator (ADR-0004). If it finishes, its real
     status replaces the timeout. If not, the rule is applied with the
     doubled limit. A mutant whose limit came from the formula or the floor
     is not retried, because a higher most would not change it. At most
     `timeouts.retries` mutants are retried per shard, an integer, 20 by
     default. Unpatched, Pest's limit cannot be raised, so unpatched Pest has
     no retry.
   - **The mode.** `timeouts.mode` is `confirm` by default, as described above,
     or `unjudged`, which makes every timeout too slow to judge, for projects
     that want no kill they cannot see.

   Triage reads the recorded status and the coverage run's times, so it is
   applied at verdict time, and a unit whose timeouts are too slow to judge is
   still recorded (ADR-0007): running it again would give the same answer.

3. **Flaky triage: the same code giving two answers is reported, not averaged.**
   - **Survivor confirmation.** Each survived mutant is run once more, alone,
     before it counts. Killed the second time means **flaky**. Survived again
     means a confirmed survivor. Survivors are few, so this is cheap. Under
     Pest the survivors run again in one run of their files with their
     mutators, each still in a process of its own, so Pest's opening run is
     paid once rather than once for each file and mutator.
     `flaky.confirmSurvivors` is a boolean, `true` by default. A mutant
     judged by reference (ADR-0004, decision 8) is run again through those
     same steps, against its judging tests. The confirming run takes the
     runner's own test order, whatever `tests.order` says, and a survivor
     proven equivalent is not run again (ADR-0013, decisions 4 and 10).
     Each shard confirms its own survivors, by the tests that judged their
     unit, and its result file lists the flaky mutants' ids under `flaky`
     for the verdict.
   - **Proofs that disagree.** When two results for one key differ (the
     default branch's ledger and a pull request's, say, or two verdicts that
     wrote one scope), the mutants that differ are flaky, and neither result
     is used (ADR-0007).
   - **What a flaky mutant does.** It counts as not killed, keeps its unit out
     of the ledger, and is reported with its judging tests, which are the
     suspects.
   - **On demand.** `mutation-gate triage <path> --repeat=<n>` runs a unit n
     times, 5 by default and never fewer than 2, and lists every mutant whose
     status varied, with the runs that gave each status and the tests that
     killed it in them. A mutant some runs made and others did not varied
     too. Each run is the runner's own: no timeout is retried, no survivor
     confirmed or checked by an analyser, and nothing is recorded.
     `--order=runner|killers-first`, `tests.order` by default, chooses the
     order of each mutant's tests, to hunt a kill an order made (ADR-0013);
     killers first reads the history every ledger holds of the unit's files.
     Its mutants judged by reference run through ADR-0004's decision 8 each
     time. It exits 1 where a mutant varied, 0 where none did, and 2 where
     the unit cannot be run. This is the tool for a kill that might be flaky.
     A flaky kill cannot be told from a real one without re-running every
     killed mutant, and the gate does not do that unasked.
   - **A failed opening run** is *cannot judge* (exit code 2) and is not
     retried. A suite that fails without mutants has to be fixed first.

4. **Equivalent mutants are ignored in the config, with a reason, and can
   expire.** `ignores.entries` is the list:

   ```json
   {
       "ignores": {
           "entries": [
               { "mutant": "3f9a1c2b7d04", "reason": "Both branches build the same list", "expires": "2027-03-31" },
               { "path": "src/Log/**", "mutator": "MethodCallRemoval", "reason": "Logging is asserted in the integration suite" }
           ]
       }
   }
   ```

   - **Two shapes.** One mutant, by the gate's id (ADR-0004). Or a path glob
     with a mutator, by its full name (ADR-0004), or a family (ADR-0009).
   - **Every entry needs a non-empty `reason`.** `expires` (`YYYY-MM-DD`) is
     optional. `ignores.maxDays`, an integer with no limit by default, requires
     every entry to expire within that many days of the run.
   - **What an ignore leaves out.** A survivor or an uncovered mutant that an
     entry matches. An entry that names one mutant by its id also leaves it
     out where it is unjudged because the file it mutates was loaded before
     the mutant was in place (ADR-0004), which no run can change. A mutant
     unjudged for any other reason, such as a time budget that ran out,
     counts as not killed whatever an entry says.
   - **Ignored mutants** are left out of the score (ADR-0003) and listed with
     their reasons in every report.
   - **An expired ignore** stops applying: the mutant counts again, and the
     report names the ignore and when it expired. Within 14 days of expiry the
     PR comment names it in advance.
   - **A stale ignore** matched no mutant in a run that judged every unit it
     could match. It fails the run (exit code 1) and says *remove it*, as
     PHPStan's `reportUnmatchedIgnoredErrors` does. Otherwise dead ignores would
     pile up, and one could come back to life and hide a new mutant. An
     ignore that matches a mutant proven equivalent is not stale: it still
     applies, and a notice says it can go (ADR-0013, decision 12). A run
     narrowed to some mutators, as `--security` is, checks only the ignores
     that name one of them by its full name: an ignore of any other mutator,
     of a family, or of a mutant's id may name a mutant that run never made
     (ADR-0021 decision 20).
   - **Native markers are refused by default.** These are `@pest-mutate-ignore`
     and `@infection-ignore-all` in source, and `ignore` or
     `ignoreSourceCodeByRegex` under `mutators` in `infection.json5`. With
     `ignores.native: refuse`, the default, the run stops with exit code 2
     before anything is mutated, listing each marker and the config entry that
     replaces it. A marker hides the mutant from the count without a reason or
     an end.
   - **How native markers are found.** Before anything is mutated, the gate asks
     the runner for its markers in the files the run mutates (the Runner port's
     `markers()`, ADR-0004).
     - Pest's: `@pest-mutate-ignore` in any comment of those files.
     - Infection's: `@infection-ignore-all` in any comment of those files, and
       each value of `ignore` or `ignoreSourceCodeByRegex` under `mutators` in
       the project's Infection config, whether under a mutator, a profile,
       `global-ignore` or `global-ignoreSourceCodeByRegex`.
     - Each is listed where it is, as a file and line or as the config file and
       key, with the entry that replaces it. For a marker in source or a
       pattern over source, that is `{"mutant": "<id>", "reason": "…"}` for each
       mutant it hides. For an `ignore` pattern it is `{"path": "<the file of
       the class it names>", "mutator": "<its mutator>", "reason": "…"}`.
   - **`ignores.native: allow` lets a project migrate.** `init --from`
     converts Infection's `ignore` patterns into entries, and sets `allow`
     only for the regex ignores that have no equivalent (ADR-0016,
     decision 4). `init` asks before it sets `allow` for markers it finds,
     and every run under `allow` says how many markers hide mutants
     (ADR-0017).
     - Pest's marker, `@infection-ignore-all` and `ignore` stop the runner
       generating the mutant at all. The report counts the markers it found,
       and says it cannot count what they hide.
     - Infection reports each mutant `ignoreSourceCodeByRegex` matched as
       ignored. The gate records it as *ignored by a native marker*, leaves it
       out of the score, and lists it with that reason in every report.

5. **Framework presets are config fragments with a name.** `preset` takes one
   name or a list, applied in order (ADR-0002). A preset is registered by an
   extension (ADR-0001) and applied before the config file, so the project's
   own settings win. When no `preset` is set, it is chosen from
   `composer.json`: `laravel` when `laravel/framework` is required, `symfony`
   when `symfony/framework-bundle` is, `library` otherwise.

   | Setting | `library` | `laravel` | `symfony` |
   |---------|-----------|-----------|-----------|
   | Trees, when `phpunit.xml` has no `<source>` | each `autoload` path in `composer.json` | `app` | `src` |
   | Also decides how the gate runs (ADR-0005, rule 1) | nothing beyond the defaults | `bootstrap/**`, `config/**`, `routes/**`, `.env.testing` | `config/**`, `.env.test`, `tests/bootstrap.php` |
   | `timeouts.seconds` | 10 | 30 | 30 |
   | `newCode.floor` | 100 | 100 | 100 |
   | `mutators.sets`, each offered (ADR-0021) | `security` | `laravel`, `security` | `symfony`, `security` |

   Framework tests boot the application, so their covering tests are slower
   and their timeouts are longer. Paths every framework test runs through,
   such as a Laravel app's `app/Providers` and a Symfony app's `src/Kernel.php`,
   stay in their trees and are found by the hot-path warning (ADR-0005) rather
   than listed. What is hot differs from app to app. Third-party presets
   (WordPress, Drupal, a company's own) are extensions.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Count unjudged mutants as killed, or leave them out of the score** | Either way, a run that stopped early reads as better than it is. The approved rule is that unjudged mutants are reported and never passed. |
| **Order individual mutants by risk** | Neither runner takes a mutant order. Per-unit batches are the finest order the gate controls. |
| **Count every timeout as killed** (both runners' default) | Counts the clock running out as a test saying what broke, which is how the in-house gate's slowest shard fills with timeouts. |
| **Count every timeout as unjudged** | Loses real detections: a mutant that makes a loop run forever is caught by the timeout and by nothing else. Kept as the strict mode. |
| **Retry every killed mutant to catch flaky kills** | Doubles the cost of every run to find a rare problem. Survivor confirmation is cheap. Flaky kills are hunted on demand with `triage`. |
| **Native ignore markers, as the runners ship them** | No reason, no expiry, no staleness check, and different per runner. The config holds one list for every runner. |
| **Ignores by file and line** | Line numbers move with every edit above them. The gate's id does not use the line (ADR-0004). |
| **Presets that exclude framework glue** (providers, kernels) | Excluding code from mutation hides it from the gate. Holding it with the tests that assert on it keeps it judged and cheap. |

## Consequences

**A short budget gives the most useful minutes.** Changed code runs first, then
code known to have survivors, then code never mutated.

**Timeouts are explained, not counted blindly.** "Too slow to judge" points at
the fix: hold the path with a group.

**The ignore list is reviewable debt.** It has reasons, end dates and no dead
entries.

**A Laravel or Symfony app gets a sensible first run with no config**, and the
warnings say where holding groups would save time.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): how unjudged, flaky and ignored mutants enter the score
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): runner timeouts, retries and Infection's ignored status
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): holding groups and the hot-path warning
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): mutator families, and how unjudged and flaky mutants are shown
- [ADR-0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): the budgets of watch and pre-push
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the order survivor confirmation and `triage` use, and ignores a proof makes redundant
- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): Infection's ignores converted by `init --from`
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): native markers as an adoption bridge
