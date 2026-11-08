# ADR-0117: A kill judged against its own unmutated stream needs no control

**Status:** Accepted
**Date:** 2026-11-08
**Amends:** [0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): its unmutated controls, for a mutant a split stream judged; [0036](0036-a-kill-only-errors-made-counts-where-its-control-passes.md): what vouches for a kill, for a mutant a split stream judged; [0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): decision 12, schemata in a run that splits its stream

## Context

A mutant's own run starts a process, or forks one from a booted worker,
loads the test files its covering tests need, and runs them with the
mutant in place. A kill there is a kill in that narrowed process, so the
gate proves it real afterwards: baselines run the narrowed set unmutated,
controls run a kill's tests unmutated (ADR-0008, ADR-0014, ADR-0033),
replays run a known order again (ADR-0036), and a doubtful kill runs again
with every test file. On the gate's own full run 42493050329 the
verification steps took 53% of 9.35 runner-hours: controls 27.0%,
baselines 19.2%, trials 5.3% and confirmation 1.5%. A killed mutant cost a
median 0.224 s, of which its test's own time is about a fifth.

Split-stream execution (King and Offutt's Mothra; Tokumoto et al., MuVM,
2016; Wang et al., AccMut, 2017) runs the unmutated suite once, and forks
the process where a test first executes a mutant's site. The child turns
that mutant on and finishes the test; the parent carries on unmutated. Every
mutant of a file is already compiled into the file's schema, each behind a
switch (ADR-0091), so the stream's program holds every site.

## Decision

1. **`splitStream.enabled` runs a shard's mutants in split streams**, under
   `pest.patch` and `pest.schemata`, `false` by default. A stream is a Pest
   run of test files, unmutated, every schema of the shard served in its
   file's place, as a booted worker serves them (ADR-0091). A mutant its
   file's schema does not switch, or one a mutator pins by source, runs its
   own run as before.
2. **A stream forks at a site's first execution within a test.** Where a
   test reaches the switch of a mutant still undecided, and the stream has
   not yet forked that mutant in this test, it forks. The child turns the
   mutant on and finishes only that test; the parent turns it off and goes
   on. A stream forks a mutant again in each later test that reaches it,
   until a child kills it. It holds at most `splitStream.children` children
   at once, 1 by default, waiting for one to end before it forks past that.
3. **With `splitStream.changeCheck`, true by default, a stream forks only
   where the mutant changes a value.** A mutant whose original and
   replacement are expressions of literals, constants, variables and
   operators that call no code of their own is switched by a call that
   evaluates both, the replacement under an error handler that keeps its
   errors, where the stream asks. Where both are identical, the test cannot
   tell this execution apart, and the stream forks at a later execution, if
   any, that changes the value. A mutant no test changed is reached, never
   infected, and survives without a fork.
4. **The parent is the child's control.** The child runs in the process the
   unmutated suite had at that instant: every test before it, every fixture,
   the same state. A test the child fails, errors or ends in, and the parent
   passes, kills the mutant, the test named its killer. A test the parent
   itself does not pass kills nothing, and the mutant waits for a later
   test. A child that passes the test leaves the mutant undecided.
5. **A child is held to `timeouts.perTest` times the parent's own time of
   the same test, never under `timeouts.testFloor` seconds** (ADR-0090),
   counted from the fork, or to `timeouts.most` where `timeouts.perTest`
   is `false`. A child past it is stopped, and its mutant times out at that
   test. The parent's time is the control: a timeout needs no other.
6. **A split stream's verdicts need no control.** A kill, a timeout or a
   survivor a stream judged runs no baseline, kill control, replay, trial,
   timeout control or confirmation, and its evidence says the stream judged
   it. A survivor survived every covering test the stream reached it in.
7. **A test the stream cannot fork in falls back.** No fork is made in a
   test that holds a socket the stream did not hold as it started, counted
   among PHP's streams and, on Linux, among the process's file descriptors,
   or that runs in a separate process. A mutant whose covering tests
   include such a test, and that no stream killed, runs its own run as
   before, narrowed to its covering tests, with its verification. Without
   `pcntl` and `posix` every mutant runs its own run, and the run warns.
8. **`splitStream.killersFirst`, true by default, forks each mutant first
   at the test the ledger last saw kill it** (ADR-0013). A mutant with no
   such test forks at every covering test. A mutant its killer did not kill
   runs in a second pass of streams of the test files that cover it, forking
   at every covering test.
9. **`splitStream.sharding` says what a shard's streams run.** `source`
   keeps the shards' parts of the source, each streaming the test files that
   cover its own mutants. `tests`, the default, cuts the test files into one
   part for each of a package's shards: each test file, by the file that
   declares a test's class, goes, longest first by the times of the
   coverage map the plan handed every shard and files of equal time by path,
   to the part with the least time so far, of those the fewest files, of
   those the lowest, so every shard cuts alike; where a test of the map is
   declared in no test file the shard loaded, the first part holds every
   test. Each shard takes every unit of its package and its own part, which
   the plan names, and streams the files of that part. In a shard:
   - a mutant no test of its part covers, whose first covering test another
     part holds, is not reached: a shard's own outcome, never a mutant's
     status;
   - a mutant no stream can judge runs its own run, by every covering test,
     in the one shard whose part holds its first covering test;
   - a mutant its part's streams leave for its own run runs it by the
     covering tests of that part alone, in that shard;
   - a survivor of the covering tests of its part alone dooms no run
     (ADR-0008), and only the shard whose part holds its first covering test
     checks it by static analysis or proves it equivalent.
   The verdict merges each mutant across the shards that hold its unit: a
   kill in any part wins, then a timeout; an own run's other answer stands
   over a survivor; a mutant survives where every part that reached it let it
   survive; a status every part gives alike merges to one; a mutant no part
   reached, or shards that hold other mutants than each other, cannot be
   judged. A unit counts as run where every shard that holds it ran it, and
   each shard's time of it adds up into its learned cost. With one shard,
   the one part is every test file. Within a shard, its test files are cut
   the same way into a stream for each process.
10. **`staticCheck.before` is `false` by default where a run splits its
   streams** (ADR-0077): a kill in a stream costs less than a check. A
   config that sets it keeps what it sets.
11. **ADR-0023 decision 12's "mutant schemata are not used" holds only where a
   run does not split its stream.** A split stream needs one program every
   mutant of the stream shares, which the schemata are.
12. All keys are in key item 3 (ADR-0007); `splitStream.enabled` decides
    how mutants are judged.

### Why a kill the stream judged needs no control

- **The same process, one switch apart.** Child and parent are the same
  process at the instant of the fork, and differ afterwards only by the
  switch the child turned on. A test that fails in the child and passes in
  the parent fails because of the mutant.
- **No narrowing.** A stream runs whole test files in the suite's order.
  What a narrowed run can lack, a helper a left-out file declares, state its
  loading sets, an order a test depends on, is there in both.
- **A failing test stays out.** A test the parent fails kills nothing in
  the stream, so a test that fails on its own never names a killer.

### What a stream does not see

- **State a mutated test leaks into later tests.** A child finishes only its
  own test, so a mutant whose damage shows only in a later test is not
  killed by that later test. A narrowed own run sees it only where both tests
  load together; a full stream that judges each test in its own child sees it
  in neither.
- **Code a subprocess runs.** A test's subprocess starts a fresh PHP, which
  serves the schema with no mutant on unless the gate's variables tell it
  one. The child puts its mutant in the variables a subprocess inherits.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep each mutant's own run, make the verification cheaper** | The verification is the price of narrowing, and every kill pays it. |
| **Fork every mutant at the start of each covering test** | The child would run the test from its first line with the mutant on, which is what a mutant's own run does, without the parent's state at the site. Forking at the site keeps everything before it unmutated in both. |
| **Run each child to the end of the suite** | A child would cost the rest of the suite, not the rest of one test, and a later test's failure would mix the mutant with the leak of every test before it. |
| **Fork at every execution of a site** | Executions after the first in a test run with the mutant already on in the child forked at the first. |

## Consequences

- A kill under split streams costs a fork and the rest of one test, not a
  process, a narrowed load and its verification.
- `splitStream.enabled` is `false` until a measured run of the gate's own
  source shows it faster than each mutant's own run at the same verdicts.
- Under `splitStream.sharding` `tests`, the fleet runs the suite once
  between its shards, not once for each shard's part of the source.

## Related

- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): a mutant's own run under Pest
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the killers the ledger keeps
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): kill controls
- [ADR-0033](0033-a-files-kills-are-controlled-together-first.md): a file's kills controlled together
- [ADR-0090](0090-each-test-of-a-mutants-run-is-held-to-a-multiple-of-its-own-time.md): each test's own limit
- [ADR-0091](0091-a-files-mutants-are-compiled-into-one-schema.md): mutant schemata
