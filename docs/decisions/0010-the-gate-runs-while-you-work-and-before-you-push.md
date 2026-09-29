# ADR-0010: The gate runs while you work and before you push, with the same verdict CI gives

**Status:** Proposed
**Date:** 2026-09-29

## Context

A survivor found in CI costs a push, a wait and a context switch. Found on the
developer's machine a minute after the edit, it costs a test. The companion runs
the whole gate locally with `composer test:mutation`, which is all of it in one
process. That is right for a deliberate check and far too slow for the edit
loop.

Two local moments matter:
- **while editing**: survivors in the code just changed, within seconds to a
  minute;
- **before pushing**: the same judgement CI will make on the new code, in the
  time a push can reasonably wait.

The pieces already exist in the design:
- reach from uncommitted changes (ADR-0005);
- a new-code floor (ADR-0003);
- proofs keyed on content as it is on disk, which work in a dirty tree
  (ADR-0007);
- a time budget that takes the riskiest code first (ADR-0008).

## Decision

1. **`mutation-gate watch` re-judges what each save reaches.**
   - **Watching.** It polls the source and test directories once a second,
     comparing content digests rather than modification times. It needs no PHP
     extension, and a checkout or a formatter that rewrites files unchanged
     triggers nothing.
   - **On a change**, it works out the reach of the change since the last
     judged state (ADR-0005). It mutates the reached units riskiest-first under
     a budget of 60 seconds (`local.watchBudget`), and prints survivors,
     unjudged mutants, hints and reproduce commands (ADR-0009). Then it waits
     for the next change.
   - **A change that arrives mid-run** stops the run. Its judged results are
     kept and the new reach is planned.
   - **The coverage map** is built once at start. When a test or test support
     changes, the changed tests are run again under coverage and their entries
     in the map are replaced, so what a changed test reaches stays true. A
     change that reaches everything (ADR-0005, rule 1) rebuilds the map.
   - **Proofs.** Results go into the local ledger (`.mutation-gate/ledger`), so
     reverting an edit costs nothing: its key is proved already.
   - **Verdict.** Watch mode judges the new-code floor over everything changed
     since `HEAD`. It shows tree scores with carried results, but does not
     enforce them. It never writes the baseline.

2. **`mutation-gate pre-push` makes the judgement CI will make on the new
   code.**
   - **What it reads.** Git's `pre-push` hook input: one line per ref, giving
     local sha, remote ref and remote sha. For each pushed ref the base is the
     remote sha. For a new branch, which has no remote sha, it is the merge base
     with the default branch's remote-tracking ref.
   - **What it judges.** It runs change-scoped from that base (ADR-0005) under a
     budget of 5 minutes (`local.prePushBudget`). It judges the new-code floor
     and the floors of the trees the change reaches, with carried results
     completing each tree (ADR-0003).
   - **Exit code.** Non-zero blocks the push. That covers a survivor on changed
     lines, a tree below its floor, and unjudged mutants, because a run that ran
     out of time did not judge them (ADR-0008). The message says how to judge
     them: `mutation-gate run --changed-since=<base>`. Git's own
     `git push --no-verify` skips the hook, and the gate adds no bypass of its
     own.
   - **Proofs.** The local ledger makes a second push of the same code
     immediate.

3. **Installing the hook respects what is already there.**
   `mutation-gate hook install` writes a `pre-push` hook into the directory
   git uses, which honours `core.hooksPath`. The hook calls
   `vendor/bin/mutation-gate pre-push "$@"`. If a hook it did not write is
   already there, it changes nothing and prints the one line to add to that
   hook. `mutation-gate hook uninstall` removes only a hook it wrote. The
   command is an ordinary executable, so CaptainHook, GrumPHP or a Composer
   script can call it instead.

4. **Local runs share CI's code paths.** `watch` and `pre-push` are the same
   plan, run and verdict steps (ADR-0006), in one process, with the directory
   proof store and the console reporter. There is no local-only logic that could
   disagree with CI.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **File-system events** (`inotify`, `fsevents`) instead of polling | Need a PHP extension or a native helper per platform. Polling digests once a second is enough for a loop measured in tens of seconds. |
| **Watch mode re-running the full coverage map on every test change** | Minutes per save on a large suite. Re-covering only the changed tests keeps the map true for what changed. |
| **A pre-push hook that warns and lets the push through when time runs out** | Unjudged is never passed (ADR-0008). A generous default budget, and change-scoped reach, keep the hook fast. `--no-verify` is the deliberate way past it. |
| **A pre-commit hook** | Commits are frequent and often partial. Pushing is the moment code is offered to others, and CI judges exactly what is pushed. |
| **Overwriting an existing hook** | Destroys somebody's setup silently. Printing the line to add leaves them in charge. |
| **Local runs enforcing the baseline ratchet** | The baseline is raised deliberately with `baseline --write` or by a full run (ADR-0003). A watch session that rewrote it on every save would leave diffs nobody asked for. |

## Consequences

**Most survivors are found before a push.** CI then confirms rather than
discovers.

**The same verdict everywhere.** A push that passes the hook fails in CI only
through something the hook could not see: a flaky test, the other packages of a
monorepo, or a slower runner.

**The local ledger is disposable.** Deleting `.mutation-gate/` costs one full
local run and nothing else. The directory belongs in `.gitignore`, and `init`
adds it there.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): the new-code floor and carried results
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): reach from uncommitted changes
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): proofs over content on disk
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): budgets and unjudged mutants
