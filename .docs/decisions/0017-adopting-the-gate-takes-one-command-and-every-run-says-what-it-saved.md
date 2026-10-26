# ADR-0017: Adopting the gate takes one command, `doctor` finds what would fail first, and every run says what it saved

**Status:** Accepted
**Date:** 2026-09-30

## Context

Adoption is the product. A team tries the gate for as long as its first hour
goes well. It keeps it for as long as the gate visibly saves CI minutes and
makes the suite better.

The path a new user walks, read at source:

- **Install.** `composer.json` declares `conflict: pestphp/pest-plugin-mutate
  <5.0.2 || >5.0.2` (ADR-0004). A project on any other
  pest-plugin-mutate release cannot install, and Composer's message names
  only the conflict.
- **The first run with no config.** Runner, preset and trees come from
  zero-config (ADR-0002).
  - Laravel's default `phpunit.xml` has `<source><include>app`.
  - Symfony's recipe writes `phpunit.dist.xml`, with `<source><include>src`
    (read on `symfony/demo`).
  - PHPUnit looks for `phpunit.xml`, then `phpunit.dist.xml`, then
    `phpunit.xml.dist`. ADR-0002 names only the first and the
    last.
- **What stops a first run.** Each of these is found only once the run meets
  it:
  - no coverage driver;
  - a suite that is red under coverage;
  - both runners installed;
  - a native ignore marker (ADR-0008).
- **How long a first run takes.** A full run of a medium project takes most
  of an hour. Its only estimate is lines of code × 0.2 s (ADR-0006),
  which is far off wherever every test boots a framework. Nothing shows
  progress.
- **What `init` writes.** It writes "exactly what zero-config found"
  (ADR-0002). A written `trees` replaces the tree source's whole
  list (ADR-0002). So a directory added to `<source>` later, or a
  module that discovery finds, is never mutated, and nothing says so.
- **The first CI run.** With no baseline committed, a tree has no floor, and
  CI stops with exit code 2 (ADR-0003). The user then needs a
  full local run to produce the file.
- **Setup takes several commands.** `init` has grown `--format`, `--ci`,
  `--editor` and `--from` (ADR-0002, ADR-0015, ADR-0016), each a command of
  its own to know about.
- **What a run saved.** The run's cost is a collapsed section at the end of
  the PR comment (ADR-0016). The gate's claim, that reach and
  proofs make mutation testing affordable, is not shown where anyone
  reads it.
- **What the plain tool would cost.** No figure compares the gate with plain
  Pest or Infection on real projects.

What the gate can know about time, measured:

- each unit's timing, kept for proved and carried units too (ADR-0006);
- each shard's opening run (ADR-0013);
- which units reach carried and which proofs covered (ADR-0003,
  ADR-0007);
- on GitHub, every job's start and end, through the API, with the
  `actions: read` the verdict already holds (ADR-0011).

What it cannot measure: what holding, test order or the equivalence skip
would have cost without them.

## Decision

### Setup

1. **One `init`, which asks only what detection cannot settle.**
   - It detects and prints, with the evidence for each: the runner, the
     preset, the trees, the PHPUnit config, the CI, an Infection config, and
     native markers.
   - Where no config is here, it asks, in this order, and only when the
     answer is not settled, each question offering what detection found as
     its default:
     - the runner, when both are installed (`--runner`);
     - the CI, when none or several are detected (`--ci=<provider>`), from
       the CIs it writes a definition for, or none. Detection looks for each
       CI's own files, such as `.github/workflows/` and `.gitlab-ci.yml`.
       `--ci` with no value takes the detected CI;
     - whether to import an `infection.json5` it found (`--from`,
       ADR-0016);
     - what to do with native markers it found (`--native=allow|refuse`);
     - where to set the pre-push hook up
       (`--hook[=git|captainhook|grumphp|pre-commit|none]`, `--no-hook`): a
       hook framework whose config the project holds is offered first, then
       git's own hooks, which `--hook` alone names (ADR-0024);
     - whether to add `composer mutate` (ADR-0024), last, and
       only of a person at a terminal: a yes prints the two Composer
       commands that add the plugin and the root script that needs none,
       and changes no file.
   - With a config already here, it asks nothing, and makes only what the
     command line asks for.
   - With `--no-interaction`, with no terminal, or with `CI` set, it asks
     nothing. The runner is the detected one, or exit 2 when both are
     installed and `--runner` is absent. The CI is the detected one, or none
     written. An Infection config found is imported. Markers stay refused.
     No hook is installed.
   - It writes the config, the CI definition (ADR-0015),
     `.gitignore`'s line, the hook when asked, and VS Code's files with
     `--editor` (ADR-0015). It never overwrites a file.
   - `--dry-run`, or its other name `--stdout` (ADR-0015), prints every
     file instead of writing it, the config and the `.gitignore` line among
     them.
   - It ends with the first-run estimate and the next three commands to
     run: `doctor`, the first run, and `git add` of the files it wrote.

2. **`init` writes only what should stay fixed.** The config it writes holds:
   - `runner` and `preset`, so a second runner installed later does not
     change the answer;
   - `$schema`, in JSON;
   - what the questions settled: `ignores.native`, and what `--from`
     imported (ADR-0016).

   Trees stay with the tree source. The trees it found are listed in the file
   as a comment in PHP, YAML and NEON, and in `init`'s output for JSON, which
   has no comments. `config:show` prints the effective config. Adopting the
   file still changes nothing until somebody edits it. This supersedes
   ADR-0002's "a starting file holding exactly what zero-config
   found".

3. **Zero-config reads the PHPUnit config PHPUnit reads**: `phpunit.xml`,
   else `phpunit.dist.xml`, else `phpunit.xml.dist`. ADR-0002
   names the three.

4. **The first-run estimate comes from one coverage run.**
   - `init` runs the suite once under coverage and plans a full run from
     that map, as `plan` does, each unit costed by the cost model's cold
     start below.
   - It prints:
     - the wall time of a full run cut as `shards.seconds` or
       `shards.target` asks (ADR-0013), its runner time, and what the
       estimate rests on;
     - the hot paths that dominate the estimate (ADR-0005),
       each with the `#[Holds]` that would cut it.
   - The estimate is one figure. A range needs the error between estimate
     and result that the benchmark measures.
   - The same run proves the suite passes under coverage and that a driver
     works. A failure is reported then, with its fix, rather than as a first
     *cannot judge*.
   - `--no-measure` skips the run and estimates from lines of code, as
     `--dry-run`, which runs nothing, does.
   - The cost model's cold start is ADR-0006's measured first
     run: the mutants the gate's own engine counts on each covered line,
     each costing a mutant's run starting and its covering tests' time,
     spread over the processes the runner runs at once. Lines of code ×
     `costs.secondsPerLine` remains the cold start where nothing was
     measured. Costs decide placement only, so a wrong estimate costs time,
     never a verdict. ADR-0015 picks the GitHub shape from the
     lines-of-code estimate.

5. **A run shows its progress and an ETA.**
   - Progress is counted by batch and shard (ADR-0008). Inside
     one runner invocation, only the runner sees progress.
   - The ETA comes from the cost model, and says when it is estimated
     rather than measured.
   - In CI it is one line per batch.
   - A local full run whose estimate exceeds 15 minutes says so before it
     starts, and names `--budget` and `watch`.

6. **A first CI run with no floor measures first, then refuses.**
   - The run mutates as any run does.
   - The verdict still exits 2 for each tree with no floor (ADR-0003),
     and there is still no default floor.
   - Its message, step summary and PR comment carry the exact
     `mutation-gate.baseline.json` it measured. The verdict writes it to
     `.mutation-gate/baseline.measured.json`. The reusable workflow uploads
     it as the artifact `mutation-gate-baseline`.
   - Its proofs are written, so the run after the file is committed is
     proved rather than repeated.
   - A run cut short by its budget offers no file, as it never raises a
     floor (ADR-0008).

   This refines ADR-0003: CI now measures before it refuses.

7. **Native markers stay refused, and adoption gets a visible bridge.**
   - `ignores.native: refuse` stays the default (ADR-0008).
   - `init` asks when it finds markers. *Allow for now* writes
     `ignores.native: allow`.
   - Under `allow`, every run prints *N native markers hide mutants with no
     reason; see `doctor`*.
   - `doctor` lists each marker with its file, line and enclosing function,
     and the `ignores.entries` shape that replaces it. The reason is left for
     a person to write.

8. **The runner pin stays exact, and a daily job keeps it current.**
   - The `conflict` on untested runner releases stays (ADR-0004,
     ADR-0011).
   - A daily scheduled job, the runner canary, runs the Runner contract
     suite against the newest releases of `pestphp/pest-plugin-mutate` and
     `infection/infection`, each freed from the package's pin. It fails,
     naming the line to write, when a release that passes is one
     `composer.json` refuses: pest-plugin-mutate by its `conflict`, Infection
     by its `require-dev`. So each release is added by a pull request within
     a day of passing.
   - The required `runner contracts` check passes where each of its legs
     passed, which run the releases the fixture libraries' committed locks
     hold, which Dependabot moves, and the lowest each supports, so a new
     release reds the canary alone.
   - The README's install line is `composer require --dev
     nightworksio/mutation-gate -W`, with the reason: `-W` lets Composer move
     the runner to the release the gate has tested.

### `doctor`

9. **`mutation-gate doctor` says what would fail, or run slowly, before a run
   does.**
   - It is read-only, offline and fast. It reads the config, the project's
     files, git's config and whether the clone is shallow, the runner's PHP
     as it describes itself, and what `.mutation-gate/` holds from earlier
     runs: timings and the ledger. A shallow clone is `shallow-clone`, *slow*.
   - `--measure` adds the coverage run of the first-run estimate. It runs the whole
     suite once, finds the units as a run does, and writes no map. Where
     measuring fails, `coverage-run-failed` says why. Where the run covers
     no line, `coverage-empty` says so. Both are *will fail*.
   - A hot path nothing holds is found only under `--measure`, over the
     fresh map and the held units a run finds. What holds a file is the
     groups the runner lists, and only running it lists them.
   - `--online`, with a token, reads GitHub's settings: whether branch
     protection requires the verdict, the fork approval policy, and whether
     a schedule exists.
     - The repository is the one `GITHUB_REPOSITORY` names, else the one
       git's `origin` remote is on GitHub's host. It is asked with
       `GITHUB_TOKEN`, else `GH_TOKEN`.
     - Its findings are advice: `verdict-not-required` where neither a
       ruleset nor branch protection requires `ci.check` on the default
       branch; `fork-approval-weak` short of approval for all outside
       contributors (ADR-0019); and `schedule-not-running` where
       a GitHub workflow that runs the gate is disabled for inactivity, or
       ran on no schedule in the last 8 days.
     - A setting GitHub does not show is `online-unread`, naming the
       permission that shows it. With no repository on GitHub, it is
       `no-github-repository`, and every other check still runs.
   - Each finding has:
     - a severity: *will fail* (a run would exit 2 or be *cannot judge*),
       *slow* (with the time at stake, where the timings know it) or
       *advice*;
     - what it found, and why it matters;
     - the exact fix, as a command or the lines to add;
     - the troubleshooting slug and link of ADR-0018.
   - It exits 1 when any finding will fail, and 0 otherwise.
     `--format=text|json` chooses the output. The JSON is public API
     (ADR-0011): `{"format": 1, "failsARun", "findings": [{"slug",
     "severity", "found", "why", "fix", "seconds", "link"}]}`, where `seconds`
     is the time at stake and only a *slow* finding has it. Its schema is
     generated from the same enums and committed at
     `resources/doctor.schema.json`.
   - Beside the checks above, it finds a config every command
     refuses (`config-refused`) and a runner's PHP that cannot describe itself
     (`php-not-read`), both *will fail*.
   - It never edits a file, as `hook install` never overwrites a hook
     (ADR-0010).
   - `plan` and a one-process run begin with the same checks, one line for
     each *will fail* and *slow* finding. Users meet them without knowing
     `doctor` exists. The checks are the core's `Diagnosis`, over what an
     adapter observed of the project without running its code.
   - The PHP the runner uses describes itself, as it does for a proof's key
     (ADR-0007): its extensions, settings and php.ini, run with the runner's
     own options (Infection's `initialTestsPhpOptions`), never seeing a
     variable withheld.

10. **The checks.** Each has a detector with no false positive on the
    benchmark's projects, a contract test of its detection and message, and
    an exact fix.

    | Check | Severity |
    |-------|----------|
    | No coverage driver in the PHP the runner uses | will fail |
    | Xdebug loaded in a mode other than `coverage` or `off`, or Xdebug as the driver where pcov is available | slow |
    | `opcache.enable_cli` or `opcache.file_cache` on, which leaves mutants judged by reference unjudged (ADR-0004) | will fail |
    | Both runners installed, and no `runner` | will fail |
    | No tree found | will fail |
    | A tree with no floor and no baseline, where a CI definition runs the gate; advice where none does | will fail |
    | Native markers under `refuse` | will fail |
    | A mirrored path repository (`symlink: false`) holding a tree | will fail |
    | `pest.patch: true` with an installed pest-plugin-mutate that does not carry `pest:patch`, which `post-install-cmd` and `post-update-cmd` apply | will fail |
    | Pest with more than one shard in the recorded timings, and no `pest.patch` | slow: the opening run × (shards − 1) |
    | A hot path nothing holds (ADR-0005), under `--measure` | slow: its units' timings, or else its covering tests' time |
    | A ledger past the compressed limit a run reads (ADR-0013), or at its proof cap with under 50% hits over its last 10 runs | slow |
    | The gate's own `memory_limit` under what its ledgers may need (ADR-0013), where its command could not raise it | will fail |
    | One file outside the tests that invalidated most proofs in recent runs, found by comparing the digests of each item of the key, which every proof keeps (ADR-0007) | slow |
    | A GitHub workflow that checks out without `fetch-depth: 0` | slow: everything is reached |
    | A GitHub workflow with no `schedule`, `cache: false` on the directory store, the action pinned by tag, or `persist-credentials` left on | advice |
    | Ignores expired or expiring within 14 days (ADR-0008) | advice |
    | `.mutation-gate/` not in `.gitignore` | advice |
    | A tree outside `sonar.sources` in `sonar-project.properties`, while a `sonar` report is listed (ADR-0028) | advice |
    | An `infection.json5` with `minMsi` or native ignores | advice: `init --from` (ADR-0016) |
    | Infection as the runner, its installed Infection without `infection:patch` (ADR-0004) | advice |
    | `runner.memory` at `-1`, a PHPUnit config whose `memory_limit` lifts the cap, or, under `--measure`, a suite that held over half the cap (ADR-0004) | advice |

    The size threshold is the limit ADR-0013 sets, twice what a
    ledger at the retention cap measures, so a ledger past it is one no run
    reads. The hit threshold, 50% over 10 runs, is recalibrated from the
    benchmark.

### What a run saved

11. **Savings are measured against the gate itself: a full run in one job.**
    - **The full run** is one setup, one opening run and the sum of every
      unit's timing (ADR-0006, ADR-0013).
    - **Reach saved** the timings of the units the change did not reach,
      whose results were carried.
    - **Proofs saved** the timings of reached units whose key matched,
      counted after reach, so no unit is counted twice.
    - **Sharding** is shown as two figures:
      - *wait saved*: the full run less the run's critical path;
      - *runner time spent*: setup and opening run × (shards − 1).

      Sharding costs runner time, and the output says so.
    - Holding, test order and the equivalence skip are not counted, because
      nothing measures what they would have cost without them.
    - Each figure carries its estimated share: units with no timing are
      estimated as the first-run estimate is.
    - A run with no timings says *no history yet*, never *saved 0*.
    - Figures against plain Pest or Infection come only from the
      benchmark.

12. **What a run spent is measured where the CI allows it.**
    - On GitHub, the verdict, and the one-step action, read the start and end
      of every job in their run and attempt through the API. That gives
      exact runner time per job, and wall time.
    - Elsewhere it is the flows' own timings (ADR-0016) plus
      `shards.setup` per job (ADR-0013), labelled *estimated*.
    - The figure is *runner time*. GitHub rounds each job up to a whole
      minute for billing, and the output does not claim to be a bill.

    This refines ADR-0016, which takes `shards.setup`
    everywhere.

13. **One headline line says it, wherever a summary is shown.**
    - The line: *Judged in 6m wall, 14m runner time. A full one-job run:
      1h 41m (94% measured). Reach saved 1h 20m, proofs 7m; sharding cut the
      wait by 38m and cost 3m of setup.*
    - It is the console's last line, a line of the step summary, and the PR
      comment's line directly under the verdict. It is not collapsed.
      ADR-0016's collapsed section keeps the breakdown and the
      money.
    - An unsharded run leaves out the sharding clause. A runner time the CI
      did not measure reads *14m runner time (estimated)*. A run with no
      history reads *Judged in 6m wall, 14m runner time. No history yet, so
      nothing saved is shown.* The line never carries money. Durations are
      said to the nearest unit shown: `45s`, `6m`, `1h 41m`, `0s`.
    - The JSON report gains a `savings` object beside `cost` and `run`
      (ADR-0016): `{"fullRun": {"seconds", "measuredPercent"}, "saved":
      {"reachSeconds", "proofsSeconds"}, "sharding"?: {"waitSavedSeconds",
      "setupSeconds"}}`, or `{"noHistory": true}`. `measuredPercent` is a
      whole percent, truncated.
    - On the default branch:
      - each `trend.json` entry gains `runnerSeconds` and `fullRunSeconds`
        (ADR-0009);
      - the step summary shows the total of the last 30 days, under the
        headline: *In the last 30 days the gate saved 41h of runner time.*
        Each run counts its full one-job run less its runner time, never
        below nothing, and this run counts too. The flows hand the verdict
        the trend they read, on the default branch only, and the line shows
        only then. The trend goes in the run's account,
        `RunAccount::after(Trend)`, which the verdict takes with
        `Verdict::withAccount` beside the run's timings, cost and savings;
      - the verdict writes `savings.json` beside `badge.json`, a shields.io
        endpoint (*mutation time saved: 41h / 30 days*), which the publish
        job publishes with the others. Showing it is the project's choice.
        With no run that knew both times, its message is *no history yet*,
        in lightgrey; otherwise it is blue.

### The benchmark

14. **Four open-source projects, pinned, prepared in this repository.**

    | Slot | Project | Runner and size (on 2026-09-30) | What the arms mutate |
    |------|---------|---------------------------------|----------------------|
    | Small library | `lcobucci/jwt` | PHPUnit 13 and Infection 0.35 with its own `infection.json.dist`; 73 source and 68 test files | `src` |
    | Large library | `Roave/BetterReflection` | PHPUnit 13 with `infection.json.dist`; 145 source and 279 test files | `src/SourceLocator/Type`, 29 files, judged by the whole suite |
    | Laravel app | `pinkary-project/pinkary.com` | Pest ^5.2.1; 178 app and 152 test files | `app/Actions` |
    | Symfony app | `symfony/demo` | PHPUnit 11, moved to the PHPUnit 13 and Infection 0.35 the gate locks by the harness; 34 source and 12 test files | `src` |

    - Each is pinned to a commit and prepared by harness steps under
      `bench/`: a `composer require --dev` of the gate and, where needed, a
      runner bump. Nothing is changed upstream.
    - Each passed its probe: it installs on PHP 8.5 and its suite passes
      under pcov. The large library's alternate, `azjezz/psl`, stands in for
      a project that fails it, so it is not needed.
    - Where three rounds of every arm over a whole project do not fit a
      job's five hours, the arms mutate one directory of it, judged by its
      whole suite. Plain Infection takes it as `--filter`, and the gate as
      the rest of the tree excluded (ADR-0016). BetterReflection's whole
      `src` does not fit; its `src/SourceLocator/Type` does.
    - Where the project's own Infection config cannot run, the harness takes
      out only what stops it, and the arm says so. BetterReflection's names
      PHPStan, which reports two errors at the pinned commit, so Infection
      refuses to start. `symfony/demo` has no Infection config, so one is
      written: `src`, with Infection's default mutators.
    - Where a project's suite cannot run in parallel as it stands, the
      harness makes it, as a team running it in parallel would.
      `symfony/demo`'s suite writes to one SQLite file, which processes that
      share it lock each other out of, so each worker runs on a copy of its
      own, by the `TEST_TOKEN` Infection and the gate give it.
    - Where the gate's arm runs Pest, it runs under `pest.patch`, and the
      harness adds a test of its own in the `mutation-canary` group: each
      run opens on it with the map the gate measured, not on the whole
      suite under coverage again.
    - `filamentphp/filament`, a Pest monorepo, joins once ecosystem
      discovery is built.
    - `bench/` is export-ignored.

15. **Three arms and four scenarios.**
    - **The arms:**
      - plain: `pest --mutate --parallel`, or `infection --threads=max`
        with the project's own config;
      - the gate in one job;
      - the gate sharded at the `shards.seconds` its config has, planned
        once, each shard on a machine of its own and the verdict on one
        more. Its wall time is the plan's, the slowest shard's and the
        verdict's; its runner time adds every shard's. Each is also shown
        with every machine's setup, since each sets the project up again;
      - where the project's runner is Infection, the gate in one job with
        its own engine and forked PHPUnit workers (ADR-0023). Its mutants
        are its own, so it is compared by count per file, never reconciled
        one by one.
    - The plain arm runs in a copy of the project the gate's
      `infection:patch` or `pest:patch` never touched. Plain Infection has a
      JSON log added to the project's own config, since it writes one only
      where its config names it. Plain Pest, in parallel, names only its
      untested and uncovered mutants, so its others are reconciled by count.
    - **The scenarios:**
      - *cold full*: an empty ledger and no timings;
      - *warm full*: a full run at the pinned commit with a scripted edit
        on the ledger of a full run at the pinned commit without it. The
        edit renames a local variable throughout one source file and one
        test file the project names: its code changes and its behaviour
        does not, so the keys of what reads either file change and no
        verdict does, and what the run reuses is what a small change
        leaves alone;
      - *PR replay*: the project's last 20 merged pull requests, or those
        of them whose code runs on the packages the preparation installs
        (15 for `lcobucci/jwt`, whose older ones predate its PHPUnit 13
        support), each run change-scoped from its base with the ledger the
        default branch's runs left there, reported as median and p90. Where
        the runner is Infection, it is also compared with Infection's own
        `--git-diff-lines`;
      - *unchanged re-run*, labelled as the trivial case it is.
    - Each scenario's commits are laid over the prepared project, whose
      packages stay as its preparation installed them. Before every run, the
      files git ignores in the directories the project names as where its
      runs write build output, such as `pinkary.com`'s `storage/framework`,
      are removed, as a CI job starts without them.
    - Every round of a warm full run starts from one commit and one ledger,
      and every unchanged re-run from a ledger that only grew, so no later
      round runs more units than its first. One that does fails the
      scenarios' job, with the units each round ran: a file an earlier round
      wrote entered the keys the next one read, and its time measures that,
      not reuse.

16. **It runs on GitHub-hosted runners, on demand and monthly.**
    - It is `bench.yml` in this repository, on `workflow_dispatch` and a
      monthly schedule, never on pull requests. It calls
      `bench-project.yml` once for each project, whose jobs each fetch the
      project and prepare it with `bench/prepare.sh`.
    - All arms of one project run in one job on one machine, interleaved,
      three times. The result is the median with its range. The scenarios
      past a cold full run take a job of their own. Where one job cannot
      hold them, the project's `replayJobs` cuts its PR replay into that
      many runs of consecutive pull requests, each a job that starts from a
      full run at its first base, as the whole replay does, beside a job
      for the warm full and unchanged re-runs; a last job reports them
      together. `pinkary.com`, each of whose pull requests runs about as long as
      a full run, cuts its replay into four.
    - The sharded arm runs as a plan job, a matrix of one job a shard, each
      shard three times from the one plan, and a verdict job that judges
      each round. It says how many machines it ran on.
    - The runner's CPU model and memory are recorded.
    - A scenario over five hours is dropped, because a job has six.

17. **The numbers are kept honest by rule.**
    - **Pinned:** each result file holds the project's commit, its lock, the
      gate's commit, the PHP and runner versions, and the CPU.
    - **Same work:** each arm's mutant counts per file and status go into a
      reconciliation table. A difference the gate's statuses do not explain
      (triage, confirmation) fails the benchmark. Plain Infection skips a
      mutant whose tests take longer than its timeout, and its JSON log
      only counts those; its text log names each, so they are matched with
      the gate's by file, line and mutator, and the skip explains them.
      Plain Infection times a mutant out at its own limit, five times its
      tests' time within its `timeout`, where the patched Infection the gate
      runs allows the gate's limit (ADR-0008); a mutant it timed out that
      the gate killed or that survived is explained by the limit. A
      gate mutant the plain arm lacks is explained where a mutator of the
      gate's own sets made it (ADR-0021), or where it is uncovered and plain
      Infection, without `--with-uncovered`, makes no mutant on a line no
      test covers.
    - **Plain at its best:** in parallel, under pcov, with the project's own
      config.
    - **Spread, not best-of:** the median of three, with the range.
    - **Losses shown:** every scenario is published, including those where
      the gate is slower, such as a cold full run, which confirms survivors
      and triages timeouts.
    - **Generated:** the README's table is rendered from
      `bench/results/<gate version>.json`, the newest by the date it holds,
      and a test fails when the README differs. The gate version is what
      `git describe --tags --always` says of the commit measured. The
      workflow's publish job writes that file and the README rendered from
      it as an artifact; the table links the workflow run and the raw file.
      Numbers reach the README through a pull request that commits both,
      never by a hand edit.
    - **Reproducible:** `composer bench -- <project> [cold|scenarios|sharded]`
      runs the same harness locally, the sharded arm's shards one after
      another.

### Migration

18. **`init` migrates a project from plain Pest mutation testing.**
    - It detects `--mutate` in Composer scripts and CI files, with its
      `--min=` and `--covered-only`, the `@pest-mutate-ignore` markers, and
      `covers()` and `mutates()` in tests.
    - It reports each in the form `init --from` uses (ADR-0016):
      - `--min=N` becomes N as each tree's declared floor, as `minMsi` does;
      - `--covered-only` becomes `uncovered: exclude`;
      - markers stay refused, with the adoption bridge;
      - `covers()` and `mutates()` no longer narrow which tests judge a
        mutant (ADR-0004), so scores can move, and the report
        says which way.
    - The settings go into the config `init` writes. Scripts and CI files are
      never edited, and the lines to remove are printed.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **A wizard that asks every setting** | It asks for what nobody can know before a run, such as a floor. |
| **A non-interactive `init` only** | "Both runners installed" and "which CI" would have to be known as flags before the first try. Every question here has a flag anyway. |
| **A full snapshot of zero-config in the written file** | A written `trees` silently freezes the trees, and every copied default becomes a pin. |
| **A snapshot less `trees`** | Copied defaults stop following the package's defaults. |
| **Estimating from lines of code alone** | Instant, and far off on framework apps, where the estimate matters most. |
| **The first CI run refusing before it mutates** | Fails fast, and then demands a full local run to produce the file. |
| **Holding every tree to new code only until a baseline exists** | Every tree would be exempt until someone acted, which is the default floor ADR-0003 refuses. |
| **`allow` for native markers under zero-config** | Writing a config file would then change behaviour, which `init`'s rule forbids. |
| **A runner pin widened to the major, refused at runtime** | A runner's patch release would turn a user's CI red, where the `conflict` lets Composer hold the runner back. |
| **`doctor --fix`** | It would edit files the gate did not write. |
| **Warnings inside runs, and no `doctor`** | Nobody could ask what is wrong before spending a run. |
| **One savings number: the full run less the actual one** | It hides that sharding costs runner time, and it credits estimates as savings. |
| **Savings against plain Pest or Infection per run** | It needs a model of a tool that never ran. The benchmark measures it for real. |
| **Setup always estimated from `shards.setup`** | Throws away GitHub's exact job times. |
| **Savings only in ADR-0016's collapsed section** | The gate's main claim would go unseen. |
| **Benchmark projects that already run mutation testing, only** | The fairest plain arm, and no Pest or framework app among them. |
| **Generated fixture projects** | Reproducible, and not the well-known projects readers recognise. |
| **A self-hosted benchmark machine** | Stabler numbers that readers cannot reproduce. A self-hosted runner on a public repository must also be kept away from pull requests. |
| **Results from the maintainer's machine** | Neither reproducible nor independent. |
| **A separate `migrate` command** | A second command writing the file `init` writes. |

## Consequences

**A first setup is one command and at most five questions.** It ends with an
estimate that has already proved the suite runs under coverage.

**The failures that used to come first now come from `doctor`,** or from the
first lines of a run, with their fixes.

**CI adoption no longer needs a local full run.** The first CI run hands over
the baseline to commit.

**Every pull request says what the gate saved,** measured where the CI
allows, estimated and labelled where it does not, and with the cost of
sharding shown too.

**The README's performance claims are the benchmark's,** with losses shown,
and nobody edits them by hand.

## Related

- [ADR-0002](0002-one-typed-config-from-several-formats.md): what `init` writes, and the PHPUnit config zero-config reads
- [ADR-0003](0003-a-floor-only-rises.md): the first CI run without a floor
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the runner pin
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the cold start from a coverage map
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the per-item digests each proof keeps
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): native markers
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the headline, `savings` and `savings.json`
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the canary and benchmark jobs, and `doctor` as public API
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): opening runs and `shards.setup`
- [ADR-0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): what `init --ci` writes
- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): `init --from`, and the run's cost
- [ADR-0018](0018-the-documentation-is-versioned-and-tested-with-the-code.md): the troubleshooting slugs `doctor` links
- [ADR-0028](0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md): the check against `sonar.sources`
