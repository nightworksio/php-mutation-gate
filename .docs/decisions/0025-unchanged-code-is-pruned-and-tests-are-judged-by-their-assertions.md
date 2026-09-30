# ADR-0025: Mutators that never let a mutant through are pruned on unchanged code, tests are judged by their assertions and by suite, and a surviving removal may suggest deletion

**Status:** Accepted
**Date:** 2026-09-30

## Context

The ledger knows every mutant's result per unit and per mutator (ADR-0007
decision 3). The kill matrix knows which tests cover and which kill
(ADR-0014). Four questions go unanswered.

- **Mutators that never let a mutant through still cost a run.** Runners take
  mutators per run, not per mutant: Pest's `--mutator` and the `mutators`
  block of Infection's config (ADR-0004). A mutator left
  out of a run generates no mutant, so its mutants' results have to come
  from somewhere, or the score's denominator changes without anyone seeing
  it. Offutt, Lee, Rothermel, Untch and Zapf showed that a few operators
  predict the full score (*An experimental determination of sufficient
  mutant operators*, ACM TOSEM, 1996). Here the selection is learned per
  project.
- **A test that kills nothing it covers is named, not explained** (ADR-0014
  decision 2). Research names the pattern: a *pseudo-tested* method is one
  whose whole body can be removed with every test still passing
  (Niedermayr, Juergens and Wagner, CSED 2016; Vera-Pérez, Danglot,
  Monperrus and Baudry, *Empirical Software Engineering*, 2019). *Checked
  coverage* asks whether an assertion depends on the code a test runs
  (Schuler and Zeller, STVR, 2013). The tests' assertions are in their
  tokens: PHPUnit's `Assert` methods and Pest's expectations.
- **A suite's share is invisible.** PHPUnit's `<testsuites>` and Pest's
  directories put every test in a suite. An ordinary run knows each killed
  mutant's first killer only (ADR-0014), and which suite that killer is in
  depends on test order (ADR-0013 decision 4), so what one suite kills alone
  is exact only under a full kill matrix (ADR-0014 decision 7).
- **A surviving removal is two possible findings.** A removed call that
  survives says only that no test observes the call: a missing test, or code
  nothing needs. *Extreme mutation* removes whole method bodies, and a body
  whose removal survives is pseudo-tested.

## Decision

### Pruning

1. **A pruned mutant's result is its last one, carried by mutant id.**
   - It comes from the unit's newest full result for the same unit content:
     the unit's own file digest must match. Its key may differ, because
     pruning serves units whose key moved through another file (ADR-0007
     decision 2.6).
   - A unit whose own content changed runs every mutator.
   - A carried pruned mutant is marked *carried (pruned)* in every report.
   - A unit result that holds carried pruned mutants is never written as a
     proof, as a budget-cut unit's is not (ADR-0007 decision 1).

   This extends the carried results of ADR-0003 decision 4 to the grain of a
   mutant, and amends ADR-0003 decision 4 and ADR-0007 decision 1.

2. **A mutator is pruned when it has let no mutant through lately.**
   - Per project and runner, a mutator with no survivor, flaky or unjudged
     mutant among its last `pruning.window` judged mutants (an integer, `500`
     by default), counted from full results in the ledger. *Killed by static
     analysis* (ADR-0020) counts as killed.
   - Only units whose content is unchanged are pruned (decision 1).
   - **Never pruned:** new code; changed units; units a sampled run samples
     (ADR-0020), so the estimate stays unbiased; and mutators tagged
     `security` (ADR-0021).

3. **The scheduled full run is the audit.** A run with no base prunes nothing
   (ADR-0005 decision 6). `pruning.audit`, a duration (`7d` by default), also
   forces every mutator on a unit whose newest full result is older than
   that, so no carried pruned result is older than a week.

4. **Pruning is configured, keyed and shown.**
   - `pruning.enabled` (a boolean, `true` by default) and `pruning.window`
     **affect results**: they decide which mutants run and which are
     carried, so both are in key item 3 (ADR-0007 decision 2.3).
     `pruning.audit` judges only.
   - The ledger gains a `survival` section: per runner, each mutator's counts
     of judged mutants and of those not killed, over its window. It is
     disposable like `killers`, and losing it disables pruning, never a
     verdict. The ledger's `mutators` list of names is unchanged. This
     amends ADR-0007 decision 3.
   - The console, the step summary, the PR comment and the JSON report say
     *pruned: 4 mutators on 212 units; 3,180 mutants carried from runs of the
     last 7 days*. The JSON report lists each pruned mutator with its window
     and its last survivor, where it ever had one.
   - The carried mutants' recorded times appear in ADR-0017's savings line
     as *pruning saved*. This amends ADR-0017 decision 11.

### Weak assertions

5. **A fixed table classifies assertions, read from a test's tokens.**
   - **Existence:**
     - `assertNotNull`, `assertNotEmpty`, `assertTrue(true)` and
       `assertNotSame(null, …)`;
     - Pest's `->not->toBeNull()`, `->not->toBe(null)`, `->toBeTruthy()`,
       `->not->toBeEmpty()`, and `->toHaveKey()` without a value.
   - **Shape:** `assertIsArray`, `assertIsString`, `assertInstanceOf`,
     `assertCount`, and Pest's `->toBeArray()`, `->toBeInstanceOf()` and
     `->toHaveCount()`.
   - **Value:**
     - `assertSame`, `assertEquals`, and Pest's `->toBe()` and `->toEqual()`;
     - a key or a property handed its value, such as `->toHaveKey('total',
       100)`;
     - snapshots, and expected exceptions, a test's `->throwsIf()` among
       them.
   - A test is **weak** when every assertion it makes is of existence or
     shape.
   - **Not assessed.** Some tests can't be judged from their own body, so
     they are not assessed:
     - a test that makes an assertion the table does not hold, such as a
       project's own;
     - a test that chains a call after `expect()` that is neither an
       expectation nor a change of subject (`->and()`, `->json()`), such
       as `->sequence()`, `->each(…)` or `->when()`, whose closures assert
       out of the scan's sight;
     - a test that calls, as a function, a helper its own file declares, or
       one a file that defines the runner declares, such as `tests/Pest.php`,
       since the helper may assert what the test does not;
     - a test that calls a method on `$this`, `self` or `static` that is
       neither an assertion nor one of PHPUnit's own methods of `TestCase`
       and `Assert`, such as a parent class's or a trait's helper, or a
       framework's.
   - A table test fails when a supported PHPUnit or Pest release adds an
     assertion the table does not classify.
   - **As built.**
     - `Core\Assertion\AssertionTable` holds the table, by name in any
       case, as PHP calls methods and functions:
       - every assertion of PHPUnit's `Assert` and `TestCase`, its
         `expect…` methods among them, and every Pest expectation;
       - `AssertionTableTest` reads the installed releases' methods and
         fails on one the table does not hold.
     - The table reads a `Call`: its name, and its arguments as written.
       - `assertTrue(true, 'reached')` is existence by its first argument,
         and `assertNotSame($cart, null)` by a `null` on either side.
       - A negated `toBeNull`, `toBeEmpty`, `toBeTrue` or `toBeFalse` is
         existence, whatever the same expectation is unnegated.
     - `TestAssertions` reads a file's tokens once, and holds each test's
       assertions:
       - a PHPUnit method by its name;
       - a Pest `it` or `test` by its description, with the calls chained
         after it, so a test's `->throws()` counts as a value;
       - a Pest test inside a `describe()`, however deep and however the
         name is qualified, is described by it, so a bare description finds
         only the test outside every one.
       A method with no body has no assertions.
     - `AssertionScan` reads the calls:
       - `assert…` and `expect…` as methods or functions, a qualified name
         such as `\PHPUnit\Framework\assertSame` by its last segment;
       - each call chained after `expect()`, where `->not` negates the
         expectation after it.
       A function or method declared inside a test's body, such as an
       anonymous class's, is not a call.

6. **A weak test is reported only beside the survivors it let through.** It is
   reported when it is a judging test of a survivor in the Return value,
   Literal, Arithmetic, Collection or Unwrap family, which an assertion of
   value would see. The report pairs the test with those survivors and the
   assertion of value that would kill them, on the enclosing function's
   result, and `stub` offers it (ADR-0015 decision 1).
   - **The assertion is written in the test's own style.** A PHPUnit
     assertion gets `$this->assertSame(<expected>, <subject>)`, and a Pest
     expectation gets `expect(<subject>)->toBe(<expected>)`. The style belongs
     to each assertion, not to the runner, since Pest runs PHPUnit classes
     too. A test that mixes both, such as a PHPUnit class that calls
     `expect()`, gets the style of its first weak assertion.
   - **As built.**
     - `Core\Assertion\Weakness` decides it over the verdict's survivors
       judged *survived*. A survivor's judging tests are those the kill
       matrix finds covering it that judge it: every test, or the group
       that holds its unit. Only a test the runner names is read, from the
       file its name gives, so a weak test is named by its file and
       description, its data set rows folded in (ADR-0014 decision 6).
     - `AssertionScan` tags each assertion with the `AssertionStyle` it
       reads it in, and that enum holds both suggestions. The subject is the
       enclosing function's call, such as `fits(…)`, or `…` outside any
       function.
     - `Cli\Flow\Judging` builds the kill matrix before it reads the
       trees, reads the test files it names through the project's
       `Directory`, and hands each survivor its finding with
       `TreeVerdicts::found()`.

7. **The finding lives in the tests report and the hint.** It is a third
   section of the `tests` report and of `mutation-gate tests` (ADR-0014
   decision 5), which never fail anything, and the survivor's hint names the
   weak assertion (ADR-0009 decision 7). It reports only. `Core` holds the
   table and the token read. This amends ADR-0014 decisions 2 and 5, and
   ADR-0009 decision 7.
   - **As built.**
     - `Core\Report\WeakAssertions` is the section's one home, and
       `TestsReport` writes it in all three forms. The JSON's `weak` lists
       each test once as `{test, assertions, survivors, assert}`, which
       `resources/tests.schema.json` describes. Markdown and the console
       head it *Asserts only existence or shape* with its count.
     - The hint's second sentence names the first weak test on one plain
       line, each assertion it makes once, and how many more tests are
       weak: *`tests/CartTest.php::it fits` asserts only `->toBeBool()`,
       which no change of value fails.*

### Score per suite

8. **A suite's score is what that suite alone kills.**
   - It is killed over counted, among the mutants that suite's tests cover.
   - It is **exact under a full kill matrix** (`--kill-matrix=full`, which
     Pest and the native runners of ADR-0023 and ADR-0027 give), and
     otherwise a **lower bound**, shown as *at least*, from first killers.
   - Suites are read from the PHPUnit configuration's `<testsuites>` and
     Pest's directories, so there is no key for them.
   - The console, the JSON report (`suites: [{name, covered, killed, score,
     exact}]`), the HTML report and the step summary show them. This amends
     ADR-0009 decision 2 and ADR-0014 decision 7.

9. **`run --suite=<name>` judges one suite's tests alone,** for a scheduled
   job, exactly under every runner. It narrows `judgedBy` as a group does, so
   its results are keyed apart from a whole-suite run's.

10. **Suite scores are reported, never judged.** A lower bound cannot gate
    soundly, so there are no floors per suite.

### Removable code

11. **A surviving removal suggests deletion only when four facts hold
    together:**
    - a Removed-call mutant survived and is covered, not uncovered;
    - the call's result is unused: it is a statement, not an expression;
    - every other mutant inside the called function's body, where that
      function is in the project, also survived, so the callee is
      pseudo-tested;
    - none of the judging tests is weak (decision 5), so the tests do assert
      things, only not this.

    The family's hint then adds: *No test depends on this call, and nothing
    its body does is checked either: if nothing outside the tests needs it,
    it can be deleted.* The gate suggests, and never edits or counts
    anything by it.

12. **The suggestion shows where hints show.** It is the hint's second
    sentence in every report that shows hints, and a *removable* marker in
    the JSON report and in `explain`. `stub` still offers the test and the
    ignore (ADR-0015 decision 5). It reports only, and `Core` decides it
    over the verdict's mutants, the enclosing and called functions with
    names resolved through imports (`Core\Php`), and decision 5's table.
    This amends ADR-0009 decision 7 and ADR-0014 decision 12.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Counting pruned mutants as killed** | A prediction written down as a result. |
| **Leaving pruned mutants out of the score** | The score's meaning would move with the pruning. |
| **Pruning per family** | A family holds strong and weak mutators alike. |
| **A separate audit command** | A second full run to schedule, where the scheduled full run already exists. |
| **Measuring weakness by running each covering test against a value-changing mutant** | Exact, and a new kind of mutation run for an insight report. |
| **Weakness inferred from survivors alone** | That is ADR-0014 decision 2's first tier, which names the test and not the assertion. |
| **Every weak test reported** | Many shape assertions are exactly what a test means to check, and a finding with no survivor behind it is noise. |
| **Separate mutation runs per suite as the only score** | Exact, and the cost times the number of suites. It stays as `run --suite`. |
| **The suite of each killed mutant's first killer** | Moves with `tests.order`, so it means nothing stable. |
| **Floors per suite, judged under a full matrix** | A gate that fails differently by runner, on a figure that is a bound under the others. |
| **Any surviving removal suggesting deletion** | It would suggest deleting half of every untested codebase. |
| **Never suggesting deletion** | Hides the dead code a pseudo-tested callee points at. |
| **A report of removable code of its own** | A second report beside `tests`, for what one sentence of a hint says. |

## Consequences

**Unchanged code costs less on every run,** with every pruned mutant shown
beside the run its result came from, and a weekly run that prunes nothing.

**A useless test is explained:** the report names the weak assertion and the
survivors it let through, and offers the assertion that would kill them.

**A suite's real share of the killing is visible,** exactly where a full kill
matrix exists and as a stated lower bound elsewhere.

**Dead code can be told from a missing test** where the gate's own records
say so, and nowhere else.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): carried results
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): how each runner takes its mutators
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): the scheduled full run that audits
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the key, what is recorded, and the ledger's sections
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the reports and hints
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the order first killers depend on
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): the tests report, the full kill matrix and `explain`
- [ADR-0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): `stub`
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): the savings line
- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): killed by static analysis, and sampled runs
- [ADR-0021](0021-mutators-are-written-once-and-first-party-sets-can-leave.md): security-tagged mutators
- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): the native PHPUnit runner and its full kill matrix
- [ADR-0027](0027-codeception-phpspec-and-testo-get-native-runners.md): the other native runners
