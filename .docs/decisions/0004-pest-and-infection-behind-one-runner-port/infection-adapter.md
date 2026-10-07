# ADR-0004, decision 4: the Infection adapter

The Infection adapter (`infection/infection` ~0.35.0, with PHPUnit 12 or 13),
as [ADR-0004](../0004-pest-and-infection-behind-one-runner-port.md) decides
it in its fourth decision.

- **Invocation:**

  ```text
  vendor/bin/infection --configuration=<generated> --threads=<n>
      --no-progress --no-interaction --with-uncovered --logger-github=false
      --coverage=<dir> --skip-initial-tests
      [--test-framework-extra-args=<the project's, and a group or filter>]
      [--only-covering-test-cases] <files>
  ```

  - `--log-verbosity` is left at its default, and never `none`.
  - Infection never runs an opening suite of its own. It always reads a
    coverage directory the adapter wrote in its own job. For a run judged
    by the whole suite that reuses the planning job's coverage, the adapter
    writes the gate's map that job handed on (ADR-0006) into the layout
    below. That covers each file's lines and the tests that ran them, the
    methods its report says some test ran, with their lines, and a JUnit
    log with a suite for each test class, holding the file its tokens say
    declares it and its tests' times. Otherwise the adapter writes the
    directory by running PHPUnit under coverage (the **Coverage** item
    below). No report another job wrote is read. The adapter reads each
    mutant's limit from that directory's JUnit log, which is what
    Infection sums, and Infection deletes its own opening run's log when it
    finishes.
  - Infection and PHPUnit run on the PHP that runs the gate, with that PHP's
    directory first on the `PATH`, because Infection starts PHPUnit for each
    mutant through the script's `#!` line. They inherit no variable of
    another run (`INFECTION_*`, `MUTATION_GATE_*`, `PEST_MUTATION_*`,
    `PARATEST`, `TEST_TOKEN`, `UNIQUE_TEST_TOKEN`) and no credential
    (`AWS_*`, `GITHUB_TOKEN`, `SONAR_TOKEN`, `ACTIONS_*`, and the cloud
    stores' of ADR-0028), because the
    project's tests and every mutant of its code run in them.
  - A project withholds its own credentials from either runner with
    `runner.withhold`, a list of variable names or globs whose `*` stands
    for any run of characters, such as `DEPLOY_*` or `COMPOSER_AUTH`. It
    only adds to what every run withholds, and it only grows: each layer of
    config adds its own to the earlier layers', whichever runner a later
    layer chooses, and a layer may write `withhold` alone for zero-config to
    find the runner. It is not part of a proof's key (ADR-0007).
  - A run stopped at its deadline is *cannot judge*: Infection writes its
    logs only when it finishes, so no mutant of such a run has a result.
    A stopped run is stopped with every process it started.
- **Configuration.** The adapter writes a config per invocation. It reads
  the project's own config where there is one, the first of
  `infection.json5`, `infection.json`, `infection.json5.dist` and
  `infection.json.dist`, as Infection does.
  - It keeps only these keys of it: `mutators`, `bootstrap`, `phpUnit`,
    `initialTestsPhpOptions`, `testFramework`, `staticAnalysisTool`,
    `staticAnalysisToolOptions`, `phpStan` and `mago`. The project's
    `testFrameworkExtraArgs` go on the command line, before the gate's own
    narrowing. Every other key either belongs to the gate or could change
    which mutants Infection makes or how it stops (`source`, `minMsi`,
    `minCoveredMsi`, `maxTimeouts`, `timeoutsAsEscaped`,
    `ignoreMsiWithNoMutations`, `threads`, `logs` and any key a later
    release adds), so the gate writes it itself or leaves it out.
  - Every path is absolute, and `phpUnit.configDir` is set, because the
    generated file lives under `.mutation-gate/`.
  - Where the config turns on registered mutators, `bootstrap` names the
    file of their bridges, which loads the project's own bootstrap, and
    `mutators` turns each bridge on (ADR-0021, decision 4).
  - `source.directories` are the directories of the files the run mutates.
  - `logs.json` and `logs.text` point into `.mutation-gate/infection/logs/`,
    and every other log is off.
  - `timeout` is `timeouts.most` (ADR-0008). Every run is started with
    `MUTATION_GATE_MUTANT_FLOOR` set to `timeouts.seconds`, which the lines
    `infection:patch` writes read, and `MUTATION_GATE_RESULTS` naming the
    file they record silence stops in.
- **`infection:patch` gives Infection the gate's mutant limit.** It is
  `@php vendor/bin/mutation-gate infection:patch` in `post-install-cmd`
  and `post-update-cmd`. It rewrites four places in Infection and one in
  its include-interceptor, each marked with a
  `// mutation-gate infection:patch:` comment:
  - `MutantProcessContainerFactory` allows each mutant the gate's standard
    limit (ADR-0008, decision 2) of Infection's own time for its covering
    tests, between `MUTATION_GATE_MUTANT_FLOOR` and Infection's `timeout`,
    in place of Infection's 5 s plus five times that time under `timeout`.
  - `MutationTestingRunner` skips no mutant for the time its tests take.
  - `MutantProcessContainerFactory` also watches each mutant's run for its
    silence limit (ADR-0008, decision 2): the same rule, of its slowest
    covering test class's time, as Infection times each test by its whole
    class, between the same bounds; none where a covering class is
    untimed. `ParallelProcessRunner`, as it checks each run's timeout,
    stops a run that has printed nothing on its standard output for its
    silence limit since it last printed. PHPUnit prints its header once
    it has loaded every test, and a mark as each test ends, so its start
    is held only to the mutant's own limit. Each stop is recorded, by the
    mutant's file, line, mutator and diff as Infection's log names them,
    in `logs/silenced.jsonl`, which `MUTATION_GATE_RESULTS` names, and the
    mutant is timed out at its own limit, with a reason naming the
    silence limit.
  - `IncludeInterceptor`, the `file://` wrapper infection/include-interceptor
    serves each mutant through, stats a path as PHP does without it while
    it is enabled. As it ships, in every release Infection ~0.35.0 allows
    (0.2.5 and 1.0.0), it reports a path the process cannot read as
    missing, so a dangling link is no link and a file without read
    permission is not there, and a test that stats either fails in every
    mutant's run whatever the mutant changes. Patched, a link, dangling or
    not, is stat'd by `lstat` where the caller asks for the link, and any
    other path that exists by `stat`. It raises no warning of its own: PHP
    warns of a failed stat where the caller did not ask for quiet
    (`STREAM_URL_STAT_QUIET`), as it does without the wrapper. A release
    whose lines have moved fails the command, and every patch is checked
    before any file is written.

  The first three read the gate's floor, so Infection run outside the gate keeps its
  own limit and skip, and stops no run for silence; the interceptor stats
  as PHP does either way. The command patches only the Infection releases the
  runner contracts run, listed in `Adapter\Infection\Release`,
  reading the release from Composer's list of what it installed. It
  refuses any other release, a file whose lines have moved, and a file
  another version of the gate patched, and says which, as `pest:patch`
  does. Each run checks whether the installed Infection and its
  include-interceptor carry the patch. Where it does not, each mutant keeps Infection's own limit, the
  gate triages by that limit, and the run's report warns of it, naming
  `infection:patch`; `doctor` advises it too (`infection-unpatched`).
  - `tmpDir` is under `.mutation-gate/`.
  - A run is judged by its logs and their counts, never by Infection's exit
    code. The logs, the generated config and the coverage reports an
    earlier run left are removed before a run, and a file that cannot be
    removed is *cannot judge*.
- **Held paths** (ADR-0005) always take coverage from their own opening run,
  never from a whole-suite map. The adapter runs PHPUnit under coverage
  narrowed to the holding tests, and Infection reads that run's directory.
  The narrowing also goes through `--test-framework-extra-args`.
  - A group, `--group=holds:<path>`, narrows the opening run and every
    mutant run.
  - `#[Holds]` becomes a `--filter` naming the holding tests. It narrows the
    opening run, and each mutant run then executes only tests that run
    recorded. The adapter adds `--only-covering-test-cases`, so a mutant
    runs the holding test methods rather than their whole classes.
  - A contract test proves both.
- **Results.** The adapter reads `logs.json`, and the skipped mutants from
  `logs.text`. The logs must add up, or the run is *cannot judge*:

  | `stats` count | Where the mutants are | Gate status |
  |---------------|-----------------------|-------------|
  | `killedCount` | `killed` | killed |
  | `killedByStaticAnalysisCount` | `killedByStaticAnalysis` | killed by static analysis |
  | `escapedCount` | `escaped` | survived |
  | `errorCount` | `errored` | errored |
  | `syntaxErrorCount` | `syntaxErrors` | errored |
  | `timeOutCount` | `timeouted` | timed out |
  | `notCoveredCount` | `uncovered` | uncovered |
  | `skippedCount` | `Skipped mutants:` in `logs.text` | skipped |
  | `ignoredCount` | `ignored` | ignored by a native marker when `ignores.native: allow`; otherwise *cannot judge*, because the run refuses native markers before it starts (ADR-0008) |

  Each count must equal the number of its mutants, and `totalMutantsCount`
  must equal the sum of the counts.

  A mutant under `killed` carries the tests that killed it (ADR-0014): the
  ones PHPUnit's output, the entry's `processOutput`, lists under `There was
  1 failure:` or `There were 2 errors:`. Each is named as the coverage map
  names it, `<class>::<method>` with `#<data set>` for a data set's test.
  Infection's mutant runs stop at the first defect, so that is the first
  killer. A mutant killed by static analysis, an error or a timeout, or
  with no such list, carries none, and one killed by static analysis
  carries no rejection either, since the log names no finding.
- **Coverage** comes from `vendor/bin/phpunit --coverage-xml=<dir>/coverage-xml
  --log-junit=<dir>/junit.xml`, or `phpUnit.customPath`, with
  `initialTestsPhpOptions` as PHP options, then the pcov settings Pest's
  coverage run takes, which win over them, the project's
  `testFrameworkExtraArgs` and, for a held path, its group or filter. That
  is the layout `--coverage` expects, and the gate reads its per-line
  `covered by` entries as its own map, so one run serves both. The map
  also keeps the methods Infection finds a signature mutant's tests by:
  each method of the report's classes or, where they have none, of its
  traits, with the start and end lines the report gives it, less each
  whose coverage Infection reads as none (under one percent). Groups come
  from `vendor/bin/phpunit --list-groups`.
- **Limits.** A timed-out or skipped mutant's limit is `min(5 s + 5 × T, timeout)`, where `T`
  is the sum of the JUnit times of the test classes covering its first
  line, each counted once, as Infection computes it.
- **Native markers** (ADR-0008) are `@infection-ignore-all` in a comment of
  the files asked for, and each value of `ignore` or
  `ignoreSourceCodeByRegex` under `mutators` in the project's config: under
  a mutator, a profile, `global-ignore` or
  `global-ignoreSourceCodeByRegex`.
- **Scope.** The adapter refuses a `testFramework` other than `phpunit`, and
  a `phpUnit.customPath` that points at Pest. A Pest project uses the Pest
  adapter.
- **Narrowing to one mutator.** A retry (ADR-0008) or a reproduce runs the
  mutant's file with a generated config whose `mutators` block keeps only
  that mutator, with the project's settings for it. `--mutators` is never
  used, because it drops those settings and the mutant could change. The
  mutant is matched back by the gate's id.
