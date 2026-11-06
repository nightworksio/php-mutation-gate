# ADR-0108: No shortcut stands on a result its tests or its evidence no longer hold

**Status:** Accepted
**Date:** 2026-11-06
**Amends:** [0025](0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md): item 1, which results pruning carries
**Amends:** [0062](0062-a-change-that-reaches-no-unit-keeps-the-verdict-it-is-read-since.md): when a verdict stands for a plan that runs no unit
**Amends:** [0014](0014-every-test-is-judged-by-what-it-kills.md): rule 17, for a run that fails before any test
**Amends:** [0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): which mutants are judged in parts, and that their survivors are screened
**Amends:** [0005](0005-what-a-change-reaches-is-what-is-mutated.md): rule 7, how modules are found

## Context

Four shortcuts could let a change through that the gate would otherwise
catch, and one could fail a run that passes.

- **Pruning checked only a unit's source and mutant set** (ADR-0025). A pull
  request that weakened or deleted the test that killed a mutant carried the
  old kill. The mutant that now survives never ran, and the verdict passed
  until the next full run.
- **A plan that runs no unit kept the verdict it was read since** (ADR-0062)
  even where its change reached units that earlier results proved. Those
  results may be older than the verdict that stands, which never judged
  them. A pull request that restored an old, weaker test could pass on them.
- **A run that failed before any test ran counted as a kill** with no killer,
  no ending and no control, in the PHPUnit-family runners and under
  Infection. A bootstrap that failed for its own reasons, such as a database
  that timed out, recorded kills nothing evidenced.
- **Modules were never found.** ADR-0005 rule 7 and ADR-0065 item 6 reach
  every tree of a module from a changed test inside it, but no layout the
  gate built held a module.
- **A heavy mutant's survivor skipped the screening** every other survivor
  gets. Parts that all pass make a timed-out mutant a survivor after the
  static check and the second run had gone by, so it went unchecked and
  could doom a run that static analysis would have passed. A mutant was also
  judged in parts where its silence limit or a test's own limit had stopped
  it, which no part judges sooner.

## Decision

1. **A proof records the digest of every test file that covers its unit**, as
   the coverage map the verdict read says, beside those that killed one of
   its mutants. **Pruning carries a result only where each of those files
   reads now as it did**, so a unit is judged whole again whenever a test
   covering it changes or goes. A result that records none, from before
   this, is held to its killers alone until a full run replaces it.
   Pruning stays on by default.

2. **A verdict stands for a plan that runs no unit only where the plan proves
   none it reaches.** Where the change reaches a unit that an earlier result
   proves, the run's own verdict judges that result.

3. **A run that fails before any test runs is a kill no test is named for.**
   Its ending is its evidence: it stands on a fatal error PHP recorded or a
   signal, and otherwise on an unmutated control of its covering tests, as
   rule 17 of ADR-0014 says for every unnamed kill. Infection's `errored`
   list is read the same way. A mutant that does not parse stays an error,
   the parser being its evidence.

4. **A module is every directory with a `composer.json` of its own inside a
   package that is not a package itself**, among the files version control
   lists. The layout that reach reads holds each one.

5. **A survivor a control leaves is screened as every survivor is.** After the
   controls, each mutant that survives there and did not before is proven
   equivalent where the settings ask, checked by static analysis, and run a
   second time, each step passing over the mutants already settled. A
   mutant is judged in parts only where `timeouts.most` stopped it, not its
   silence limit or a test's own limit.

## Alternatives considered

- **Check only the killers' digests.** A carried kill by a timeout, by static
  analysis or by a run that failed names no test, so a weakened test would
  still be carried for it.
- **Read what changed since each proof's commit from git.** It needs every
  file the proof's commit held, and a proof whose commit is gone could not be
  checked at all; the digests are already in the ledger.
- **Let a verdict stand where each proof it would keep is the newest of its
  path at the passing instant.** It is narrower but needs the default
  branch's history of every proof; refusing the shortcut costs one verdict
  job.
- **Leave a run that failed before any test unjudged.** Most such runs are a
  fatal error the mutant caused, and the kill stands on that.

## Consequences

**A weakened test is caught on the push that weakens it.** The units it
covers run every mutator, so a mutant it no longer kills survives in that
run's verdict.

**The ledger grows by one index per covering test file per proof.** Each
test file's digest is held once in the ledger's table of inputs.

**A monorepo's module reaches its own trees.** A changed test under a
module's directory reaches every tree of that module, as ADR-0005 says.

**A heavy mutant can be killed by static analysis or found flaky**, as any
survivor can, and one whose check could not run dooms no run.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): what a proof records
- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): survivors checked by static analysis
- [ADR-0065](0065-a-changed-test-file-reaches-what-its-changed-tests-run.md): item 6, a changed test in a module
- [ADR-0103](0103-a-doomed-shard-fails-fast.md): a survivor not yet checked dooms nothing
- [ADR-0106](0106-carried-survivors-are-judged-again-and-their-re-check-is-bounded-and-shared.md): pruning never carries a survivor
