# Reading the report

What the gate's report shows, in plain words, for someone who has never done
mutation testing. Every *What is this?* in the HTML report links to a section
of this page.

## Mutation testing

Mutation testing checks your tests, not your code. The gate makes small
changes to your code, called mutants, and runs your tests against each one.
A good test suite fails on every change: if your tests still pass after `>`
became `>=`, they would also miss a real bug that made that change.

The report answers three questions:

- **What happened?** The verdict at the top, *Passed*, *Failed* or *Cannot
  judge*, and the sentences under it say why.
- **Is it fine?** Each count says in a word whether it is *Good*, *Needs
  attention* or *Bad*, with a sentence on why.
- **What do I do?** *What to do* says what has to happen for the run to
  pass, and each survivor says what test would catch it.

## Mutant

A copy of your code with one small change, such as `>` turned into `>=`, a
`true` turned into `false`, or a line removed. The gate makes many of them,
runs the tests that run that line against each, and counts what happened.

## Killed

**Good.** At least one test failed with the change in place: your tests
noticed it. Nothing to do.

## Escaped

**Bad.** The tests ran the changed code and still passed. A real bug in the
same place could slip through the same way. Each escaped mutant says what
test would catch it, such as *Add an assertion that fails unless this value
is `0`.* Write that test, then run the gate again.

`mutation-gate explain <id>` shows the mutant, its tests and their outcomes,
and `mutation-gate stub <id>` writes a failing test to start from.

## Untested

**Bad.** No test runs this code at all, so nothing would notice a bug in
it. Add a test that runs the line.

## Timed out

**Needs attention.** The change made the tests run far longer than usual,
such as by causing an endless loop. The gate counts it as caught, but each
one makes the run slower. Where the gate cannot tell a hang from a slow
test, it counts the mutant as escaped and says how to give it more time.

## Errored

**Needs attention.** The change crashed the test run instead of failing a
test. The gate counts it as caught, but a crash can hide whether a test
really checks this code.

## Left out

**Needs attention.** The score does not count it: an ignore in the config or
a marker in the code leaves it out, or the gate proved it behaves exactly
like the original. Check that each one is left out on purpose.

## Score

The share of counted mutants your tests caught, in percent. Higher is
better. Left-out mutants are not counted.
Each part of the code, a tree, has its own score, and so do the code your
change added and the code the security mutators reach.

## Floor

The lowest score a part of the code may have. A run fails when a score drops
below its floor, and the floor rises as the score does, so it never goes
back down. Where a part has no floor yet, `mutation-gate baseline --write`
writes one at its score.

When a score is below its floor, *What to do* says how many more mutants
your tests must catch to reach it.

## Carried

A result reused from an earlier run because nothing it depends on changed,
so this run did not test it again. A carried file shows its counts alone.

## The digest, for agents

`mutation-gate digest` says the last run's verdict one fact a line, for an
agent or a terminal, and runs nothing. It reads the report every run keeps
at `.mutation-gate/report.json`. Each line starts with a word that never
changes:

| Word | What follows |
|------|--------------|
| `mg` | The verdict and the project's score |
| `why` | Why the verdict is what it is |
| `next` | What has to happen to pass |
| `counts` | How many mutants came to each outcome, each with its grade |
| `set` | A part of the code, its score, its floor and its verdict |
| `prov` | The gate, the commit, the runner, how long the run took, and the baseline |
| `warn` | A warning |
| `@` | The file of the survivors below it |
| `escaped`, `untested` | A survivor: its line, mutator, what changed, its id and what test would catch it |
| `more` | How many survivors it left out |

`--survivors=<n>` or `--survivors=all` lists more survivors than the 25 it
lists by default. `--detail=<file>`, `--detail=<mutant id>` or
`--detail=<cluster id>` shows one of them in depth, and `--detail=legend`
says what each word above means. The `digest` report writes the same
overview to a file; the action and the reusable workflow upload it as
`mutation-gate-digest.txt` beside the HTML report.
