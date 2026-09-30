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
        to judge.
     3. Units never mutated.
     4. Units reached by a changed test or test support.
     5. Everything else, most recently changed first.
   - **Batches.** A runner orders mutants inside one invocation itself, so the
     gate controls order between invocations. It runs units in batches, each
     sized by the cost model (ADR-0006) to fit the remaining budget. Batches
     are as large as the budget allows, because each one pays the runner's
     opening run. When the next batch does not fit, none is started. At the
     deadline the runner is stopped.
   - **Unjudged mutants.** Every mutant the budget left without a result is
     *unjudged*. Such a mutant:
     - counts as not killed (ADR-0003);
     - is listed by unit in every report, with the command that judges it;
     - keeps its unit out of the ledger (ADR-0007);
     - means its tree's floor is never raised by this run.

     A budgeted run can fail for lack of time. It can never pass a mutant it did
     not judge.

2. **Timeout triage tells a detection from the clock running out.** Each
   runner sets a limit per mutant (ADR-0004):
   - **Pest's** is its opening run's duration plus the larger of 5 s and 20%,
     and it cannot be changed.
   - **Infection's** is the smaller of 5 s plus five times the covering tests'
     own time, and `timeouts.seconds`: an integer, 10 by default as Infection's
     own, and 30 in the Laravel and Symfony presets. A mutant whose covering
     tests take at least `timeouts.seconds` is skipped, never run.

   For every timed-out or skipped mutant the gate works out the limit that
   applied to it, and compares its judging tests' own time with it (ADR-0004,
   decision 5). That time comes from the coverage run: Pest's map, or the JUnit
   times of the judging test classes, which Infection sums the same way.
   - **Under half the limit.** Tests that normally finish quickly ran past the
     limit with the mutant in place. The mutant broke something (a loop that
     never ends, say), so it is **killed by timeout**. It counts as killed and
     is reported under that name. Under Infection's own formula this holds for
     every timeout the configured cap did not decide.
   - **Half the limit or more.** The limit says nothing about this mutant, so it
     is **too slow to judge**, and counts as not killed. A skipped mutant that
     its retry does not resolve is always here. The hint points at holding the
     path with a group (ADR-0005) or raising `timeouts.seconds`.
   - **Retry (Infection only).** Before the rule is applied, each timed-out or
     skipped mutant that the configured cap decided is run once more with
     `timeouts.seconds` doubled, narrowed to its file and mutator (ADR-0004).
     If it finishes, its real status replaces the timeout. If not, the rule is
     applied with the doubled limit. A mutant whose limit came from the formula
     is not retried, because a higher cap would not change it. At most
     `timeouts.retries` mutants are retried per shard, an integer, 20 by
     default. Pest's limit cannot be raised, so Pest has no retry.
   - **The mode.** `timeouts.mode` is `confirm` by default, as described above,
     or `unjudged`, which makes every timeout too slow to judge, for projects
     that want no kill they cannot see.

   Triage reads the recorded status and the coverage run's times, so it is
   applied at verdict time, and a unit whose timeouts are too slow to judge is
   still recorded (ADR-0007): running it again would give the same answer.

3. **Flaky triage: the same code giving two answers is reported, not averaged.**
   - **Survivor confirmation.** Each survived mutant is run once more, alone,
     before it counts. Killed the second time means **flaky**. Survived again
     means a confirmed survivor. Survivors are few, so this is cheap.
     `flaky.confirmSurvivors` is a boolean, `true` by default. A mutant
     judged by reference (ADR-0004, decision 8) is run again through those
     same steps, against its judging tests. The confirming run takes the
     runner's own test order, whatever `tests.order` says, and a survivor
     proven equivalent is not run again (ADR-0013, decisions 4 and 10).
   - **Proofs that disagree.** When two results for one key differ (the
     default branch's ledger and a pull request's, say, or two verdicts that
     wrote one scope), the mutants that differ are flaky, and neither result
     is used (ADR-0007).
   - **What a flaky mutant does.** It counts as not killed, keeps its unit out
     of the ledger, and is reported with its judging tests, which are the
     suspects.
   - **On demand.** `mutation-gate triage <path> --repeat=<n>` runs a unit n
     times, 5 by default, and lists every mutant whose status varied.
     `--order=runner|killers-first`, `tests.order` by default, chooses the
     order of each mutant's tests, to hunt a kill an order made (ADR-0013). Its
     mutants judged by reference run through ADR-0004's decision 8 each
     time. This is
     the tool for a kill that might be flaky. A flaky kill cannot be told from a
     real one without re-running every killed mutant, and the gate does not do
     that unasked.
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
     applies, and a notice says it can go (ADR-0013, decision 12).
   - **Native markers are refused by default.** These are `@pest-mutate-ignore`
     and `@infection-ignore-all` in source, and `ignore` or
     `ignoreSourceCodeByRegex` under `mutators` in `infection.json5`. With
     `ignores.native: refuse`, the default, the run stops with exit code 2
     before anything is mutated, listing each marker and the config entry that
     replaces it. A marker hides the mutant from the count without a reason or
     an end.
   - **`ignores.native: allow` lets a project migrate.** `init --from`
     converts Infection's `ignore` patterns into entries, and sets `allow`
     only for the regex ignores that have no equivalent (ADR-0016,
     decision 4).
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
