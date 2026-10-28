# ADR-0004: the Pest adapter

The Pest adapter (`pestphp/pest` ^5.1, `pestphp/pest-plugin-mutate` ^5.0,
and the PHPUnit 13 release Pest pins), as
[ADR-0004](../0004-pest-and-infection-behind-one-runner-port.md) decides it
in its third decision.

- **Invocation:**

  ```text
  <vendor>/pestphp/pest/bin/pest --mutate --no-cache --parallel --no-tia --everything
      --covered-only=false --stop-on-untested=false
      --stop-on-uncovered=false --retry=false --path=<files>
      --ignore=<held paths, or .mutation-gate>
      [--group <holds group> --do-not-fail-on-empty-test-suite]
      [--mutator=<class names>]
      --cache-directory=<project>/.mutation-gate/phpunit-cache
  ```

  - Every Pest command the adapter runs, a mutant's, its coverage map's,
    a listing's, a run of no test's and a judging run's, keeps PHPUnit's
    caches, Pest's test run history among them, in
    `.mutation-gate/phpunit-cache`, whatever the project's config names,
    so no run writes a file a content key reads (ADR-0007, ADR-0023). A
    replay of a kill's run passes the arguments Pest started it with,
    which hold it already.

  - A group or a filter is passed as its option and its value apart, as
    Pest passes its own on: PHPUnit's reading of `--name=value` ends the
    value at its next `=`, which a data set named `a === b` holds.
  - Every run narrowed to a group also passes
    `--do-not-fail-on-empty-test-suite`. Under `--parallel`, Pest can sum a
    run that ran every test of the group as one with no tests, and fail it.
    A narrowed run with none of the group's tests passes too, so it kills
    no mutant.
  - `--path` makes the gate, not a test's `covers()` or `mutates()`, decide
    what is mutated and which tests judge it. A contract test proves that a
    suite using `covers()` is still judged by every covering test.
  - `--no-cache` keeps a stale cache from deciding a result.
  - `--mutator` names the mutators a narrowed run applies. Where the config
    turns on registered mutators, a run of every mutator names Pest's
    `DefaultSet` beside their bridges, since `--mutator` replaces Pest's
    list (ADR-0021).
  - `--min` is never passed, and the options after `--no-tia` undo what a
    project's own mutation config could set: covered lines only, a class
    list, an ignore list, a stop at the first escaped or uncovered
    mutant, and escaped mutants first. Uncovered mutants are always
    reported, and `uncovered: exclude` is applied by the gate (ADR-0003).
    A run that reaches its end is judged by its records whatever its exit
    code, since a project's own minimum score fails a finished run.
  - `--processes` is never passed. pest-plugin-mutate hands it on to each
    mutant's own run, which is not parallel and rejects it, so every
    covered mutant would read as killed. Pest runs one mutant per core,
    and a request's process count does not apply to it. The gate counts
    the cores as pest-plugin-mutate does, with `fidry/cpu-core-counter`,
    once per run, on the same machine, so the two counts agree.
  - A runner's behaviour says whether it runs a mutant per core or one
    at a time; one an extension adds runs one at a time unless it says
    otherwise. Pest and Infection run one per core, and every request the
    gate makes of them asks for every core: `<n>` in Infection's
    `--threads=<n>` is that count.
  - `<vendor>` is where Composer installed the project's packages:
    `COMPOSER_VENDOR_DIR`, then `config.vendor-dir`, then `vendor`. The
    gate reads Pest's versions from `<vendor>/composer/installed.json` and
    checks `pest:patch` there too. Pest's own script loads
    `vendor/autoload.php` from the directory that holds its vendor
    directory, so Pest runs only where that directory is named `vendor`,
    such as `lib/vendor`.
  - Pest runs on the PHP the gate runs on, which is first on its path,
    because Pest starts each mutant's run through the same script. It
    inherits the environment the gate's PHP started with, where a variable
    set later by `putenv()` alone does not reach it. It inherits none of
    the variables that make a process a paratest worker or a mutant's run,
    none of the gate's own, none of those by which a tool prints for an AI
    agent (each that `laravel/agent-detector` reads, such as `AI_AGENT`
    and `CLAUDECODE`, and `laravel/pao`'s `PAO_FORCE`), so a run started
    from an agent's shell prints as the gate parses it, and none of the
    variables a request withholds.
    Every request withholds the CI's credentials (`AWS_*`, `ACTIONS_*`,
    `GITHUB_TOKEN`, `SONAR_TOKEN`, and the cloud stores' of ADR-0028:
    `GOOGLE_APPLICATION_CREDENTIALS`, `GOOGLE_GHA_CREDS_PATH`,
    `CLOUDSDK_AUTH_CREDENTIAL_FILE_OVERRIDE`, `AZURE_*`,
    `MUTATION_GATE_GCS_TOKEN` and `MUTATION_GATE_AZURE_TOKEN`), Composer's
    `COMPOSER_AUTH`, OpenTelemetry's per-signal headers
    (`OTEL_EXPORTER_OTLP_TRACES_HEADERS`,
    `OTEL_EXPORTER_OTLP_METRICS_HEADERS` and
    `OTEL_EXPORTER_OTLP_LOGS_HEADERS`), and the secrets the gate itself reads
    (`OTEL_EXPORTER_OTLP_HEADERS`, `GH_TOKEN`, and the alert channels'
    `MUTATION_GATE_SLACK_URL`, `MUTATION_GATE_DISCORD_URL`,
    `MUTATION_GATE_WEBHOOK_URL` and `MUTATION_GATE_WEBHOOK_SECRET`, or the
    variables an alert's `urlEnv` and `secretEnv` name instead), each CI
    plan adds its own CI's tokens (such as GitLab's `CI_JOB_TOKEN` and
    Buildkite's `BUILDKITE_AGENT_ACCESS_TOKEN`), and `runner.withhold`, a
    list of names or globs, adds a project's own. The list only ever grows.
    Withholding keeps a credential out of the tests' environment, not out
    of their reach. It stops a mutant using one by accident, never code
    written to find it: a test can read its parent process's environment,
    a credentials file a withheld variable named stays on disk, and the
    project's autoload files and PHP config run in the gate's own process.
    A credential the project's code must not use goes only to a job that
    runs none of it (ADR-0007).
  - Pest runs with the project root as its working directory, and one
    `--mutate` invocation at a time runs in a checkout, because each writes
    its opening map to the same path.
- **Where Pest's filter cannot hold the covering tests.** The adapter builds
  Pest's filter from the gate's coverage map, as Pest does. A mutant with a
  covering test whose id that filter cannot express is recorded as
  unjudged, with the test named as the reason, never as killed or uncovered:
  Pest would have run no test for it and called the mutant killed, or
  dropped the test and called it uncovered. The filter names a test by
  the class after a namespace separator, so a PHPUnit class outside any
  namespace is one it cannot express: a PHPUnit class run by Pest belongs
  in a namespace.
- **Where a mutant's file was loaded before the mutant.** Pest puts a
  mutant in the place of its file through an override that
  pest-plugin-mutate starts as it boots, so a file PHP loaded before that,
  such as one Composer's `files` autoload or `tests/Pest.php` loads, runs
  as it is. The package's plugin reads in each mutant's own process what
  PHP had loaded when it boots, before it loads anything itself, and
  records a mutant whose file was among it. Such a mutant is unjudged,
  whatever Pest made of it, with the reason *`<file>` was loaded before the
  mutant was in place, so its tests ran the original code*. That holds on
  every run, so an ignore that names the mutant by its id can leave it out
  (ADR-0008). Patched, a class file Composer autoloads is served by an
  autoloader instead of the override (see the patches below), so a test
  that loads that file by its path, as a `require` does, runs it as it is
  too. The plugin reads what PHP loaded as Pest terminates it and records
  such a mutant as well, with the same reason.
- **Where a survivor's own run ran no test.** The package's plugin counts
  the tests that finish in each mutant's own process and writes the count
  once PHPUnit ends the run, which it does whether or not its filter
  selected a test. A survivor whose own process says it ran none is
  unjudged, with the reason *its own run ran no test*: nothing ran against
  the mutant, so its survival says nothing. A process that never ends its
  run, as one stopped at its limit does, writes no count, and its mutant
  is read as Pest reports it. Mutants that leave the same source share an
  own run's record, so their counts add up. The PHPUnit runner reads the
  same from its own records (ADR-0023). Infection's log says
  nothing of how many tests ran in a mutant's process, so an Infection
  survivor is read as Infection reports it.
- **A small Pest plugin** ships in this package, listed in its
  `composer.json` under `extra.pest.plugins`, which is how Pest finds
  plugins. The class listed is the CLI's, which hands the Pest adapter's
  plugin the PHPUnit runner's reader of the tests whose issues failed a
  mutant's run (ADR-0014), as only the CLI wires one adapter to another.
  Both implement Pest's `Bootable` and `HandlesArguments` contracts; the
  adapter's plugin registers a `TestCaseMethodFilter` as it boots, and it
  does six jobs. It turns `#[Holds]`
  into groups and reports results, both described below. To judge by
  reference, it keeps the mutated file of each mutant Pest leaves uncovered on a line
  that is not executable, and it guards each judging run. In a mutant's
  child process it records the first test that fails, and it orders the
  child's tests (ADR-0013). For a full kill matrix it
  drops `--bail` there and records every test that fails (ADR-0014).
- **It turns `#[Holds]` into groups** (ADR-0005).
  - In `boot()` it registers itself as a filter on Pest's test repository.
    Pest boots plugins after it loads `tests/Pest.php` and before it loads
    any other test file, in the main process, in every `--parallel` worker
    and in every mutant's child process.
  - Pest passes each test to the filter as it registers it, before it
    builds the test's class. That is the point where Pest turns its own
    `->group()` calls into PHPUnit `#[Group]` attributes.
  - The filter reads `#[Holds]` by reflection from the test's closure, and
    from the closure of every `describe` it is registered inside, which it
    finds on the call stack. For each path it adds
    `#[Group('holds:<path>')]` to the test, once. It never drops a test.
  - It does this in every Pest run, the gate's or not. A mutant's child
    process that lost the group would select no test, and Pest counts that
    as a kill.
  - Datasets inherit the group, because Pest puts it on the method that
    carries the data provider.
- **It reports results.** This part is inert unless the environment variable
  `MUTATION_GATE_RESULTS` names a file, which only the adapter sets. A
  mutant's child process inherits it, and there the plugin appends whether
  the child had loaded the mutant's file before the mutant was in place,
  the id of the first test that fails (ADR-0013), or of every
  test that fails under a full kill matrix (ADR-0014), and how
  many tests the process ran, to a file of that mutant's own beside the
  results file. Once the child ends, the plugin in Pest's own process
  writes each as a line of the results file with the mutated file Pest
  serves, and removes that file. So the results file has one writer,
  which runs no mutated code: in a child, the plugin's own classes can be
  the mutant, as they are in the package's own gate (ADR-0011).
  - At `FinishMutationSuite` it walks the suite's mutants and writes one JSON
    line per mutant: native id, file, lines, mutator class (a bridged
    mutator's own name, ADR-0021), diff, status and
    duration, and one line with the opening run's duration, from which the
    adapter computes each mutant's limit. That walk is the only place that
    sees a mutant with no result.
  - The adapter fails closed. The records must add up to the counts on Pest's
    own summary line (`Mutations: … untested, … uncovered, … pending, …
    timeout, … tested`), and a missing file or a mismatch is *cannot judge*.
    So is a whole record that names an event, or a status, the plugin does
    not write, lacks a field its event carries, or places a mutant on a line
    no file has. The last line, which a run stopped while it wrote leaves
    cut short, is skipped when it is not JSON; any other line that is not
    JSON refuses the file, since a line lost there could be the test that
    killed a mutant first. The plugin writes a status it does not know as
    Pest names it, for the adapter to refuse, and fails rather than write
    a record JSON cannot hold. The plugin and the adapter write and read the lines through
    one protocol: its events, its fields and Pest's statuses are each
    spelled once.
  - The plugin relies on Pest APIs that are `@internal`: the plugin
    contracts, the test repository, the test's closure and attributes, and
    the `describe` call's closure. It also relies on the mutate plugin's
    events, which are undocumented. So only the pest-plugin-mutate versions the contract suite
    covers are allowed, and `composer.json` declares a `conflict` for the
    rest.
- **Statuses**:

  | Pest status | Gate status |
  |-------------|-------------|
  | `tested` | killed. Pest cannot tell a crash from a failed assertion, so errored never comes from Pest. |
  | `untested` | survived |
  | `uncovered` | uncovered, or judged by reference on a line that is not executable |
  | `timeout` | timed out |
  | `none` left at the end (Pest's *pending*) | unjudged |

- **Coverage** is an invocation of its own, never part of a mutation run:
  - `<vendor>/pestphp/pest/bin/pest --parallel --coverage-php=<dir>/coverage.php
    --log-junit=<dir>/junit.xml` under pcov or Xdebug, without `--coverage`,
    whose own report path would win. pcov collects from the project's
    root, less its vendor directory (`-d pcov.directory=<root>
    -d pcov.exclude=~^<vendor>/~`), in Pest's own process and, through
    `--passthru-php`, in each worker paratest starts. Left unset, pcov
    collects from the first of `src`, `lib` and `app` alone, so the lines
    of a tree beside it would read as run by no test. The map is read with
    `phpunit/php-code-coverage`, and its `testResults` carry each test's
    duration. That map is PHP, which reading runs, so only the job that
    wrote it reads it. php-code-coverage leaves out of the map a test that
    ran no line it measures, such as one that only reads a class's
    constants, so the package's plugin times every test the run finished,
    from PHPUnit's events as each is prepared and finishes, into
    `<dir>/timed-tests.jsonl` (`MUTATION_GATE_TIMED_TESTS`). The gate adds
    each test the map does not time to it with that time, so the reach of
    a value, the filters, the limits and the shards see every test that
    ran.
  - A map another job hands over with `--coverage=<dir>` is only ever the
    gate's own format 1 map in that directory (ADR-0006). A runner's
    `coverage.php` there is refused. For a shard that opens on the canary
    group, the job writes the handed-over map again as `--coverage-php`
    writes one, beside its results, and its Pest loads that.
  - Groups come from `<vendor>/pestphp/pest/bin/pest --list-groups --colors=never`. A
    listing without `Available test group` is *cannot judge*, never *no
    groups*.
- **The in-house gate's patches are an opt-in.** Enabling them is
  `pest.patch: true` (a boolean, `false` by default), plus
  `@php vendor/bin/mutation-gate pest:patch` in `post-install-cmd` and
  `post-update-cmd`. The GitHub action runs `pest:patch` itself after it
  installs the project, wherever the effective config runs Pest with
  `pest.patch` on, so the Composer hook serves every other run. The
  command applies every patch to pest-plugin-mutate:
  - Shards open on a canary group (`pest.canary`, a group name,
    `mutation-canary` by default) and read the map the planning job wrote,
    instead of each running the whole suite again.
  - A mutant's run selects exactly the tests that cover it, a row of a
    data set alone (`Selection::covering`), not every row of their data
    sets as Pest's own filter does, so it runs the tests its limit is
    sized from (ADR-0008). The filter names a class, or a data set, whole
    where those tests hold every test of it the map Pest loaded ran, and
    each class once, which keeps it short enough to pass for nearly every
    mutant. A run whose filter is still too long passes none: it runs
    every test of the test files it loads, its limit is sized from those
    tests, the plugin records that it ran unfiltered, and its controls
    load the same test files with no filter (ADR-0008).
  - A `--filter` that will not fit is dropped, so that mutant runs every
    test its run loads. That can only kill more mutants, never fewer.
  - A run again makes only the mutants whose native ids a file beside the
    results lists.
  - While the override serves a file, a path stats as it does without
    it. The override pest-plugin-mutate ships reports a path the process
    cannot read as missing, so a dangling link is no link and a file
    without read permission is not there, and a test that stats either
    fails in every mutant's run whatever the mutant changes. Patched, a
    link, dangling or not, is stat'd by `lstat` where the caller asks for
    the link, and any other path that exists by `stat`. The override
    raises no warning of its own: PHP warns of a failed stat where the
    caller did not ask for quiet (`STREAM_URL_STAT_QUIET`), as it does
    without the override.
  - A file whose every class-like Composer autoloads from it, none of them
    loaded yet as the plugin boots, is served with no override at all. An
    autoloader put before Composer's loads a copy of the mutated file the
    first time one of those classes is asked for. The copy is written
    beside Pest's as `<copy>.served.php`, renamed into place whole, with
    `__FILE__` and `__DIR__` spelled as the original's. The override
    pest-plugin-mutate ships handles every file operation of the process in
    user space and registers itself again around each one, and PHP keeps
    every registration until the process ends: 14 MB over 100,000 file
    operations, so a suite that opens files in every test grows by about
    half a megabyte a test. Served so, every file opens through PHP's own
    wrapper, and a test that reads how PHP opened a file passes. The class
    names are read from the file's tokens alone, so serving a file loads
    none of the gate's classes. Any other file is still served through the
    override: a file of functions, one whose classes Composer loads from
    elsewhere or not at all, and one whose class is loaded before the
    plugin boots. So is the file of a mutant whose mutator is pinned by
    source (ADR-0021): the patched run marks its process with
    `MUTATION_GATE_SOURCE_PINNED`, since only the override shows the test
    that reads the file the mutant. A served run reads differently from
    one through the override in three ways. A test that reads the
    original's text reads it as it is on disk. A reflection of the class
    names the served copy's file. A test that loads the original by its
    path runs the original's code, and its mutant is unjudged (see where a
    mutant's file was loaded before the mutant).
  - Each mutant is allowed the standard mutant limit (ADR-0008): three
    times the shard's measured start-up, told it in
    `MUTATION_GATE_MUTANT_START_UP`, plus three times its covering
    tests' own time, as the map Pest loaded timed them, never less than
    `timeouts.seconds` and never more than `timeouts.most`, which the
    gate names. The patched plugin records each mutant's limit in the
    results file.
  - A mutant's run is also stopped where no test of it finishes for its
    silence limit (ADR-0008): the same rule, of its slowest
    covering test's own time, within the same bounds. The run's recorder
    writes a byte to its error output as the run starts executing tests and
    as each test finishes, and Pest's parent process, as it checks the
    run's limit, stops the run where none came for the silence limit since
    the last. Before the first, the run is held only to its own limit. A
    run whose filter was left out is not held to one, as its slowest test
    is not among the covering tests, and a covering test the map did not
    time has none, so neither run has a silence limit. The patched plugin records each stop in the results
    file, and the mutant is timed out at its own limit, with a reason
    naming the silence limit.
  - A mutant's own run loads only the test files its covering tests need:
    the file that declares each covering test's class, and every test file
    Pest's parent process loaded that declares a name those use, in turn.
    The names are functions, classes, interfaces, traits and enums, and
    constants declared by `const` or `define()`, used in code or, fully
    qualified, in a quoted string. Where a covering test's class is not
    loaded, or the paths would not fit in the bytes a filter may take, it
    loads every test file. The patched plugin records the files each
    narrowed run loads.
  - A test file can also give another what it needs by what loading it
    does, which no name shows. So every narrowed run also loads each test
    file that is not inert, with what it needs. A test file is inert where
    its top only declares, imports, and makes the Pest registrations whose
    effects stay in the file: `test`, `it`, `todo`, `arch`, `describe`,
    `beforeEach`, `afterEach`, `beforeAll`, `afterAll`, `dataset`,
    `covers` and `uses`. Each is one call with methods chained on it, none
    of them `->in()`, whose arguments run nothing as the file loads:
    literals, constants, class names, arrays of these, and closures. Pest
    runs two closures as the file loads, so each is held to more: a
    `describe` body, whose statements must each be such a registration in
    turn, and a dataset handed to `dataset` or `->with()` as a closure,
    which may only return or yield what runs nothing. A plain variable
    assigned, by value, a value that runs nothing as it is evaluated, at
    the top or in a `describe` body, keeps the file inert too: Pest
    requires each test file inside a method, so the variable stays in
    that file, and making a closure runs none of its body. Such a
    variable is the file's own, and reading it, in a registration's
    arguments or in another such assignment, runs nothing either.
    `$this`, `$GLOBALS`, a superglobal and an assignment by reference
    reach past the file, and a value that calls, or reads a variable not
    its own, runs code. Anything else acts,
    at the top, in a `describe` body or in a dataset closure: a hook or a
    trait sent `->in()` a directory, `pest()` and `mutates()`, which change
    Pest's configuration, a write to `$_ENV`, the environment or
    `$GLOBALS`, an include, a call, or any other statement. The files Pest
    loads in every process, `tests/Pest.php` and its kind, are loaded
    anyway.
  - A narrowed kill counts only where the run can vouch for it:
    - a kill with no test named as its killer, or only tests that errored,
      does not count, as a helper a test calls by a name built at run time
      leaves. ADR-0036 amends this: a kill only tests that errored made
      counts where the replay or the control below vouches for it, either
      of which fails in that case too, and runs again with every test file
      where it does not;
    - any other, where the run recorded the arguments Pest started it
      with, how many tests it took up to its last killer and the key of
      that order (ADR-0014), counts only where a replay of
      that run vouches for it. The replay starts Pest again with those
      arguments, in the order the plugin wrote for the mutant, with the
      file the mutant changes served unmutated through Pest's override,
      as the mutant's own run served its copy: the file printed as Pest
      prints its mutants. The plugin, told that number in
      `MUTATION_GATE_STOP_AFTER`, stops it through PHPUnit's
      `TestResult\Facade::interrupt()` once as many tests have finished
      as the run took up to its last killer. It runs within the seconds
      Pest allowed the mutant, or the longest that any kill it vouches
      for had, where that is shorter than the time left. The kill counts
      only where the replay ran exactly that many tests, none of them
      failed or errored, it ended well, and the order it took them in
      gives the same key. Anything else leaves the mutant unjudged,
      naming which: *Killed, but a test of its run fails unmutated too,
      replayed in its order, served as its mutant was*, another number of
      tests, a run that failed with no test failing, another order, a
      replay past its mutant's limit, no time left, or a file the gate
      cannot serve unmutated. A test that
      fails only while the override serves a file, as one that reads how
      PHP opened a file does, fails in the replay too, so its failure
      never counts as a kill. One replay runs for each file changed and
      key, and is kept while the gate runs unless no time was left for
      it;
    - any other counts only where every test in the files its run loaded
      passes on the unmutated code, loaded alone as that run loaded them,
      with the file the mutant changes served unmutated in the same way.
      That run is made once for each file changed and set of files, and
      kept while the gate runs.

    The replays and runs a mutation run needs are made side by side, in
    the places of its pool, each told what its place tells it, as the
    mutants' own runs were. Only Pest's runs need them: Pest alone narrows
    a mutant's own run to some test files. The PHPUnit runner and
    Infection load every test file in each mutant's run, so a kill there
    never rests on a file it left out, and the gate runs no such run for
    them.

    A kill a replay does not let stand is unjudged. Any other kill that
    does not count runs again with every test file within the time left,
    or is unjudged. A kill the run again makes counts only
    where the tests that cover the mutant pass on the unmutated code,
    among every test file, served the same way: as Pest's filter selects
    them, or the tests that judge the run where that filter is too long
    to pass, each logging JUnit. Where they fail, the mutant's tests fail
    unmutated (ADR-0008), naming those the log records failing: *Killed,
    but its covering tests fail as well with the file unmutated, served
    as its mutant was.* Where they run out of time or memory, or never
    run, it is unjudged, saying which. Where the coverage they are read
    off cannot be read, it is unjudged too, saying so. A kill whose
    records cannot be read does not count either. A kill of a mutant Pest left uncovered is the
    trial's, which ran its tests alone on the unmutated code
    first, served as its mutant is, so it always counts.
  - What a test's body leaves behind as it runs is not seen: a test that
    writes a global, a static or a file while it runs, which a test in
    another file reads, passes or fails by whether that other test ran
    first. Where a test needs that, and its narrowed run leaves it out, a
    kill can count that a run with every test file would not have made.
  - Where a mutant's own run fails, Pest's parent process records how it
    ended, by its mutated copy: the code it exited with and whether a
    signal ended it, never what it printed, as the evidence of a kill
    (ADR-0014).

  Every anchor is checked before anything is written, and one that has moved
  fails the install: a patch that quietly matched nothing is worse than none.
  With patching enabled, a canary group with no test in it is *cannot
  judge*. Without patching, every shard runs its own opening suite, and a
  line past the filter limit is *cannot judge* with a message pointing at
  the patch.
- **Timeouts**, unpatched, are Pest's own and cannot be changed, so timeout
  triage reruns nothing. Patched, each mutant's limit is the standard one.
  Either way, triage weighs each timeout against its unmutated control,
  the judging tests run on the original under the same limit (ADR-0008).
