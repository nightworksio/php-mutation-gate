# ADR-0004: Pest and Infection behind one Runner port, each reporting every mutant

**Status:** Accepted
**Date:** 2026-09-29

## Context

The gate needs four things from whatever mutates the code:

- which tests run which line (a per-test coverage map);
- the suite's groups;
- a mutation run over some files, judged by some tests;
- every mutant's result: where it is, what changed, and whether a test caught
  it.

This decision covers two runners: Pest's own mutation testing
(`pestphp/pest-plugin-mutate`) and Infection. What follows comes from their
source and from scratch projects: pest-plugin-mutate 5.0.2 with Pest 5.2.1, and
Infection 0.35.5, with every option named here checked against 0.35.0 as well.
They differ in ways that shape the adapters.

**Pest.**

- **It writes no machine-readable mutation report.** Its output is console
  text. Under `--parallel` each mutant prints as one character, and only escaped
  and uncovered mutants get a line with an `ID:`. The in-house gate never parses
  it. It reads only the exit code of `--min`.
- **It emits in-process events**, through `Pest\Mutate\Event\Facade`, in the
  Pest process that runs the mutants: `Tested`, `Untested`, `Uncovered` and
  `Timeout` per mutant, and `FinishMutationSuite` at the end. Each carries a
  `MutationTest` with its `Mutation`: file, mutator, start and end line, diff
  and id. A mutant's `duration()` is set only after its event fires, so
  durations, and the mutants that never ran, are read at `FinishMutationSuite`.
  The facade and the events carry no `@internal` tag, but Pest documents none
  of them. The interfaces a Pest plugin implements, `Pest\Contracts\Plugins\*`,
  are `@internal`.
- **It has five statuses:** `none`, `tested`, `untested`, `uncovered` and
  `timeout`. `tested` covers every non-zero exit of a mutant's child process: a
  failed assertion, a crash, and a `--filter` that matches no test, which Pest
  answers with "No tests found." and exit code 1. A covering test whose id does
  not fit Pest's filter pattern is dropped from the filter, which can leave a
  covered mutant `uncovered`.
- **Its timeout is not configurable.** It is the opening run's duration plus
  the larger of 5 s and 20 %, truncated to whole seconds.
- **`covers()` and `mutates()` narrow a run.** Each puts its test file in the
  group `__pest_mutate_only`, and restricts the run to that group and its
  classes unless `--path`, `--class` or `--everything` is given.
- **It caches generated mutants** under its own vendor directory, pointing at
  mutated sources kept beside them. A cache restored without those files turns
  survivors into kills. `--no-cache` turns the cache off.
- **Its opening run always takes the whole suite under coverage**, into a fixed
  path in its vendor directory. `--coverage-php` cannot be combined with
  `--mutate`, and no option takes a map another run wrote. The in-house gate
  patches Pest's vendor code to make it take one. A second patch covers a line
  that every test covers. Pest builds one `--filter` argument naming every
  covering test, and for such a line that argument grows past the kernel's
  limit, so the child process never starts (`posix_spawn() failed: Argument
  list too long`). Well before that, the pattern grows past what PHP's PCRE
  compiles: PHPUnit then matches no test name, the run executes no test and
  exits 0, and Pest calls the mutant survived. The patch drops such a filter
  too.
- **Mutator names are short class names, and some are shared:** two
  `BitwiseAndToBitwiseOr` classes exist, in different namespaces. `--mutator`
  accepts a fully qualified class name.
- **Each Pest release pins one PHPUnit release.** Pest 5.2.1 requires PHPUnit
  13.3.4 exactly, so a Pest project runs PHPUnit 13.

**Infection.**

- **It does not run Pest suites.** Releases from 0.29.13 on have no Pest
  adapter (infection PR #2047). Its bundled framework is PHPUnit. Codeception,
  phpspec and Testo adapters install separately.
- **It writes a JSON log, but only when the config file asks** (`logs.json`;
  its only JSON flag, `--logger-summary-json`, writes the totals alone).
  - The log has `stats` and per-status arrays: `killed`,
    `killedByStaticAnalysis`, `escaped`, `timeouted`, `errored`,
    `syntaxErrors`, `uncovered` and `ignored`.
  - Each entry is `{mutator: {mutatorName, originalSourceCode,
    mutatedSourceCode, originalFilePath, originalStartLine}, diff,
    processOutput}`. It has **no mutant id and no duration**.
  - `uncovered` is empty unless `--with-uncovered` is given, even when
    `stats.notCoveredCount` is not.
  - `stats.skippedCount` has no array. The text log (`logs.text`) lists
    skipped mutants under `Skipped mutants:`, each as
    `<n>) <path>:<line>    [M] <mutator> [ID] <hash>` followed by its diff.
  - `--log-verbosity=none` writes no log file at all. `default` and `all` write
    the same JSON.
- **Its per-mutant timeout is `min(5 s + 5 × T, timeout)`**, where `T` is the
  sum of the JUnit times of the test classes covering the mutant, and
  `timeout` is the config value in seconds, 10 by default. A mutant whose `T`
  is at least `timeout` is *skipped*: never run.
- **It reuses a coverage run natively.** `--coverage=<dir>` takes
  `coverage-xml/index.xml` plus one JUnit file, and `--skip-initial-tests` then
  skips its own opening run.
- **It passes `--test-framework-extra-args` to its opening run, and to each
  mutant run less `--configuration`, `--filter` and `--testsuite`**, which its
  PHPUnit adapter declares opening-run only. Each mutant run executes the test
  classes the coverage names, or with `--only-covering-test-cases` the covering
  test methods alone. A mutant run that executes no test counts as escaped.
- **`--mutators=<names>` replaces the config's mutator settings**, so a mutator
  configured in `infection.json5` behaves differently when named on the command
  line.
- **Relative paths in its config resolve against the config file's
  directory**, except `bootstrap`, which resolves against the working
  directory.
- **On GitHub Actions it writes annotations of its own** unless given
  `--logger-github=false`.

**Both.** Neither runner's own mutant id identifies a mutant across machines.
Pest's is `xxh3(realpath . mutator . mutated source)`. Infection's is an md5
over the file path, the mutator name, the mutation's index for that mutator and
its parser attributes. Both change when the checkout moves.

## Decision

1. **The Runner port asks for exactly what the core needs.**

   | Method | Returns |
   |--------|---------|
   | `identity()` | The runner's name, the exact installed version of every package it drives, and a digest of the PHP it runs on, as that PHP describes itself when started the way the runner starts it, without the variables withheld: version, extensions and their versions, every ini setting except the inert ones (ADR-0007), operating system family and architecture. All of these go into the content key (ADR-0007). |
   | `behaviour()` | How the runner behaves where the flows must know it, as a `RunnerBehaviour`: whether it reads `#[Holds]` as its test files load, whether a timeout's limit can be raised, the groups every proof key reads, whether each shard pays its own opening run, why a kill matrix of its run holds first killers only, and whether it runs a mutant per core or one at a time. `RunnerBehaviour::standard()` where it does none of these differently; Pest reads holds as they load; patched, it raises a limit and every key reads its canary, while unpatched, it raises none and each shard opens on its own run; Infection stops each mutant at its first failing test, so it cannot record every killer; both run a mutant per core |
   | `groups()` | The suite's groups, as the runner itself lists them |
   | `coverage(CoverageRun\|CoverageRead)` | A per-test line map of the whole suite, one group, the tests a filter names or the tests of some test files, plus each test's duration: by running them for a `CoverageRun`, or by reading the gate's own map another job wrote for a `CoverageRead` |
   | `testsIn(files, map)` | The tests of a map that these test files hold, by the runner's own rules for naming a test after its file: the entries a run of those files measures again (ADR-0023). A test the runner cannot place in a file is not among them |
   | `judges(file, map)` | The test files that can judge a mutant of this file, by the runner's own selection rules |
   | `startUp(file, withheld)` | How long one run of no test takes, started as the runner starts a mutant's own run of the file, whose mutant is an unchanged copy of it, and narrowed by a filter that matches no test (`(?!)`): what every mutant's run pays before its first test (ADR-0006). The runner serves the file as it serves a mutant, through its wrapper or, patched, with none for a class file Composer autoloads, so the run pays for that too. Pest runs itself with `--no-tia --bail --filter (?!) --do-not-fail-on-empty-test-suite` in the environment pest-plugin-mutate gives a mutant's run under `--parallel`: `PEST_MUTATION_TESTING` naming the file, `PEST_MUTATION_FILE` naming the copy, `PARATEST`, `TEST_TOKEN`, `UNIQUE_TEST_TOKEN` and `LARAVEL_PARALLEL_TESTING`. It loads every test file, as a mutant's run does. Infection's is the project's PHPUnit with no PHP options, as Infection starts a mutant's, with its extra arguments and the same filter, on a config shaped by the steps of Infection's `MutationConfigBuilder` that change what a run loads. Those steps are: every path absolute from the config's directory, no loggers, coverage reports, colours, printer or default suite, the suites replaced by one holding the covering test files, here none, and the bootstrap replaced by Infection's, which lowers the process's priority, serves the copy through Infection's include interceptor and then loads the config's own bootstrap. So it loads no test file. The steps that only order, stop or report a run (the result cache, the fail-on attributes, stop-on-defect, stderr) are left out. The gate takes those steps itself rather than call Infection's builder, which is the project's install, not the gate's. The run withholds what every child process withholds |
   | `mutate(request)` | Every mutant's normalised result for some files, judged by the whole suite or by a group, under a deadline |
   | `retry(request, mutants, limit)` | The same, for a few mutants run again, the invocation's request narrowed to them (ADR-0008) |
   | `reproduce(mutant, request, limit)` | One mutant run again on its own, the request narrowed to its file and its mutator, with what the runner printed |
   | `markers(files)` | The runner's own ignore markers in those files and in its config, each with the `ignores.entries` entry that replaces it (ADR-0008). Whether a run may go ahead with them is the verdict's to decide |
   | `definitions()` | The files that define how the runner runs, as paths from the project's root. Pest names `tests/Pest.php` and the PHPUnit config in the root; Infection names its config under each of its four names and the PHPUnit config in `phpUnit.configDir`, or in the root where the config sets none. Each PHPUnit config is named by each of the names PHPUnit looks for: `phpunit.xml`, `phpunit.dist.xml` and `phpunit.xml.dist`. A change to one reaches everything (ADR-0005), and every content key reads them (ADR-0007) |
   | `names(tests, withheld)` | Each test by its file and the description the runner gives it, or the data set row that folds into it (ADR-0014). Infection names a test from the file that declares its class and its method. Pest lists the suite's tests, running none, and its plugin names each one. That listing withholds what every child process withholds |
   | `rootedAt(package, tests)` | The same runner in a package's directory, with the package's vendor and gate directory, and its tests in the directories the CLI reads from the package's own PHPUnit config (ADR-0005). A directory where the runner is not installed cannot be judged |

   A failed opening run, an unsupported version or a result that does not add up
   is returned as *cannot judge* with the runner's output (ADR-0001). The run
   stops with exit code 2.

2. **Every mutant is normalised to one record.**
   - **id**: the gate's own, 12 lowercase hex characters of a SHA-256 over:
     - the path relative to the repository;
     - the mutator's full name: its fully qualified class name for Pest, its
       name for Infection;
     - the removed and added lines of the diff, with whitespace collapsed;
     - the mutant's position among mutants in the same file that share
       everything above.

     The line number is not an input, so an id survives code above it moving.
     The runner's native id is kept alongside for use within the same run only.
   - **where and what**: file, start and end line where the runner gives them,
     the mutator's full name, its family (ADR-0009), and the diff.
   - **status**, as the runner reported it:
     - killed, survived, uncovered or errored;
     - timed out, or skipped: too slow to run at all, because the tests
       alone take its limit (unpatched Infection's own skip, and that of
       judging by reference). None is run again, and the timeout rule is applied at verdict
       time (ADR-0008);
     - unjudged: no result, because the budget ran out, the runner stopped
       first, a covering test could not be put in Pest's filter,
       or judging by reference could not judge it, with its reason.

     An Infection mutant that Infection reports as ignored (its
     `ignoreSourceCodeByRegex`) is recorded as *ignored by a native marker*
     (ADR-0008). Flaky and ignored are otherwise applied later by
     the gate (ADR-0008).
   - **duration**, where the runner reports one.
   - **limit**, for a timed-out or skipped mutant: the seconds the runner
     allowed it, which timeout triage needs (ADR-0008).

3. **The Pest adapter** (`pestphp/pest` ^5.1, `pestphp/pest-plugin-mutate`
   ^5.0, and the PHPUnit 13 release Pest pins), as [the Pest adapter](0004-pest-and-infection-behind-one-runner-port/pest-adapter.md)
   sets it out: how it is invoked, where Pest's filter and the wrapper fall
   short, its plugin, statuses, coverage, its patches, and timeouts.
   ADR-0041 amends this: pest-plugin-mutate is allowed from 5.0.2 to below
   6.0.

4. **The Infection adapter** (`infection/infection` ~0.35.0, with PHPUnit 12
   or 13), as [the Infection adapter](0004-pest-and-infection-behind-one-runner-port/infection-adapter.md)
   sets it out: how it is invoked, what it reads back and how, its patch,
   its scope, and how it narrows a run to one mutator.

5. **Which tests can judge a file is the runner's answer, not a guess.** The
   content key needs it (ADR-0007).
   - **Pest** selects covering tests with an unanchored regular expression,
     `<Class>::(.*)<name>`. So a test class also selects every class whose name
     ends in the same letters. When the filter is dropped, a mutant
     runs against the whole suite. The adapter answers with exactly that set:
     every file whose class ends in a covering test's class name, or every test
     where the filter would not fit. The seed computes the same set.
   - **Infection** runs the covering test cases' classes, and the adapter
     answers with their files.
   - **Under Pest, a unit whose tokens hold a class or interface constant, a
     property default, an enum case, a plain function's or closure's parameter
     default or an attribute argument** can have mutants judged by reference, so for it the answer is every test file.

   A mutant's *judging tests* are the tests that decide its result: its
   covering tests, or, judged by reference, the test files it selected and, where
   used, the fallback. Of a held unit they are those of its covering tests
   that hold it (ADR-0005), the only tests its run selects. Timeout triage,
   flaky triage and hints name these (ADR-0008, ADR-0009).

6. **Reproducing one mutant is runner-neutral.** `mutation-gate reproduce <id>`
   runs the runner over the one file with only that mutator: Pest's `--path`
   with `--mutator=<class name>`, or Infection's positional path with the
   narrowed config of the Infection adapter. It finds the mutant by the gate's id, or a
   unique prefix of six or more, in the newest record the readable ledgers
   hold, and runs it under the conditions of the run it came from: by the
   tests that judge its unit, allowed the configured time limit, withholding
   what a run withholds, and under the memory cap every run has. It prints
   the diff, what was recorded, what the run found, the judging tests and the
   runner's own output for it. It exits 0 where the run
   finds what was recorded, 1 where it finds something else, and 2 where no
   ledger read holds the mutant, the run no longer makes it, or the runner
   cannot judge. A mutant judged by reference is re-run through those
   steps instead, against its judging tests, because Pest reports it
   uncovered before any test runs.

7. **One contract suite for every runner.** `tests/Contract/Runner` holds a
   fixture library with a known killed, survived, uncovered, timed-out and
   held mutant. Every adapter must produce the same normalised records for it.
   The library also holds what each runner gets wrong on its own: for Pest, a
   test whose name its filter cannot express; for Infection, a mutant it skips.
   For Pest's `#[Holds]`, the suite asserts the exact set of tests Pest runs
   for `--group=holds:<path>`, serially and under `--parallel`, and in a
   mutant's child process:

   - a held `it()`, `test()` and `arch()` closure, with `function` and with
     `fn`;
   - a held closure whose path is a class constant, not a literal;
   - a PHPUnit class with `#[Holds]` and its matching `#[Group]`, run by Pest;
   - a held `describe`, with a nested `describe`, a higher-order test and a
     skipped test inside;
   - a held test with a dataset, where every row is selected;
   - a test that covers the path but does not hold it, which is never
     selected;
   - two `#[Holds]` on one closure;
   - `--list-groups`, which shows every `holds:` group.

   CI runs the suite against the lowest and highest supported version of each
   runner (ADR-0011).

8. **A mutant on a line that is not executable is judged by the tests that
   reference its symbol, and one on a statement's first line by the tests
   that run the statement** (Pest only). ADR-0037 amends this for a patched
   shard: a constant's or a backed case's lines, and a global constant's,
   are covered by the tests one run judges them by, where the scan follows
   every read, so their mutants run in the mutation step.
   - **Which mutants.** php-code-coverage leaves some lines out of the
     coverage map altogether: class and interface constants, enum cases,
     property declarations, parameters of plain functions and closures, and
     attribute arguments. Pest marks every mutant there *uncovered* and runs no
     test. The gate takes these mutants from the `Uncovered` events, reads
     what the first token the mutated copy writes differently stands in, and
     judges them itself. Pest writes the mutated copy as php-parser's print of
     the whole file, which lays the code out its own way: it drops a trailing
     comma, adds parentheses and respells a number. So the gate prints the
     original the same way, finds the first token the two prints write
     differently, and carries it back to the file through the tokens the file
     and its print share.
     - Coverage may also leave a statement's first line unmarked though the
       statement ran: pcov never marks the head of a `match (true)`. A mutant
       Pest left uncovered on the first line of a statement inside a
       function's body, where a bracket opened on that line closes on a later
       one, is judged by the trial below with the test files that cover the
       statement's later lines up to that close, since PHP runs a statement's
       first line before any other. Where no test covers them, it stays
       uncovered. A line that starts no statement, such as a `match` arm's or
       an `else`, can stay unrun while the lines around it run, so an
       uncovered mutant there, as on a statement of one line, stays
       uncovered, but for one in the head of a `match`.
     - A mutant Pest left uncovered in the head of a `match`, which is the
       `match` keyword, its subject in parentheses and the `{` that opens its
       arms, is judged by the trial with the test files that cover the lines
       inside its braces, wherever the match stands: as a statement, inside
       an argument list, after a ternary's `?`, or in another match's arm.
       PHP evaluates a match's subject before any arm, so a test that runs a
       line of its arms ran its head. Where its arms close on the line its
       head opens them on, or no test covers them, it stays uncovered.
     - Coverage can also mark an arm's line run only for the tests whose
       result that arm is: pcov marks the condition of the first arm of a
       `match (true)` that reads a constant on the match's head. PHP tries a
       match's arms in order, so a test that runs a later arm ran the
       condition of every arm before it. In a patched shard that opens on a
       map the plan handed over, the map Pest opens on covers each line of a
       match's arms with the tests that cover it or any later line of those
       arms, so a mutant of an arm's condition runs the tests of the arms
       after it. A run that measures its own coverage keeps Pest's reading of
       the line.
     - A global `const` or `define()` sits on an executable line, so an
       uncovered mutant there stays uncovered.
     - pest-plugin-mutate makes no mutant of a string-backed enum case's
       value, only of an int-backed one, so under Pest a string case's value
       is never mutated, and the gate has no mutant of it to judge.
     - Infection never generates mutants on constants, enum cases, property
       declarations or attribute arguments: it mutates only inside functions
       and their signatures. A parameter default is in a signature, so
       Infection mutates it and reports it as it reports any mutant. A
       contract test proves both.
   - **Which tests judge it.** A scan of tokens, with names resolved through
     namespaces, imports and aliases, finds every reference to the symbol:
     - in test files, the test files themselves, where the coverage map
       names a test of theirs, whose class is the one Pest declares for the
       file or one the file declares itself: another file under the test
       directories, such as a helper or a fixture, is no test file Pest runs,
       even where its name ends in a test's class name;
     - in source files, the test files that hold a test covering the line of
       each reference, read from a coverage map that holds every file: the run's own opening
       map, or, in a shard that opened on the map of its own files, the plan's
       whole map (ADR-0006). So a constant read through `self::RATE`
       inside a covered method is judged by that method's tests. References
       are followed through constant expressions and defaults, at most three
       steps deep, and a longer chain counts as ambiguous. A reference in a
       method's or a closure's parameter default is judged by the tests
       covering any line of that function's body, which runs it wherever a
       call leaves the parameter out; one in an arrow function's or a
       bodiless method's default counts as ambiguous.

     What counts as a reference:
     - `Owner::NAME`, and `self::`, `static::` and `parent::` inside the owner
       and its subclasses;
     - an inherited, interface or trait constant through any class that
       extends, implements or uses its owner;
     - an enum case through `Enum::Case`, and a case's backing value through
       any use of the enum, because `from()`, `tryFrom()`, `cases()` and
       serialisation all read it;
     - a constant through a reflection, where it is built: one built on a
       class written out (`Owner::class`, `self::class`, `parent::class` or a
       string) that reaches the owner, a `ReflectionClassConstant` only where
       it is built with the constant's name or a name not written out; and
       one built on anything else, such as a variable or an object, in a file
       that names the owner;
     - a static property through `Owner::$name`, and a trait's through any
       class that uses it;
     - an instance property's default through any creation of the owner, or
       of a class that extends it or uses it as a trait, and through the body
       of a constructor such a class declares, which runs however the
       instance is made, by a container or a reflection too;
     - an attribute's argument through any name of its owner: the
       attribute's class, or, for `#[Attribute]`, the class-like it makes an
       attribute, as a reflection that makes the attribute names it;
     - a plain function's parameter default through the covering tests of the
       lines that call it;
     - a read in a helper, a file under the test directories that Pest does
       not run as a test file, through the test files that name a class or
       function the helper declares, or that name another helper that does,
       in turn. Pest runs a file as a test file where it holds a test the map
       names, or where the suite's config declares it a file of test cases
       (`TestCases`, which every run's request carries in its narrowing). A
       test file whose tests run no line is one of these: a map
       php-code-coverage reports names no test of it. A fixture under a test
       directory that the config leaves out, or that lacks its directory's
       suffix, stays a helper, though it declares tests of its own.

     A closure's parameter default and a method's parameter default have no
     reference a token scan can follow, and a reflection no name leads to can
     make an attribute, so these and an attribute's argument are always
     ambiguous. A read in a helper is ambiguous too, as a helper can
     run where no name shows it, such as a base test case's set-up or a
     `Pest.php` hook. In a
     held unit (ADR-0005), every set this decision names, the fallback
     included, is limited to the holding group.
   - **The fallback** is the set of test files that cover the owner's file.
     It is used when it has at most 10 test files:
     - when the direct references leave the mutant alive, it runs against the
       fallback too before it counts as a survivor;
     - when the scan is ambiguous (a variable class such as `$class::NAME`, a
       `constant()` call on a non-literal name, or `static::NAME` that a
       subclass overrides) and found no direct reference, the fallback alone
       judges it.

     A kill by the direct references stands, whatever the fallback holds. An
     ambiguous mutant they leave alive, or with no direct reference, whose
     fallback is over the bound is unjudged, and its reason says why, for
     example *ambiguous reference; src/Theme.php is covered by 152 test
     files*. An unambiguous survivor whose fallback is over the bound stays a
     survivor.
   - **No reference** makes the mutant unjudged, with the reason *no test
     reaches this value*. It is never passed.
   - **How it runs.** Pest serves a mutated file to a process through an
     override that its mutate plugin registers on every boot when
     `PEST_MUTATION_TESTING` names the original file and `PEST_MUTATION_FILE`
     names the mutated copy. That happens with or without `--mutate`, and in
     every `--parallel` worker, which inherits the environment. Patched, a
     class file Composer autoloads is served by an autoloader in the
     override's place (decision 3). The gate uses
     the same mechanism Pest uses for its own mutants:
     1. The plugin copies each uncovered mutant's mutated file from its
        `Uncovered` event to `.mutation-gate/pest/mutants/<native id>.php`.
        Every run of the adapter starts with none left from an earlier one.
     2. The selected tests run once on the unmutated code, narrowed as in
        step 3, with the two variables naming the original and a copy of it
        printed as Pest prints its mutants, written under
        `.mutation-gate/pest/trials/originals/`, so the override serves the
        file as it serves the mutant. They run once for each set of test
        files and file they judge, within the mutant's limit. If they fail,
        or the original cannot be read, printed or copied, the mutant is
        unjudged: *the selected tests fail on their own*. A test that fails
        only while the override serves a file fails here too. If they are
        stopped at the limit, the mutant is skipped, with that limit, since
        no run of it within the limit can judge it.
     3. For each mutant, one at a time, the gate runs
        `<vendor>/pestphp/pest/bin/pest --no-tia --bail --colors=never` over
        the selected test files, with `--group holds:<path>` for a held unit,
        from the project root, within the standard mutant limit of the own
        time of the selected files' tests, as the map timed them, between
        `timeouts.seconds` and `timeouts.most`, withholding what the request withholds, with the
        two variables and `MUTATION_GATE_GUARD` set.
     4. A failing run kills the mutant, and a passing run leaves it alive for
        the fallback above. A run stopped at the limit times the mutant out.
        A missing mutated file makes it unjudged, *mutated file missing*,
        never killed. A kill names as its killers the tests the run's JUnit
        log says failed or errored, as the coverage map names them: a Pest
        test by its class with Pest's `P\` and the method Pest makes of its
        description, a test method by its class and name, each with its data
        set. Where the log names none, how the run ended is the kill's
        evidence, which judges it (ADR-0014).
   - **Guards.** When `MUTATION_GATE_GUARD` names a file, the plugin writes to
     it whether the original file was loaded before the override started,
     or by its path where a copy is served in its place (decision 3),
     whether it or that copy was loaded at all, and the opcache settings. It writes as
     Pest terminates its plugins, and again as the process ends: Pest ends a
     process a fatal error stopped by exiting from its own shutdown function,
     which leaves every later one uncalled. A file is loaded where PHP lists
     it, or where the last error PHP raised stands in it, as one a mutated
     copy that cannot compile raises. Composer's `files` autoload,
     `tests/Pest.php` and datasets can load a class before any plugin
     starts. Each of these makes the mutant unjudged, with its reason:
     - *loaded before the override*;
     - *never loaded*;
     - `opcache.enable_cli` or `opcache.file_cache` on, because a cached
       original could be served instead of the mutant;
     - no guard written at all: *the run wrote no guard, so the gate cannot
       tell the mutated file ran*. A run that a signal ended, whose exit code
       is 128 plus the signal's number, from 129 to 192, writes none either,
       and kills the mutant instead: its tests passed on their own in step 2,
       so only the mutated file can have ended it. PHP's exit code for a
       fatal error, 255, is no signal's.
   - **What a run that judges nothing says.** Each run writes PHPUnit's
     JUnit log beside the guard (`--log-junit`). A mutant left unjudged by a
     run, on its own or with the override, carries after its reason, in
     brackets: the exit code; the first
     test the log names as failed or errored, by its file and description,
     with the first line PHPUnit said of it, or, where none did and the run
     failed, the last line the run printed; and the test files it ran, the
     first three and how many more. Each line from the run is one plain line
     of at most 200 characters.
   - **Costs.** Each run's time goes into the cost model like any other
     mutant's (ADR-0006).
   - **Contract tests**, one per kind of symbol:
     - a class constant named in a test through an alias;
     - a constant read only through `self::` in a covered method;
     - an inherited constant, and an interface constant;
     - an enum case's `->value`, a value seen only through `from()`, and a
       duplicate value;
     - a static property default, an instance property default reached
       only through the fallback, and one a reflection makes, killed through
       the constructor;
     - a plain function's parameter default, a closure's parameter default,
       an attribute's argument, and `#[Attribute]`'s, killed by a test that
       makes the attribute;
     - the condition of a `match (true)`'s first arm, killed by a test whose
       result is a later arm;
     - a global `const` in a `files` autoload file, which stays uncovered,
       and a function's parameter default there, loaded before the override;
     - no reference, and a same-named constant in another class that does not
       count;
     - `$class::NAME` inside the bound, with two test files as arguments, and
       beyond it, and a chain four steps deep;
     - a held unit, where only the holding group judges;
     - a symlinked project root, and an enum `tests/Pest.php` loads before the
       override;
     - Infection, which emits no mutant on a constant, an enum case, a
       property or an attribute argument, and one on a parameter default.

9. **Every PHP process a mutation run starts has a memory cap.**
   - `runner.memory` is the `memory_limit` of each PHP process a mutation
     run starts: the runner's own, its opening run of the suite, and each
     mutant's process, and so of each reproduction. It is written as PHP writes it, a whole number of
     bytes, `K`, `M` or `G`, such as `512M`. It is `1G` by default, and `-1`
     for none. A mutant that runs away with memory then stops alone, rather
     than taking the machine and every mutant still to run on it with it.
   - The adapter writes `memory_limit` to an ini file in a directory of its
     own in the run's workspace, and adds that directory to
     `PHP_INI_SCAN_DIR`, after the directories PHP scans already. A runner
     starts each mutant as a PHP process of its own, which takes none of the
     options of the command that started the runner but inherits its
     environment. A cap the adapter cannot write makes the run *cannot
     judge*.
   - A project that sets `memory_limit` itself sets it after PHP reads the
     cap, and so wins over it: with `<ini name="memory_limit">` under `<php>`
     in its PHPUnit config, or with `ini_set()` in a bootstrap file. doctor
     finds the first; the second shows only when the tests run.
   - Coverage runs, listing the tests, and the gate's own process run
     without the cap; the gate's own process takes the `memory_limit` its
     ledgers need (ADR-0013).
   - The plan weighs the suite against the cap in force: `runner.memory`,
     or the `memory_limit` of the PHPUnit config the runner reads (the
     project root's for Pest; for Infection, the one in `phpUnit.configDir`,
     or the root's where its config sets none) where that is higher or none.
     Where the largest process of the coverage run it has just run held
     more, the plan is *cannot judge*, and says to raise `runner.memory`. The
     peak is the most resident memory `getrusage` counts for the processes
     the gate waited for, an upper bound on what `memory_limit` counts, so a
     suite near the cap can be refused though its mutants would fit. Where
     the system counts no such peak, or the plan reads a map another job
     wrote, it plans.
   - The plan owns the peak. It records the peak of the coverage run it
     planned from as `peak`, in bytes, within its digest, and every shard
     reads it from there, so a shard that reads the map the plan job handed
     over triages by the plan job's measurement. A plan without `peak`,
     made from a map another job wrote, or before plans recorded it,
     measured none.
   - A mutant whose own process ran out of exactly `runner.memory`, to the
     byte, is *out of memory*. PHP's fatal error names the limit it ran out
     of, and PHP logs it as it happens, before any shutdown function: Pest
     and PHPUnit end the process, or run out of memory themselves, before
     anything registered later could write it. So Pest's plugin logs PHP's
     errors in each mutant's own process to a file of that mutant's own
     (`log_errors` and `error_log`, which decide nothing a test computes),
     and reads it in the process that started the mutant; a test or config
     that sets `error_log` itself logs elsewhere. The Infection adapter reads
     the error from the output Infection logs for a mutant it counts killed
     or errored, which is the mutant's standard output alone, and the
     PHPUnit it runs sends `error_log` to a file of its own around each
     test, lost with the process. So the cap's ini file for an Infection run also sets
     `display_errors=stdout`. That decides where an error is shown and
     nothing a test computes: PHPUnit's own handler still takes every error a
     test raises, and only one raised outside it, such as in a bootstrap
     file, now also prints on standard output. A project that shows errors
     nowhere again leaves only PHPUnit's word that its process ended
     mid-test, which PHPUnit also prints after `exit` or `die`. So, under a
     cap, such a mutant is out of memory with no limit known, so too heavy
     to judge, only where the hiding is visible: the project's PHPUnit
     config sets `display_errors` in its `<ini>` to print nowhere or on
     standard error, or PHPUnit 12.5 says it hid the error. Errors hidden at
     runtime, such as by `ini_set` in a bootstrap file, are not seen, so a
     mutant out of the cap there is a false kill. Where the hiding is
     visible, an `exit` or `die` mid-test prints the same sentence, so it
     reads as too heavy to judge too. A limit the project set itself, any
     other fatal error PHP shows, `exit` and `die` where errors are not
     visibly hidden, the system's own out-of-memory killer and a crash keep
     the status the runner gave them.
   - Memory triage judges a mutant out of memory the way timeout triage
     judges a timeout (ADR-0008), by an **unmutated control**:
     the tests that judge it (of a held unit, only those that hold it), as
     its own run selected them (ADR-0008), run with its file served
     unmutated as its mutant was, under the same cap, allowed the standard
     mutant limit of their time. Every runner starts
     the control through one launcher, a PHP script that runs the command
     and writes the most memory any process under it held, as `getrusage`
     counts the processes it waited for (`Core\Control\PeakLauncher`), so
     every runner, an unpatched Infection included, measures alike. Two
     mutants of the same file and tests share one control.
     - Where the control finishes under the cap, the mutant needed far more
       than its own tests and ran away: it is *killed by the memory cap*,
       counts as killed, with no killer named, and its need is the
       control's peak.
     - Where the control runs out of the cap too, runs out of time or never
       runs, it is *too heavy to judge*, counts as not killed under the
       `unjudged` rule, and its reason says which. Where the control fails,
       the tests fail with the file unmutated as well: the mutant's tests
       fail unmutated (ADR-0008).
     - doctor's `memory-cap-near` still advises a cap of twice the suite's
       peak, which leaves the controls room.
   - A result records a mutant out of memory with its cap and its control's
     peak, in bytes. A carried one is unjudged, as a timeout is, since
     triage can count it as a kill; a proved one is judged again under the
     peak it recorded.
   - Where the runner's own process runs out of the cap, the opening run
     of the suite included, the run is *cannot judge*, and says to raise
     `runner.memory`.
   - The cap's ini file is written, whole, into a directory of the runner's
     workspace for each process of the gate, which it empties first and
     removes when the run is done. A link at any level from the gate's own
     directory down to that one is refused; the levels above it are the
     project's, which the gate reads through as it reads the project. An
     entry other than a file in that directory is refused before a run;
     after one, the files are removed and the directory is left, so the next
     run refuses it.
   - The cap can change a mutant's result, so it is part of a proof's key
     (ADR-0007).
   - doctor's findings on it are advice (ADR-0017):
     `memory-uncapped` where it is `-1`; `memory-cap-lifted` where the
     project's PHPUnit config sets a higher `memory_limit`, or none; and,
     under `--measure`, `memory-cap-near` where the suite's processes held
     over half the cap, by the most resident memory `getrusage` counts for
     the processes the gate waited for.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Parse Pest's console output** | Under `--parallel` a killed mutant is one character, with no file, line or id. Only escaped and uncovered mutants are named. Per-file counts, which the verdict needs below a floor of 100 (ADR-0003), are not there. |
| **One Pest invocation per file, reading the exit code** | Every invocation pays Pest's opening suite, which is what shared coverage exists to avoid, and the exit code still says nothing below a floor of 100. |
| **Infection for Pest projects** | Infection removed Pest support. Pointing its PHPUnit adapter at `vendor/bin/pest` depends on PHPUnit-shaped filters and JUnit that Pest does not promise. |
| **Pest's `--id` or Infection's id as the gate's id** | Both hash an absolute path, so an id printed in CI does not exist on a developer's machine, and an ignore written against it would never match. |
| **Infection's id from the Stryker report embedded in its HTML log** | Means parsing JSON out of an HTML page, and the gate needs no native id: narrowing to file and mutator finds a mutant in a handful of runs. |
| **Skipped mutants from Infection's HTML log, or only as a count** | The HTML log's embedded report gives skipped mutants the status `Ignored`, the same as ignored ones. A count alone gives no file, id or diff to report. The text log names each one, and the JSON log's count checks it. |
| **Passing `--covered-only`, or leaving out `--with-uncovered`, under `uncovered: exclude`** | The runners would then report uncovered mutants differently, Infection's log would not add up, and changing the setting would re-run every unit. The gate applies it at verdict time instead. |
| **Patching a source file in place to judge a mutant on a line that is not executable, then restoring it** | Every other process sees the mutant meanwhile: an editor, another run, and Pest generating mutants from that same file. A run killed at its deadline leaves mutated source that a pre-push could commit before the next run restores it. Pest's own override changes nothing on disk. |
| **Pest's `--id` with a forced test set, for such a mutant** | Pest decides *uncovered* before it starts any process, so no option reaches the test run. |
| **Counting such mutants as uncovered, or leaving them out** | A constant or an enum value a test depends on would then be either always against the project or never judged, whatever the tests assert. Judging it against the tests that reference it gives the real answer. |
| **Patching Pest by default** | Edits another package's vendor code on every install without being asked. Opt-in keeps that a visible decision in the project's own `composer.json`. |
| **Waiting for Pest to offer a report or a shared map** | Not in this package's control. The adapter works with what the supported versions ship, and the contract suite finds out when that changes. |
| **The cap as `-d memory_limit` on the runner's command** | Only the process the gate starts takes it. Each mutant's process is started by the runner, and inherits the environment, not the options. |
| **An address-space limit (`ulimit -v`) on the runner's processes** | It counts reserved address space, which PHP's JIT and OPcache reserve far beyond what they use, it does not exist on Windows, and a process over it dies with no message that names the cause. |
| **Codeception and phpspec through Infection** | Their coverage and group listing are not PHPUnit's, and nothing in the gate's reach, holds or proof key has been checked against them. An extension can add them through the same port. |

## Consequences

**Every mutant is on record, from either runner.** The verdict, the baseline,
the proofs, the reports and the hints all read the same normalised records.

**The Pest adapter depends on an undocumented API and `@internal` plugin
contracts.** Each pest-plugin-mutate release has to pass the contract suite
before its `conflict` entry is relaxed. ADR-0041 amends this: the `conflict`
refuses only below 5.0.2 and from the next major, and each release that
passes is counted as tested by `doctor`. A Pest release that moves them fails
the contract suite, not a user's gate.

**Sharded Pest runs are fastest with the patch.** Without it each shard pays
one opening suite. The README says so beside the CI examples.

**Infection is pinned to a 0.x minor.** Each Infection minor may break its
interfaces, so each is added only once the contract suite passes on it.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-nine-ports.md): the port and its outcomes
- [ADR-0003](0003-a-floor-only-rises.md): how statuses become a score
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): holding groups and `#[Holds]`
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): runner identity, and the tests that can judge a unit, in the key
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): timeouts, flaky results and ignores
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the first killer recorded, and the order of a mutant's tests
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): every killer recorded, for a full kill matrix
- [ADR-0036](0036-a-kill-only-errors-made-counts-where-its-control-passes.md): a narrowed kill only errors made, judged by its control
- [ADR-0037](0037-a-value-is-covered-by-the-tests-that-read-it.md): a declared value's lines covered by the tests that read it
