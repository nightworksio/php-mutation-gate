# ADR-0020: A change's tests can be listed, a static analyser can kill a mutant, survivors are re-checked first, and a huge repository can be sampled

**Status:** Accepted
**Date:** 2026-09-30

## Context

Reach narrows a pull request to what it touches (ADR-0005), proofs skip what
is proved (ADR-0007), and shards are cut by learned cost (ADR-0006). Four
needs remain, and the runners' own behaviour shapes each answer.

- **Plain test runs pay for the whole suite.** The gate keeps a per-test
  coverage map, `map.json.gz` in the directory `--coverage=<dir>` names,
  `"format": 1`, which reading never runs (ADR-0006 decision 1). The same
  map says which tests a change can make fail. PHPUnit 13.3 runs a list of
  test files with `--test-files-file` and a list of test ids with
  `--test-id-filter-file`.
- **Some mutants are invalid programs.** A static analyser proves a mutant
  that returns a `bool` from a function declared to return `int` wrong, with
  no test run.
  - Infection 0.35.5 runs PHPStan or Mago **after** the tests, only on each
    mutant they let escape. It first requires a whole-project run that
    passes, and marks a mutant `killedByStaticAnalysis` on any non-zero exit.
  - Pest has no such step. It generates every mutant, emitting
    `finishMutationGeneration` and then `startMutationSuite`, before it
    starts the first mutant's child process.
  - **Mago** (`carthage-software/mago`) offers
    `analyze --substitute <original>=<mutant>`, which its command reference
    says is designed for mutation-testing frameworks. It keeps no result
    cache, so each run re-scans the project.
  - **PHPStan's editor mode** (`--tmp-file`, `--instead-of`, from 2.1.17)
    re-analyses the file and its dependents from a warm cache, and saves no
    cache.
  - **Psalm** has no such option on its command line. The same substitution
    exists only in its PHP API, `Codebase::addTemporaryFileChanges`, which
    its language server uses.
  - Measured on a copy of this repository (479 source files, an Apple M1
    Pro), one check costs about 1 s of CPU with Mago's `--substitute` and
    about 1.4 s with PHPStan's warm editor mode. PHPStan's cold whole-project
    run costs 52.6 s.
  - Mutation-testing-elements, whose viewer the HTML report embeds
    (ADR-0009), leaves `CompileError` out of the score as an invalid mutant.
- **The survivors of the last push are the first thing a developer wants
  news of.** Neither runner takes a list of mutants (ADR-0006). The Runner
  port's `retry` runs named mutants again, narrowed to their file and
  mutator (ADR-0004), and a scope's ledger holds every survivor's full
  record (ADR-0007 decision 3).
- **A full run of a huge repository does not fit in a week's CI.** A run
  can be cut short by a budget (ADR-0008), but then it judges nothing it did
  not reach.
  - Research on mutation sampling (Gopinath, Alipour, Ahmed, Jensen and
    Groce, ISSRE 2015) finds that a constant-size random sample estimates the
    full score closely, whatever the program's size.
  - Runners take files, so a sample is of files, and a file's mutants are
    correlated: a binomial interval over them is too narrow.
  - The Wald interval behaves badly near 0 and 100, where mutation scores
    sit, and Wilson's does not (Brown, Cai and DasGupta, *Statistical
    Science*, 2001).
  - Nothing in `src` draws a random number (ADR-0001).

## Decision

### The tests a change reaches

1. **`mutation-gate affected` lists the tests a change can make fail.**
   - It takes `--changed-since=<ref>` and `--coverage=<dir>`, as `plan` does
     (ADR-0005, ADR-0006).
   - Formats, by `--format`:
     - `files`, the default: one repository-relative test file per line, for
       PHPUnit's `--test-files-file`;
     - `files0`: the same, each ended by a NUL byte, for
       `xargs -0 vendor/bin/pest`, so no path is split;
     - `ids`: one test id per line, for PHPUnit's `--test-id-filter-file`
       (decision 4);
     - `json`: `{"format": 1, "base", "map": {"commit", "dirty"}, "all",
       "tests": [{"file", "ids", "reasons"}], "unreached"}`.
   - Every test is printed as the full list of test files, so a pipe never
     special-cases it. The reasons go to stderr in every format but `json`.
   - It exits 0 whenever it answered, a list with no test included. Where git
     cannot say what changed it exits 2, *cannot tell what changed; run the
     whole suite* (ADR-0001 decision 6).
   - It prints and runs nothing. The README gives the PHPUnit and Pest lines
     that run the list.

2. **ADR-0005's rules decide the list, read backwards, and a file the rules do
   not place reaches every test.**
   - A file that decides how the gate runs (ADR-0005 decision 4, rule 1)
     reaches every test.
   - A changed or deleted source file reaches every test that covers any of
     its lines. Where it declares a class or interface constant, an enum case,
     a property default or a parameter default, the tests that reference those
     symbols are added, found by ADR-0004 decision 8's token scan.
   - A new source file reaches the tests that name what it declares.
   - A changed test reaches itself. Changed support reaches the tests that use
     it (ADR-0005 decision 4, rule 4).
   - Any other file, such as a template, a translation, a fixture or a
     migration, reaches every test, unless `proofs.ignore` matches it. That
     key is already the project's statement that no test reads those paths
     (ADR-0007 decision 2.6).

3. **The map is the gate's own, and it records when it was measured.**
   - `affected` reads the map `--coverage=<dir>` names, or else the one this
     checkout last wrote under `.mutation-gate/coverage/`, or the one the
     proof store keeps for the run's scope or the default branch
     (ADR-0023).
   - The map records the commit it was measured at and whether the tree was
     dirty, as `commit` and `dirty`. Every change since that commit joins the
     change being asked about.
   - A dirty map, or none, reaches every test, and the reason says so.
   - This amends ADR-0006 decision 1: the map gains `commit` and `dirty`.
     The map is internal, so no public format moves.

4. **Test ids are offered where PHPUnit takes them.** `--format=ids` prints
   the coverage map's ids for PHPUnit's `--test-id-filter-file`. Under Pest,
   whose ids PHPUnit's filter does not take, it exits 2 and names `files`.
   The first step of the build proves that `--test-id-filter-file` accepts
   the map's ids verbatim, data-set rows included, on the lowest supported
   PHPUnit. If it does not, `ids` is not added.

5. **`Core` computes the list.** An `AffectedTests` value is a pure step over
   `Changes`, the `CoverageMap`, the layout's test directories and the token
   scan, beside `Core\Reach\TestReach`. The `Cli` command reads git through
   `ChangeSource`. No port changes, no config key is added, and no proof is
   written.

### A static analyser can kill a mutant

6. **A tenth port, `StaticChecker`, asks a static analyser about one
   mutant.**
   - `identity(Withheld): AnalyserIdentity|CannotJudge` gives the analyser,
     its version and its config's digest.
   - `findings(Paths, Withheld): Findings|CannotJudge` gives the findings
     of every file the analyser analyses, which must hold these files, from
     one warm-up run. Each check reports on the whole of that scope too, so
     a finding the originals already had is never read as new.
   - `check(MutantCheck): Findings|OutOfScope|CannotJudge` checks one
     mutant. The request holds the original, the mutant, the dependents to
     analyse again unchanged against it (none by default), and what is
     withheld. So a finding always belongs to that mutant or to one of those
     dependents. An original outside the paths the analyser analyses is
     `OutOfScope`. A check that is out of scope or cannot run leaves the
     mutant to its tests. It never kills it.
   - The runner gives each mutant as the analyser reads it:
     `Runner::checkable(Mutant): Checkable|CannotJudge` holds the mutant's
     text and the original it is judged against (decision 9). The runner
     owns its mutants' format, so `Core` needs no parser.
   - Every process an adapter starts, its version command included, runs
     without what the runner withholds (decision 18).
   - `Core` compares findings. The port has a fake in `tests/Fakes` and one
     contract suite, run against the fake and every adapter.
   - Its adapters are `Adapter\Mago`, `Adapter\PhpStan` and
     `Adapter\Psalm`. An extension registers another with
     `Extensions::withStaticChecker(name, build)`, as it registers a runner.
   - This supersedes ADR-0001 decision 2's count: there are ten ports.

7. **Each analyser runs in the mode its authors built for this, and answers
   in JSON.**
   - **Mago:** `mago --config=<config> --colors=never --threads=1 analyze
     --reporting-format=json --substitute <original>=<mutant>`, with both
     paths absolute. The config is passed on every run, so no check reads
     another. Mago analyses the whole workspace in each check, so a mutant
     that breaks a file depending on it is found there. Mago analyses a
     substitute outside its configured `paths` too, so the adapter checks
     the original against `mago list-files` first: a mutant of a file
     outside them is `OutOfScope`, left unchecked, and the run says so
     (decision 11). The gate runs the
     binary Composer's package downloaded, under
     `vendor/carthage-software/mago/composer/bin/<version>/`, rather than
     `vendor/bin/mago`, whose first run downloads it.
   - **PHPStan:** `analyse --tmp-file=<mutant> --instead-of=<original>
     --error-format=json --no-progress`, with a config for the check that
     includes the project's and sets `maximumNumberOfProcesses: 1` and
     `reportUnmatchedIgnoredErrors: false`. The gate keeps that config in
     `.mutation-gate/phpstan/check.neon`. The warm-up saves PHPStan's result
     cache, so each check reanalyses the mutant and the files whose view of
     it changed, and saves nothing. A mutant of a file outside PHPStan's
     paths cannot be judged.
   - **Psalm:** one `psalm --language-server` per worker. Each mutant is sent
     as the original file's changed content (`textDocument/didChange`), the
     findings are read from `publishDiagnostics`, and the original content is
     restored after each check. The first step of the build proves that the
     server answers a change with its diagnostics. If it does not, Psalm
     checks a scratch copy per worker with the file replaced, and then only
     after the tests, since a full run per mutant is never worth running
     before them.

8. **`staticCheck.tool` chooses the analyser, and Mago comes first.**
   - `staticCheck.tool` is `auto`, `mago`, `phpstan`, `psalm` or `none`,
     `auto` by default, or an analyser an extension registers. It is an
     adapter choice, as `runner` is: a name or a class, with its options.
     `auto` and `none` are names the gate resolves before it builds an
     adapter, never adapters: `none` turns checking off.
   - `auto` takes the first of these that is installed and configured, else
     `none`, and `doctor` asks the same question of the same code:
     1. Mago: `vendor/bin/mago`, and a `mago.toml`, `mago.yaml` or
        `mago.json`;
     2. PHPStan: `vendor/bin/phpstan`, and a `phpstan.neon`,
        `phpstan.neon.dist` or `phpstan.dist.neon`;
     3. Psalm: `vendor/bin/psalm`, and a `psalm.xml` or `psalm.xml.dist`.
   - `staticCheck.config` is a path, or by default the first of the config
     files `auto` looks for. The gate hands it to the analyser it builds as
     the option `config`, which a user never sets under `with`.
   - Both keys **affect results**, and are in key item 3 (ADR-0007
     decision 2.3). Checking one mutant pays off only when a check is fast,
     which is why Mago leads.
   - The choice never changes by itself between runs, because the analyser
     is in the key. `doctor` prints each configured analyser's measured time
     per check, and says when another would be faster.

9. **A mutant is rejected by a finding its original does not have.**
   - Each finding is compared by its code and message, ignoring lines:
     PHPStan's `identifier`, Mago's `code` and Psalm's `type`. Any
     error-level finding the original file does not have rejects the mutant.
   - The original's findings come from the warm-up run, once per shard. So
     a project with a baseline, or with known errors, is served. A clean
     analyser run is not required.
   - Infection's and PHPUnit's mutants are the file as written with the
     runner's diff put back onto it: each hunk where its lines stand,
     nearest the mutant's line. They are judged against the warm-up's
     findings.
   - Pest diffs two prints of the file by php-parser's standard printer, so
     its adapter prints the original the same way and puts the diff onto
     the print. The print is checked once per file, and stands for the
     original only where its findings are the warm-up's, each as many
     times. Otherwise every survivor of that file is left unchecked.
   - A diff that does not apply, or a file gone or no longer parsed, leaves
     the mutant unchecked. It is never killed.
   - The texts a check reads go under `.mutation-gate/staticcheck/mutants/`
     and `.mutation-gate/staticcheck/originals/`, one file per check, named
     by the mutant's id and removed after it.

10. **A rejected mutant is *killed by static analysis*, and counts as
    killed.**
    - This amends ADR-0003 decision 1: the status table gains the row
      *killed by static analysis*, in the score as killed. The analyser is a
      check the project runs, so a mutant it rejects could not pass the
      project's CI.
    - The JSON report's judgement is `killed-by-static-analysis`. The HTML
      viewer shows `Killed` with the status in `statusReason`. It is no
      SARIF result and no JUnit failure. This amends ADR-0009 decisions 2
      and 4.
    - Its `killedBy` is unknown (ADR-0013 decision 1), and every kill-matrix
      cell is `not-run` (ADR-0014).
    - The mutant's record keeps its rejection where the gate's own check
      found it: the analyser's name and the finding's code and message, the
      code cut to 128 characters and the message to 1,024, each ending in
      `…` where it was cut. A shard's results and the proof hold it, and so
      does the JSON report's `rejection`. A kill Infection reports carries
      none, since its log names no finding. A record that gives a rejection
      to a mutant of any other status, or a reason or an `outOfTime` beside
      its rejection, is not well formed.
    - A kill by static analysis a time budget carries is unjudged (ADR-0008,
      decision 1), since the record does not say which file the finding sits
      in.
    - `explain` prints the analyser, the finding's code and its message.

11. **Each mutant is checked where it pays: before its tests, after them, or
    both.**
    - Every survivor that is not flaky is checked after its tests, in its
      shard, once the shard's invocations are done. The checks count
      against the time budget (ADR-0008): a survivor the time left has no
      room for, at the checks' mean time so far, stays a survivor.
    - A survivor left unchecked stays a survivor. The verdict warns once for
      each reason, with how many survivors it left and the first three of
      their files, sorted: the analyser could not say its version, its run
      over the originals failed, the file is out of its scope, the runner
      could not give the mutant, the print analyses otherwise than the
      file, the check could not run, or the budget ran out.
    - A check after the tests records its time alone, in the ledger's
      `analysers` section. Rates are learned only from checks before the
      tests, since the survivors are no fair sample of a mutator's mutants.
    - A mutant is also checked **before** its tests when its mutator's
      rejection rate × its judging tests' time is greater than the time of
      one check. The tests' time comes from the coverage map, and the check's
      from the analyser's measured times.
    - A mutator's rejection rate is learned per analyser, in the ledger's
      `analysers` section: by the analyser's name, its checks and their
      `seconds` together, and each mutator's `[checks, rejections]`. The
      section is optional and the ledger's format does not count it: a
      ledger without it, or with an entry that is not well formed, has
      learned nothing of that analyser. Losing it costs speed, never a
      verdict. Where two scopes' ledgers are read together, the run's own
      scope's rates and time come first.
    - `PreCheck`, a policy whose standard is 50 checks, places each check.
      Every mutator is checked before the tests until the ledger holds 50
      checks of it. A mutator with no rejection in them is then checked only
      after the tests. Any other is checked before them where its rate ×
      the judging tests' time is greater than one check's time, or while no
      check has been timed.
    - A pre-check never moves a score: under decision 10 a mutant is killed
      whether the tests or the analyser caught it. So the placement, the
      rates and the timings **judge only**, and ADR-0007's agreement check
      reads *killed by static analysis* and *killed* as one status, as it
      never compares `killedBy`.
    - This amends ADR-0007 decision 3: the ledger gains the rates, and its
      agreement rule reads the two statuses as one.

12. **Pest hands its generated mutants to the gate before the first one
    runs.**
    - At `FinishMutationGeneration` the package's plugin writes each mutant's
      original and mutated paths to
      `.mutation-gate/staticcheck/generated.jsonl`, and waits for
      `.mutation-gate/staticcheck/verdicts.jsonl`.
    - The Pest adapter, handed the `StaticChecker` by the Cli, writes that
      file after running the selected checks in parallel, up to Pest's
      process count. The analysers' editor modes save no shared cache, so
      checks do not race.
    - In a mutant's child, a mutant the file lists as rejected makes the
      plugin record *killed by static analysis* in the results file, and end
      the child with a failure before any test runs. Pest counts it `tested`,
      and the adapter reads the plugin's record.
    - The first step of the build proves that the plugin can end a child
      before Pest selects its tests.
    - This amends ADR-0004 decision 3: the plugin gains the hand-off.

13. **Under Infection, the gate checks survivors itself.**
    - Infection offers no hook before a mutant's tests, so under it every
      check comes after them, and `plan` says so once.
    - With `staticCheck.tool` other than `none`, the config the adapter
      writes drops `staticAnalysisTool` and `staticAnalysisToolOptions`, and
      the gate checks survivors by decision 9, from each mutant's diff put
      back onto its file. With `none`, the project's own keys are kept.
    - One rule and one status then hold under every runner, Psalm included.
    - `init --from` maps `staticAnalysisTool: phpstan` or `mago` to
      `staticCheck.tool`.
    - This amends ADR-0004 decision 4 and ADR-0016 decision 1.

14. **The analyser is keyed as the runner is.**
    - The checker's `identity()` joins key item 4 beside the runner's,
      whenever `staticCheck.tool` is not `none`. For Mago the version comes
      from `mago --version`, since its Composer package fetches the binary
      that analyses.
    - The analyser's config and baseline files are in item 6. Its bootstrap
      files under a test directory are in item 7.
    - This amends ADR-0007 decisions 2.4 and 2.7.

15. **PHPStan's and Psalm's caches are kept per scope, as the ledger is.**
    - The analyser's cache directory is set under
      `.mutation-gate/staticcheck/cache/`, and the proof store keeps it beside
      the ledger in the run's scope.
    - A run reads its own scope's cache and the default branch's, and writes
      only its own, so a pull request cannot plant a cache the default branch
      uses.
    - This amends ADR-0007 decision 4. The companion object per scope is the
      one ADR-0023 adds for the coverage map.

16. **The equivalence check stays outside the port.** ADR-0013 decision 10's
    opcache check answers whether a mutant is the same program. An analyser
    answers whether it is a valid one. None of the three proves equivalence.
    So the opcache check stays a Cli-owned adapter, as ADR-0013 decided.

17. **Pre-kills are counted in what a run saved.** ADR-0017's headline line
    gains *static analysis saved N*: the recorded time of the tests that did
    not run, less the checks' own time. This amends ADR-0017 decision 11.

18. **An analyser runs as the runner runs.** It loads the project's config,
    and for PHPStan and Psalm its extensions and bootstrap files, in the job
    that already runs the tests. It runs without the variables the runner
    withholds (ADR-0004 decision 3). The gate never fetches Mago's binary
    itself: a check whose analyser is not ready is left unchecked, and
    `doctor` names the install step.

### Survivors first

19. **On a pull request, the last run's survivors run again first.**
    - The survivors are every mutant not killed in the newest proof of each
      reached unit in the pull request's own scope, survivors on changed
      lines first. There are at most `survivorsFirst.max` of them, an
      integer, `20` by default, the PR comment's own cap. `0` turns it off.
      The key **judges or reports only**.
    - They run through the Runner port's `retry`, matched by the gate's id,
      batched per file and mutator. The id holds no line number
      (ADR-0004 decision 2), so most survivors are found again after an edit
      elsewhere in the file. One whose id is gone is reported as *gone*.
    - A pull request's first push has no earlier run, and says so in one
      line. A push to the default branch does not re-check survivors.

20. **The early result is a signal, and the full run always follows.**
    - The sticky comment moves from *planned* to *survivors re-checked: 3 of
      4 still survive*, with each one's hint and reproduce command, and then
      to the verdict. This amends ADR-0009 decision 3.
    - The early results are never a proof, since a unit's result needs every
      mutant (ADR-0007 decision 1). They enter neither the ledger, nor a
      score, nor the verdict.

21. **Survivors are re-checked in a job of their own.**
    - The reusable workflow gains a `survivors` job beside the shard matrix,
      after `plan`, with `contents: read` and `pull-requests: write` and
      nothing more. It never saves a ledger. This
      amends ADR-0011 decision 8.
    - The one-step action and a one-process run re-check survivors as their
      first step.
    - The `survivors` job writes the comment only while it is still in its
      *planned* state, found by its marker, so the verdict always wins.
    - On a fork's pull request it writes the step summary instead
      (ADR-0009 decision 3).

### Sampled runs

22. **A sampled run judges a sample of units and gives each tree an
    estimate.**
    - It is asked for by name: `--sample` on `plan` and on a one-process
      run, and `mode: sampled` for the action and the reusable workflow. It
      is never chosen automatically, and the documented scheduled full run
      stays full (ADR-0005 decision 6). This amends ADR-0011 decision 8.
    - The sampling unit is a unit, a file or a held path (ADR-0005
      decision 1), the one thing both runners accept.

23. **Known results are exact, and only the rest is sampled.**
    - Units whose proof key matches, and in a change-scoped run the reached
      and carried units, form an exact stratum.
    - The rest is sampled. A tree's estimate weights the exact stratum by its
      mutant count, and the sampled stratum by its estimated count: each
      unit's newest proof's count, or its lines times the runner's mutants
      per line (ADR-0017 decision 4) where there is none.
    - The interval is the sampled stratum's, scaled by that stratum's share
      of the tree's mutants, so it narrows as the ledger fills.

24. **The estimate is a ratio, and its interval is Wilson's on the effective
    sample size.**
    - The estimator is killed over counted, summed over the sampled units.
    - The interval is Wilson's score interval on Kish's effective sample
      size. That is the sampled mutants divided by the design effect, which
      is the ratio's linearised variance (Cochran) over the binomial variance
      of as many independent mutants. A finite-population correction applies
      for the sampled share.
    - `sampling.confidence` is `90`, `95` or `99`, `95` by default, a closed
      set, so the normal quantile is a table of three constants.
    - The lower bound is truncated to hundredths, and the upper bound
      rounded up, so ADR-0003's two decimals never flatter.

25. **A floor is judged on the interval.**
    - A tree passes when its lower bound is at least its floor, and fails
      when its upper bound is below it.
    - Otherwise it is *undecided*, which is *cannot judge* (exit 2). The
      message names the tree, its interval, and the two ways to decide it: a
      smaller `sampling.margin`, or a full run.

26. **New code, and every tree at a floor of 100, are never sampled.** No
    sample can show that a floor of 100 holds, since one unsampled unit may
    hold a survivor, and changed code is the author's own. Both run in full.
    A repository at 100 everywhere gets nothing from sampling, and the plan
    says so.

27. **The sample is sized per tree for a margin.**
    - `sampling.margin` is the target half-width in points, above 0 and at
      most 10, `2.0` by default, at `sampling.confidence`.
    - The size comes from the between-unit variance of the tree's last
      results. It is at least `sampling.minUnits` units, an integer, `30` by
      default, or every unit.

28. **The sample is drawn by digest, from a seed the author does not
    choose.**
    - The sample is the first units of each tree ranked by `SHA-256(seed ‖
      path)`. That is a digest, not a random number, and a plan made once
      records it. This amends ADR-0006 decision 1.
    - The seed is the change's base commit in a change-scoped run, and the
      plan's commit otherwise. `sampling.seed` overrides it.
    - So the same commit samples the same units, and a pull request's author
      cannot amend commits until the sample skips a unit, because the base is
      the default branch's.

29. **A sampled run records its true results and no estimate.**
    - The sampled units' proofs are written, since each ran to the end.
    - `passed` is never written, so a sampled pass is never `last-passed`,
      and never trusted as a passing run.
    - Floors are never raised. The badge, trend and chat alerts are not
      updated, as with a run cut short by its budget (ADR-0009 decision 5).
    - This amends ADR-0003 decision 4 with a second exception to "a tree is
      judged whole", next to the budget's: a sampled run estimates, and never
      raises a floor.

30. **The estimate is shown as one.**
    - Console: `app/Http  floor 80.00  score ≈ 84.12 (95%: 81.37–86.55; 212
      of 1,904 units sampled, 311 exact)`.
    - JSON: each tree gains `estimate: {score, lower, upper, confidence,
      units: {sampled, exact, total}}`, and the top level gains `sample:
      {seed, confidence, margin}`. This amends ADR-0009 decision 2.
    - JUnit: an undecided tree's case fails with *undecided*, and the run
      exits 2.
    - SARIF and the annotations carry the sampled survivors as usual. The PR
      comment says *sampled* in its heading.
    - `sampling.confidence`, `sampling.margin`, `sampling.minUnits` and
      `sampling.seed` all **judge or report only**: sampling decides which
      units run, as reach does, never what a unit's result is.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **ADR-0005's rules exactly, for `affected`**, where anything else reaches nothing | Mutation reach may miss a template because the scheduled full run catches it. A plain test run has no such net, so a template change would run no screen test. |
| **Tests covering the changed lines only** | A new line is in no coverage map, so a new branch would run no test. |
| **Running the suite under coverage before `affected` answers** | Costs the whole suite the command exists to avoid. |
| **The coverage map inside the ledger** | Per-test line maps are megabytes, rewritten into every scope's ledger on every run. |
| **A `filter` format**, one regular expression | Hits the argument-length limit ADR-0004 found for Pest's own filter. |
| **`affected --run`**, through a new Runner port method | A port change for plain test runs, which nothing else needs, and two ways for a run to fail that are not mutation's. |
| **A rejected mutant left out of the score as invalid**, as Stryker's viewer treats `CompileError` | The score would then depend on placement: a pre-checked mutant the tests would also have killed leaves the denominator. So a cost-driven placement would move scores. |
| **Checking survivors only** | Saves no time, and the need is speed where tests are slow. |
| **Checking every mutant before its tests** | Slower than the tests for a fast suite, and Infection has no hook before a mutant's tests without a vendor patch. |
| **Any non-zero exit, with a clean analyser run required first**, as Infection does | Refuses every project whose analyser is not clean at its configured level. |
| **Only type-related finding codes** | Each analyser's list is its own and moves with its releases, and an undefined method is a real rejection too. |
| **The plugin running the analyser itself** | The plugin would grow process and output handling of its own, outside any port. |
| **Infection's own static analysis, under Infection** | A different rule from the other runners', so one project would score differently per runner, and Psalm would be missing. |
| **A Cli-owned PHPStan adapter with no port**, as ADR-0013's opcache check is | The Pest adapter could not reach it, so Pest users, who have the slow framework suites, would get no pre-check. |
| **A batch check of mutants in several files at once**, through Mago's repeatable `--substitute` | A mutant that changes a signature makes findings appear in another mutant's file, and the kill would go to the wrong mutant. |
| **The fastest analyser, measured each run** | The analyser, which is in the key, would flip between runs as timings move. |
| **PHPStan first in `auto`** | The slower analyser in every measurement taken, where checking one mutant pays only when a check is fast. |
| **Psalm through a scratch copy only** | A full analysis per mutant, which can never be worth running before the tests. |
| **The analyser's version through `installed.json` alone** | Misses a Mago binary on the path, or one its Composer package fetched at another version. |
| **Discarding the analysers' caches between runs** | PHPStan's warm-up costs about a minute cold on a mid-sized project, in every shard. |
| **`StaticChecker` also proving equivalence** | Each adapter would have to answer a question its tool cannot, and ADR-0013 already rejected a port for equivalence provers. |
| **Stopping early when re-checked survivors make failure certain** | Hides every new survivor until the next push. |
| **The survivors in the plan job, or first in every shard** | Every shard would wait for them, or the signal would be scattered over N jobs with N writers of one comment. |
| **Merging early results into the units' results** | The runners cannot leave those mutants out of the full run, so there is nothing to save. |
| **Re-checking survivors on default-branch pushes** | A push to the default branch reaches little (ADR-0005 decision 5), and nobody waits on it. |
| **Sampling (file, mutator) pairs, or single mutants** | Each pair or mutant pays a runner invocation and its opening run. |
| **A Wald interval** | Near 100 it collapses to a point or runs past 100. |
| **Clopper–Pearson** | Conservative, and needs an inverse incomplete beta function in `Core`. |
| **A bootstrap over units** | Thousands of resamples per tree, and hard to explain in one line. |
| **Sampling every unit, ignoring proofs** | Wastes what is already proved. |
| **An undecided tree failing** | Fails trees that may well be above their floors. |
| **Sampling until decided, in rounds** | Needs re-planning, which a plan made once cannot do (ADR-0006 decision 1). |
| **Sampling new code and trees at 100** | They could only fail or be undecided, never pass. |
| **A fixed fraction, or a fixed number of mutants** | A fraction over-samples uniform trees and under-samples varied ones. A mutant count ignores that units are clusters. |
| **The plan's commit as the seed on a pull request** | An author could amend until the sample skips a unit. |
| **A fixed seed** | The same units forever, and the rest never judged. |
| **Recording `passed` after a sampled pass** | An estimate would become the base the next push reaches from. |
| **Sampling chosen automatically** above a cost | A verdict would silently change kind. |

## Consequences

**A change's tests can run without the rest of the suite**, from a map the
gate already keeps. Anything the gate cannot place runs every test.

**The static analyser a project already runs kills mutants its tests would
spend seconds on.** Framework feature tests gain the most. Library unit tests
are checked only after they have run, where the check still settles
survivors honestly.

**One more port, and one more thing in the key.** Upgrading the analyser
re-runs every unit once.

**A push learns within minutes whether it fixed the last push's survivors**,
and the verdict still comes from the full run.

**A repository too large to mutate weekly gets a verdict it can trust in
both directions**, or an honest *undecided*, and never an estimate recorded
as a fact.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-nine-ports.md): the port count, superseded to ten
- [ADR-0003](0003-a-floor-only-rises.md): *killed by static analysis* in the status table, and sampling as a second exception to judging a tree whole
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the plugin's hand-off, `retry`, and Infection's static-analysis keys
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): the rules `affected` reads backwards
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the coverage map's `commit` and `dirty`, and the sample a plan records
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the analyser in the key, the rejection rates in the ledger, and the analyser's cache per scope
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the new status, the comment's re-checked state and the estimate's fields
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the `survivors` job and `mode: sampled`
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the opcache check that stays outside the port
- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): `init --from`'s mapping of `staticAnalysisTool`
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): the savings line
- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): the coverage map the store keeps per scope, and the native runners that need no hand-off
