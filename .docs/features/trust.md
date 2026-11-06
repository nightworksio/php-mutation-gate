# Why a verdict can be trusted

A gate that skips work has to be right about what it skips, and a gate that
counts kills has to be right about each one. Every shortcut the gate takes is
bounded by a rule that keeps a verdict from passing on something it did not
prove.

## A kill needs evidence

A runner reporting a mutant as killed is not enough
([ADR-0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md)):

- **A kill stands on evidence**: a test named as its killer (one that failed
  or errored, raised an issue the runner's configuration fails the run on, or
  whose dataset the mutant left empty), or a signal or a fatal error that
  ended its process.
- **A named killer is checked on the unmutated code**, served as its mutant
  was. A test that fails there too, or fails only because of how the runner
  serves a file, never counts as a kill. A file's kills are controlled
  together first, and one by one where that control fails
  ([ADR-0033](../decisions/0033-a-files-kills-are-controlled-together-first.md)),
  and a kill only erroring tests made counts only where its control passes
  ([ADR-0036](../decisions/0036-a-kill-only-errors-made-counts-where-its-control-passes.md)).
- **Any other kill is unjudged**, with the code its process exited with and
  the tail of what it printed. An unjudged mutant counts as not killed.

## Timeouts, memory and flaky tests

- **A timeout counts as a kill only where its tests, run unmutated under the
  same limit, finish within it**
  ([ADR-0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md)).
  Each mutant is held to a multiple of its own covering tests' time, and each
  test of its run to a multiple of that test's own
  ([ADR-0090](../decisions/0090-each-test-of-a-mutants-run-is-held-to-a-multiple-of-its-own-time.md)).
  Under `timeouts.mode: unjudged`, a timeout is unjudged instead.
- **A mutant out of the memory cap counts as a kill only where its tests,
  run unmutated under the same cap, finish under it**, measured alike for
  every runner
  ([ADR-0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md)).
- **A survivor is run once more, alone**, and one killed then is reported as
  flaky, not as killed, under `flaky.confirmSurvivors`, on by default
  ([ADR-0034](../decisions/0034-a-suite-whose-test-files-pass-alone-need-not-confirm-survivors.md)).

## What cannot be judged is never passed

- **A time budget runs the riskiest code first**, and reports whatever it
  left unjudged, which counts as not killed, rather than passing it
  ([ADR-0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md)).
- **A command the gate could not finish exits 2**, could-not-judge, which no
  CI reads as a pass
  ([ADR-0040](../decisions/0040-a-verdict-stands-only-on-what-it-can-stand-behind.md)).
- **The verdict follows only its own commit's plan**, and a shard's result
  stands only in its own place, so a result from another run or another
  shard never fills a gap.

## A skip is never wrong

A proof is a unit's result, kept under a content key: a SHA-256 over
everything the result could depend on
([ADR-0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)).
That is the gate's version, the configuration as it affects results, the
runner's identity and the PHP it runs on, what Composer installed, the
project's files outside its tests, what every test reads, and the unit itself
with the tests that cover it. A run skips a unit only where its key matches,
so any of these moving runs it again.

## Who may write what the default branch trusts

- **A ledger belongs to one scope**, a branch, and a run writes only its own.
  The default branch's ledger is written only by runs on the default branch.
- **A fork's pull request reads the default branch's proofs, read-only**, and
  cannot plant a proof anyone else trusts
  ([ADR-0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)).
- **A merge queue trusts no pull request's own proofs**: a `merge_group` run
  judges what will land
  ([ADR-0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md)).
- **The credentials that write a ledger run in jobs of their own**, apart from
  the project's code
  ([ADR-0039](../decisions/0039-the-jobs-that-hold-a-credential-run-on-a-runner-of-their-own.md)).

[Proofs and trust](../using/proofs.md) sets each store up so this holds.

## The gate holds itself to the same rules

php-mutation-gate gates its own pull requests with its own reusable workflow,
at a floor of 100
([ADR-0011](../decisions/0011-the-package-holds-itself-to-the-gate-it-ships.md)).
Each release's PHAR is signed with the release key and attested by GitHub, and
the action checks both before it runs one
([ADR-0051](../decisions/0051-the-action-runs-the-release-s-signed-phar-where-the-project-has-no-copy.md)).
