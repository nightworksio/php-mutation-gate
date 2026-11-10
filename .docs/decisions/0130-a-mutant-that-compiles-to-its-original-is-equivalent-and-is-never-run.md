# ADR-0130: A mutant that compiles to its original is equivalent, and is never run

**Status:** Accepted
**Date:** 2026-11-10
**Amends:** [0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): decision 10, its survivors only, for a mutant `outbreak.so` compiled before its run
**Grows:** [0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): its change check, by a check of the whole function before the first fork

## Context

ADR-0013 proves a survivor *equivalent* after the run: a child `php -n`
compiles the original file and the mutated file with opcache, dumps their
optimized opcodes, and proves the mutant where the opcodes are its
original's, the declarations printed with every body taken out are alike,
the constant expressions the dump prints only in part are written alike,
and the mutant changes no function that holds a check the compiling PHP
answers (`function_exists`, `PHP_VERSION_ID` and the like). The check is
`src/Adapter/Opcache/Prover.php:20-27`. It runs on survivors only, so an
equivalent mutant has already cost every covering test before it is proven:
a survivor costs about nine times a kill.

A split stream (ADR-0117) forks a mutant at each covering test that reaches
its site, until a child kills it. Its change check skips a fork where a pure
expression's mutated value equals the original's at that execution
(ADR-0117 decision 3). A mutant whose function compiles to the original's
program never changes a value, but where its site is not a pure expression
the stream still forks it at every covering test, and every child passes.

`outbreak.so`, Outbreak's PHP extension, built with ext-php-rs, compiles a
program in the PHP that loads it. Loaded into a child of the PHP that runs
the tests, with the php.ini and the extensions the tests run under, it can
compare a mutant's op arrays with its original's before any test runs.
Compiling in a test's own process is unsafe: a compile error such as
`parent::` in a class with no parent ends the process.

The share of mutants this proves on the gate's own run is not measured. The
gate's own full run 42493050329 ended with 0 survivors of 35,645 mutants,
so the prover after the run proved none there.

## Decision

1. **`outbreak.equivalence`, `true` by default, proves a mutant equivalent
   before it runs.** Where `outbreak.so` ships for the platform and the
   tests' PHP minor, the gate starts a child of the PHP that runs the tests,
   with its php.ini and extensions and the extension loaded besides, once all
   mutants are planned. The child compiles each mutant's file and its
   original's, as the runner prints them, from their text, runs none of it,
   and answers one digest of each program's op arrays: the main script's,
   each closure's, and each function's and method's the compile declared.
   A mutant is proven where:
   - its op arrays are its original's, as PHP's compiler builds them, with its
     compile-time folding and without opcache's optimizer: every op whole but
     for its handler, its line included, the file's own path never read, every
     literal and constant expression compared by its exact value, where the
     opcache dump ADR-0013 reads prints an array, a float or a constant
     expression only in part;
   - the gate's PHP finds the declarations printed with every body taken out
     alike, as ADR-0013 decision 10 requires: a class constant, a property
     default, an enum case, an attribute, a modifier and a static variable's
     initial value.

   A program the extension cannot compile, or that holds a value its digest
   does not read, is not proven, and runs. The child stops at the first
   program it cannot compile, since a compile error can leave PHP's compiler
   in a state no later compile may trust, and the rest go to a new child.
2. **A proven mutant is never run.** It forks no child in a stream and takes
   no run of its own. Its raw record (ADR-0004) is `equivalent`, its evidence
   names the extension and its version, and no test is named its killer.
   The runner skips it as it skips a mutant static analysis rejected before
   its tests (ADR-0020), and the mutant is never offered to the analyser.
3. **The verdict scores it as ADR-0013 scores *equivalent, proven*:** left
   out of the score as an ignored mutant is (ADR-0003), and listed as such in
   every report (ADR-0009). `equivalence.static` decides only the check of
   survivors after the run, so a mutant proven before its run is equivalent
   whatever `equivalence.static` says.
4. **Within one PHP, the compiling PHP's answers are the tests' answers.**
   ADR-0013 never proves a mutant that changes a function holding
   `function_exists`, `extension_loaded`, `defined` or a `PHP_*` constant
   opcache folds, because its child runs under `php -n`. The extension
   compiles in the tests' own PHP, with their extensions and ini, and without
   the optimizer that folds those checks, so such a function is compared
   like any other.
5. **The key holds what proves it.** `outbreak.equivalence` affects results
   and is in key item 3 (ADR-0007). The extension ships inside the package,
   which the gate's release keys, and loads only into the child that
   compiles, so the runner's PHP, key item 4, is the same with it or
   without it.
6. **Two mutants that compile alike are judged once**, by the rule
   `mutants.duplicates` gives (ADR-0137): under `judge-once`, a mutant whose
   digest is that of a mutant made before it at the same file and lines, whose
   declarations printed with every body taken out are that one's, and that is
   not proven equivalent, is skipped, and judged as that one's twin.
7. **Each fork's state can be counted (H5).** Under `outbreak.forkStates`,
   `false` by default, a split stream (ADR-0117) loads the extension, and at
   each fork arms a watch on the call that reached the mutant's site, the
   nearest outside the gate, in the parent and in the child. As that call
   returns, each reads one digest of what it returned and of the properties
   of the object it ran on, and the run's log counts the forks whose two
   digests are equal. Static properties, globals and the state an internal
   object keeps out of its properties are not read, so the count is a
   measurement no verdict reads. A skip of such forks is built only where
   the count shows at least a fifth of forks alike.
8. **A streamed kill by a test that touched nondeterminism is judged
   again.** Under `outbreak.recheckTouchedKills`, `true` by default, a split
   stream loads the extension, and each child watches whether its test,
   after the fork, calls a function that reads the clock, randomness, the
   environment, another process or the network, or names a file outside the
   project. A kill by such a child is not taken from the stream: the mutant
   runs its own run, with its verification (ADR-0008). A kill by a test that
   touched none is trusted no more for it, since the extension cannot see
   every source, such as randomness in an extension's own C code; nothing is
   ever skipped because a test touched none. `false` is a loose value
   (ADR-0142), and the setting affects results (key item 3).

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `outbreak.equivalence` | `true`, `false` | `true` | affects results (key item 3) |
| `outbreak.forkStates` | `true`, `false` | `false` | decides how the gate runs; changes no result |
| `outbreak.recheckTouchedKills` | `true`, **`false`** | `true` | affects results (key item 3) |

### Why no verdict passes that should not

- **An identical op array is the same program.** Every input runs the same
  opcodes on the same values, and the declarations a body cannot show are
  compared besides. The comparison is ADR-0013's, made earlier and in the
  tests' own PHP.
- **A test that reads source text loses its chance.** A Pest `arch()` test,
  or any test reading a file as text, could fail on an equivalent mutant
  that is not run. ADR-0013 lets such a test try first. Here it does not,
  and the mutant is left out of the score where it would have counted as
  killed. Leaving a killed mutant out moves a score of `k` killed of `n`
  scored to `k − 1` of `n − 1`, which is never higher, so the trade can fail
  a run that passed and never passes one that failed. The `tests` report
  (ADR-0014) loses that kill for the test.
- **The record says what the run did.** ADR-0013 keeps *equivalent* out of
  the raw record, because its check is a judgement the verdict makes later,
  in another PHP. Here the run itself left the mutant unrun, and the record
  says so, keyed by the PHP and the extension that decided it.
- **Everything the extension does not prove runs.** Where the extension is
  missing, a program does not compile, a child crashes or runs out of
  `outbreak.timeout`, or the gate's declaration check differs, the mutant
  runs as today.
- **Without the optimizer, fewer programs are alike, never more.** Two op
  arrays the compiler builds alike are alike after any optimization, so
  leaving it out can only prove less.

### Where today's path stays

- Without `outbreak.so` for the platform and PHP minor (Windows and a
  thread-safe PHP have none), with `outbreak.enabled` or
  `outbreak.equivalence` off, without the patched Pest run that hands its
  mutants off (ADR-0020), or under Infection or the native runners, every
  mutant runs, and ADR-0013's check proves survivors after the run.
- Without `pcntl` the extension still compares before the run; only the
  stream falls back (ADR-0117 decision 7).

### Proof before it ships

- A contract suite of mutant pairs, the ADR-0013 prover's own fixtures among
  them, run in `outbreak / boundary` against the extension that commit
  builds: the extension proves no pair the prover does not, but where a
  compile-time check differs between `php -n` and the tests' PHP or the dump
  prints a value in part; and where it proves less, because the optimizer
  is left out, the pair is listed.
- A self-gate run with the extension, compared mutant by mutant with one
  without it on the same commit: every mutant the extension proved is
  either a survivor the prover proves, or listed with the test that killed
  it and why that test reads source rather than runs it.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep proving survivors after the run** | An equivalent mutant costs every covering test first, the most expensive kind of mutant to run. |
| **Prove before the run in the gate's PHP, with opcache's dump** | A `php -n` child runs another PHP's answers to compile-time checks, and its dump prints values in part. |
| **Compile in the test's own process** | A compile error ends the process, and with it the stream or the worker it serves. |
| **Record the mutant as survived and let the verdict prove it** | The verdict's `php -n` child does not prove a function that holds a compile-time check, so a mutant never run would count as a survivor. |
| **Count it as killed** | A prediction written down as a result (ADR-0025). |

## Consequences

- A mutant that compiles to its original costs one compile, in place of a
  fork at every covering test or a run of every covering test.
- A source-reading test no longer kills an equivalent mutant; such mutants
  are left out of the score.
- Every proof's key moves once, by the new setting in key item 3.
- The package ships `outbreak.so` for each supported PHP minor on Linux and
  macOS, beside the `outbreak` binary, each listed in `SHA256SUMS`.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): what *equivalent, proven* is left out of
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): the raw record
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): key items 3 and 4
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): how reports show *equivalent, proven*
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the opcache prover this moves before the run
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): the `tests` report
- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): the hand-off before the tests
- [ADR-0025](0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md): a prediction is no result
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): split streams and their change check
- [ADR-0118](0118-outbreak-s-core-vouches-for-and-slices-coverage-maps.md): Outbreak, `outbreak.enabled`
- [ADR-0137](0137-a-mutant-that-is-the-same-program-as-another-is-judged-once.md): duplicates judged once
