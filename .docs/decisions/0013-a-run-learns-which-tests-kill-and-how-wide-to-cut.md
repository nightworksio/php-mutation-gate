# ADR-0013: A run learns which tests kill and how wide to cut, proves equivalent survivors, and lets a fork read what the default branch proved

**Status:** Accepted
**Date:** 2026-09-30

## Context

The gate already spends its time carefully. Reach narrows a pull request to
what it touches (ADR-0005), proofs skip what is already proved (ADR-0007), and
shards are cut by learned cost (ADR-0006). Four costs remain.

- **Each killed mutant runs its covering tests in suite order.** A mutant run
  stops at its first failing test: Pest's mutant child always runs with
  `--bail`, and Infection's mutant configuration sets `stopOnDefect`. The
  sooner the test that kills it runs, the sooner the mutant is done. The gate
  records no test as a killer today, so it cannot put one first.
  - **Pest.** A mutant's child is a fresh `vendor/bin/pest` with the
    original arguments plus `--bail` and one `--filter` naming the covering
    tests. The filter selects tests and does not order them. The package's
    Pest plugin boots in that child (ADR-0004), and Pest lets a plugin
    implementing `HandlesArguments` rewrite the child's arguments. PHPUnit
    13.3, the release Pest pins, orders tests with `--order-by=defects`. That
    puts tests with a recorded defect first, then orders the rest by recorded
    time, both read from the test run history in the cache directory.
  - **Infection.** Infection builds each mutant's PHPUnit configuration
    itself. It lists the covering test files fastest first, and keeps a
    per-mutant history under its temporary directory, keyed by its own
    mutant hash. It deletes that directory at the end of every run. The gate
    has no hook into that order.
  - **The first killer is the only one known.** Both runners stop at the
    first failure. Infection's `logs.json` keeps each killed mutant's process
    output, which names the failing test. Pest's parent process sees only
    the child's exit code.
- **The shard count ignores what each shard pays before it mutates.** A
  shard's wall time is its CI setup, plus its runner's opening run, plus its
  share of the mutation cost. ADR-0006 models only the share, and fixes the
  count at total cost over `shards.seconds`. A team that wants "the mutation
  stage in ten minutes" has no way to ask for it.
- **An equivalent mutant stays a survivor until someone writes an ignore.**
  Some mutants change the source and not the program. An identical program is
  what compilers prove cheaply: *trivial compiler equivalence* compares the
  compiled forms of the original and the mutant. Neither runner accepts a list
  of mutants to leave out (ADR-0006), and the mutated code exists only once
  the runner has generated it. So a proof can relabel a survivor after the
  run, and cannot skip a mutant before it.
- **A fork's pull request has no credentials.** On GitHub a fork's
  `pull_request` run can restore the base and default branch's caches and
  write only its own `refs/pull/<n>/merge` scope, so the Actions-cache ledger
  already serves forks read-only. An S3 store needs credentials a fork never
  gets. GitHub also evicts a cache entry that nothing restores for seven
  days, and a weekly schedule sits on that edge.

ADR-0011 ties the first release to a count of features. The features this ADR
decides are accepted for that release too.

## Decision

1. **The first test that kills each mutant is recorded.**
   - **Pest.** In a mutant's child process, the package's Pest plugin
     subscribes to PHPUnit's `Test\Failed` and `Test\Errored` events. It
     appends the mutated file Pest serves and the failing test's id to the
     results file that `MUTATION_GATE_RESULTS` names, which the child
     inherits. The adapter joins each line to its mutant by the mutated
     file's path (`Mutation::$modifiedSourcePath`). ADR-0004's decision 3
     records the plugin's jobs in a child.
   - **Infection.** The adapter reads the failing test's id from each
     killed mutant's `processOutput` in `logs.json`.
   - **The record.** Each mutant carries `killedBy`: a test id, or *unknown*
     for a mutant killed by a timeout, an error before any test failed, or
     static analysis.

2. **A killer history, kept in the ledger, predicts the next killer.**
   - **What it holds.** For each mutant id, the tests that killed it and how
     often, keeping the five most frequent. For each enclosing function (the
     one a hint names, ADR-0009 decision 7), the same, summed over its
     mutants. A mutant seen for the first time takes its function's history.
     The gate's id holds no line number (ADR-0004 decision 2), so the history
     survives code above it moving.
   - **Where it lives.** A `killers` section of each scope's ledger, within
     format 2 (ADR-0007 decision 3): each ranking a list of `[test, kills]`
     pairs, most kills first, `test` an index into the ledger's `tests`. A
     run reads its own scope's and the default branch's, as it reads
     timings, with its own scope's ranking where both know a mutant or a
     function, and the verdict writes its own scope's.
   - **What it keeps.** The mutant ids a kept proof holds, and of those and
     of the functions, the 20,000 mutants and 5,000 functions that most
     recently learned a killer. Functions of files that no longer exist are
     dropped. Of two tests with as many kills, the one that killed most
     recently ranks first, so a new killer can displace an old one.

3. **`tests.order` puts the likely killers first.** Its values are
   `killers-first`, the default, and `runner`.
   - **The order.** The tests the history names come first, most frequent
     first. The other covering tests follow, fastest first by the coverage
     map's durations. A mutant with no history at all runs fastest first.
   - **Pest.** The adapter hands the plugin the kill history in
     `.mutation-gate/order/plan.json`, as a ledger's `tests` and `killers`
     sections hold it, and names that directory in `MUTATION_GATE_ORDER`.
     Once Pest has made its mutants and before any runs, the plugin, in
     Pest's own process, writes each mutant's order into
     `.mutation-gate/order/<name of its mutated copy>/test-run-history`. That
     name is what a mutant's own process sees, in `PEST_MUTATION_FILE`. In
     that process, the plugin's `HandlesArguments` points PHPUnit at the
     order with `--cache-directory=<that directory> --record-test-run-history
     --order-by=defects,duration`.
   - **What an order can say.** Pest keeps PHPUnit's test run history in a
     file of its own, whose `version` is `pest_` and the version
     `Pest\version()` answers, which is not always the version Composer
     installed. So the plugin writes it in Pest's own process. PHPUnit reads
     a history of any other version as none.
     - Under `--order-by=defects` PHPUnit weighs a test's recorded status
       alone: an error before a failure, and every other status the same as
       none, with no tie broken by time. So the first likely killer is an
       error and the next four are failures, which keep the suite's order
       among themselves.
     - `duration` orders every test by its recorded time first, so the tests
       below the likely killers run fastest first. Each covering test's time
       comes from the opening run's coverage map.
     - PHPUnit orders a file by the highest weight among its tests and never
       interleaves two files' tests. So a file that holds a likely killer runs
       first, with that test first within it.
     - The coverage map names a data set's row `Class::method#<name>`, and
       the history `Class::method with data set "<name>"`, or `#<number>` for
       a row keyed by a number. One place turns one into the other.
   - **An order never makes a kill.** PHPUnit warns, and so exits with the
     failure a mutant counts as a kill, when an option is given twice, when
     two options contradict, or when it is asked to order by defects with its
     history not recorded. So before it adds its own, the rewrite drops every
     `--cache-directory`, `--order-by`, `--record-test-run-history`,
     `--do-not-record-test-run-history`, `--cache-result` and
     `--do-not-cache-result` the process was started with, Pest's own among
     them. Where the plugin wrote no order for a mutant, because there was no
     plan, no opening run's map, or the process is no mutant's, the arguments
     are left as they are and the tests run in Pest's own order. A contract
     test proves that a survivor stays one and a kill a kill with each of
     those options.
   - **Infection.** Infection keeps its own fastest-first order, and the gate
     passes it nothing. `plan` says once, under Infection, that
     `killers-first` records killers and leaves the order to Infection.

4. **Ordering is treated as something that can change a result.** With
   `--bail`, a mutant is killed when some covering test fails. In a suite whose
   tests do not depend on each other's order, a new order changes when that
   happens and never whether. In a suite whose tests do, any new order can move
   a result, as adding a test can.
   - `tests.order` affects results, so it is in the proof key (ADR-0007
     decision 2.3). The history is not: it decides only the order.
   - Survivor confirmation (ADR-0008 decision 3) runs with no history, in
     the runner's own order. A survivor that the new order made shows up
     there as flaky.
   - `triage` takes `--order=runner|killers-first`, `tests.order` by
     default, to hunt a kill the order made.

5. **Where it lives in the code.** `Core` holds the history and the pure
   order over it. The mutation request carries the order as one more field,
   and each mutant record carries `killedBy`. The Runner port's methods do
   not change: each adapter records the killer, and honours the order where
   its runner allows. The Runner contract suite asserts both.

6. **`shards.target` sets the count from a target wall time.**
   - **The rule.** When `shards.target`, a duration, is set, the count is
     the smallest n for which a shard's overhead plus the mutation cost over
     n fits in the target, and at most `shards.max`. The cut within that
     count is ADR-0006's: path order, equal shares.
   - **One rule at a time.** Unset, `shards.seconds` decides as before. A
     config that sets both stops with exit code 2, naming both keys.
   - `shards.target` judges or reports only, like the other `shards` keys
     (ADR-0007 decision 2.3).

7. **A shard's overhead is its measured opening run plus a configured
   setup.**
   - **The opening run.** Each shard's result records how long its runner's
     opening run took. The ledger keeps the newest figure per package and
     runner, in an `openings` section (ADR-0007 decision 3). With the Pest
     patch the opening run is the canary group (ADR-0004), so it is short.
     With no measurement, it is the coverage run's own duration.
   - **The setup.** Checkout and `composer install` run before the gate
     starts, so the gate cannot time them. `shards.setup`, a duration, `1m`
     by default, stands for them.

8. **A target the plan cannot meet is a warning.** When the target needs more
   than `shards.max` shards, or the overhead alone exceeds it, the plan cuts
   `shards.max` shards and warns. The warning gives the expected wall time
   and the count the target would need, in the console, the step summary and
   the PR comment. Costs decide placement only (ADR-0006 decision 4), so a
   missed target never changes a verdict.

9. **The target applies to estimates too, and never becomes a budget.**
   - A unit with no timing is estimated from lines of code (ADR-0006
     decision 4), and the target applies to that estimate as it applies to
     measurements. The plan prints what share of its cost is estimated.
   - `--shards=<n>` wins over the target, with a notice when a target is set,
     as on CircleCI, whose plan always passes it. GitHub's limit of 256 jobs
     per matrix stays a cap.
   - The target is never turned into a per-shard `budget`. A shard runs to
     the end. A wrong estimate makes a slow shard, never an unjudged mutant.

10. **A survivor that compiles to the original program is *equivalent,
    proven*.**
    - **The check.** For each survived mutant, a child `php` compiles the
      original file and the mutated file with opcache
      (`opcache.enable_cli=1`, `opcache_compile_file()`), and dumps their
      optimized opcodes (`opcache.opt_debug_level=0x20000`). With line
      numbers stripped, identical opcodes prove the mutant equivalent. The
      mutated file is Pest's `Mutation::$modifiedSourcePath`, or
      Infection's `mutatedSourceCode` in `logs.json`.
    - **Survivors only.** A killed mutant is never checked, so a kill can
      never become a pass. A test that reads source text rather than running
      it, such as a Pest `arch()` test, had its chance before the mutant
      survived. A proven mutant is not re-run for survivor confirmation
      (ADR-0008 decision 3), which is the time it saves.
    - **Scoring.** A proven mutant is left out of the score as an ignored
      one is (ADR-0003 decision 1). It is listed in every report as
      *equivalent, proven* (ADR-0009).
    - **Where it runs.** The check is applied at verdict time, as ignores and
      timeout triage are, by an adapter the `Cli` owns and injects into the
      verdict flow, like its file adapter. It is not a port. The raw record
      stays *survived*, so the check enters neither the proof key nor the
      ledger, and a new PHP or a new gate version changes no proof. It is
      recomputed each verdict, because survivors are few.
    - **The first step of the build proves** that on PHP 8.5 a child `php`
      with `opcache.enable_cli=1` and `opcache.opt_debug_level=0x20000`
      dumps the optimized opcodes of a file through `opcache_compile_file()`,
      and that an original and a mutant the optimizer makes identical dump
      the same once line numbers are stripped. If it does not,
      `equivalence.static` is not added, and no mutant is proven equivalent.

11. **`equivalence.static` turns the check on, and it is on by default.** It
    is a boolean, `true` by default, and it judges or reports only
    (ADR-0007 decision 2.3). Where opcache cannot be loaded, the verdict
    prints *no mutant was checked for equivalence: opcache is not
    available* and scores as it would with the check off.

12. **An ignore that a proof makes redundant is not stale.** An
    `ignores.entries` item that matches a mutant proven equivalent still
    applies, and a notice says *proven equivalent; this ignore can go*. It
    never fails the run (ADR-0008 decision 4), so a PHP upgrade that changes
    the optimizer cannot turn a clean run red.

13. **A run without credentials reads the default branch's proofs from a
    public URL.**
    - **The setting.** `proofs.store.with.publicUrl` (`s3`) is an
      `https://` base from which `<scope>/ledger.json.gz` is fetched with an
      anonymous GET, whenever the store has no credentials.
    - **The bucket policy.** The README gives it: public `GetObject` on
      `<prefix>/refs/heads/<default branch>/*` and nothing else. A read of
      any other scope is then refused, and reads as an empty ledger. That
      costs a run, never a verdict (ADR-0007 decision 3).
    - **Writing.** Writing still needs the credentials only default-branch
      runs hold (ADR-0007 decision 5).

14. **A store with no write credentials opens read-only.** It says once:
    *read-only: no credentials; this run's proofs are not kept*. The run
    proceeds, and `proofs.write: auto` covers it. There is no separate test
    for "this is a fork": a fork's run is a run without credentials. On
    GitHub's cache, a fork's run still saves its own merge-ref scope, which
    only its re-runs read.

15. **A fork cannot plant a proof that anyone else trusts.** It cannot write
    the default branch's scope on any store: GitHub's cache refuses it, and
    S3 needs credentials a fork never gets. It cannot write another pull
    request's scope either. It can influence only its own verdict, which it
    could do anyway under `pull_request`, whose workflow comes from the pull
    request's own merge commit. A fork's verdict is therefore as trustworthy
    as GitHub's fork policy makes any required check. The README says to
    require approval before outside contributors' workflows run.
    `pull_request_target` stays unsupported (ADR-0009 decision 3).

16. **The documented GitHub schedule runs twice a week** (`'0 3 * * 1,4'`).
    Every restore refreshes a cache entry, so a quiet week keeps the default
    branch's ledger. ADR-0005 decision 6 asks for a full run at least weekly,
    and this meets it.

17. **1.0.0 is tagged when every feature the README lists is built.** The
    features this ADR decides, and those of the ADRs that follow it, join the
    README's feature table and the first release. ADR-0011's release rule
    counts no features: it names the README's list.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Every killer, by re-running each killed mutant without stopping** | Costs close to a second mutation run, and Pest's `--bail` is hard-coded in its child. Ordering needs only the first killer. |
| **Ordering by coverage alone, fastest first, with no kill data** | That is what Infection already does, and not what the feature asks for. |
| **The history kept per line** | Every edit above a line shifts it and empties the history below. |
| **The history kept per file** | The killers of a file's other functions would run first. |
| **Patching Infection to take the gate's order** | A vendor patch on a 0.x dependency, re-checked on every minor release, for a speed-up Infection's own fastest-first order already partly gives. |
| **One order for the whole suite** (a shared history of the best killers) | Not per mutant, and concurrent children would race to write one history file. |
| **Confirming every kill whose killer came from the history** | Re-runs most kills. ADR-0008 already rejected doubling a run's cost to hunt flaky kills. |
| **`killers-first` off by default** | Few projects would ever get the speed. The risk it adds is the order dependence a suite already has. |
| **A boolean `speed.likelyKillerFirst`** | Cannot grow another order, and opens a namespace nothing else uses. |
| **A new Runner method for ordering** | A port change for what is one more field of a request. |
| **As many shards as `shards.max` allows** | Fastest, and wasteful: twenty runners each pay setup for a small pull request. |
| **Reinterpreting `shards.seconds` as the target** | Silently changes the meaning of an Accepted default. |
| **Learning CI setup from the CI's API** | Exact on GitHub, and one question for the CiPlan port that only one adapter can answer. |
| **Ignoring per-shard overhead** | Over-shards small runs, which is the waste the setting removes. |
| **Exit 2 when the target cannot be met** | A slow week would fail CI over a planning preference. |
| **Keeping `shards.seconds` while most of the cost is estimated** | Two rules active in one project, depending on what the ledger holds. |
| **A per-shard budget of the target less overhead** | Every misestimate becomes unjudged mutants and a failed run, which ties verdicts to cost estimates. ADR-0006 decision 4 rules that out. |
| **A table of AST patterns known to be equivalent** | Each pattern is a proof somebody has to write, test and keep true. |
| **PHPStan type facts** | Brings PHPStan and the project's own PHPStan config into the verdict. |
| **Skipping proven mutants in later Infection runs** through generated `ignore` lines | Infection's `ignore` matches by class, method and line, so it can drop another mutant of the same mutator on that line, which would then never be judged. |
| **Generating mutants in the gate, to prove them before a run** | The gate would need a mutator engine matching each runner's exactly. ADR-0001 leaves mutating to the runner. |
| **Counting a proven mutant as survived and only marking it** | A floor of 100 would stay out of reach for code with a genuine equivalent. |
| **A port for equivalence provers** | A public interface for a feature with one known implementation. |
| **Recording *equivalent* in the proof** | Puts a gate judgement into the raw record, which ADR-0007 decision 1 keeps free of judgement. |
| **`equivalence.static` off by default** | Nothing has shipped, so no one has a score an upgrade could surprise. |
| **Treating a redundant ignore as stale** | Couples CI to opcache's optimizer: a PHP upgrade could fail a clean run. |
| **Publishing the ledger to the `mutation-gate` data branch** | A ledger of up to 20,000 proofs would be rewritten into git history on every default-branch run. |
| **The last default-branch run's artifact, through the Actions API** | GitHub only, where the cache already serves forks, and artifacts expire. |
| **Fork detection in the CiPlan port**, forcing `proofs.write: never` | A Core and Port change per CI for what a missing credential already says, and it would stop a fork's harmless re-run cache on GitHub. |
| **Signing the default branch's ledger** | The bucket's own access control already covers a public mirror. Readers of the mirror are runs whose trust matters only to themselves. |
| **Releasing these features after 1.0.0**, with ADR-0011's count unchanged | The maintainer chose one first release holding every accepted feature. A count in the release rule would go stale with each new ADR. |
| **Keeping the documented schedule weekly** | After a quiet week, the next pull request re-mutates everything it cannot carry. |

## Consequences

**Killed mutants finish sooner under Pest.** Their likely killer runs first,
and Infection keeps its own fastest-first order. Every run also learns who
kills what.

**The shard count follows a wall time the team chose.** A one-file change gets
one shard, and a full run gets as many as the target needs, within
`shards.max`.

**A genuine equivalent can stop costing a floor without an ignore.** Its
survivor is relabelled, never a kill, so the check cannot pass a mutant that a
test could catch.

**A fork's pull request can carry the default branch's proofs** on every store
with a public read URL, and on GitHub's cache as before, and can plant nothing.

**The ledger grows two sections,** `killers` and `openings`. Both are disposable
like timings: losing them costs speed, never a verdict.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): *equivalent, proven* in the status table
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the plugin's jobs in a mutant's child
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): the scheduled full run
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the shard count and the cost model
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the key, the ledger's new sections and the S3 store
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): survivor confirmation, `triage` and stale ignores
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): how reports show *equivalent, proven*
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the release rule, which names the README's list
