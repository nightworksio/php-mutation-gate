# ADR-0055: Each gated job runs for its own inputs, and each runner leg for its own test support

**Status:** Accepted
**Date:** 2026-11-02
**Amends:** [0054](0054-a-pull-request-runs-the-gates-its-change-can-move.md): its outputs, and the jobs a workflow change runs

## Context

ADR-0054 gives `what changed` four outputs. `code` starts every PHP gate at
once, and the PHP gates read different things:

- `checks` and `lowest dependencies` judge the program alone;
- `tests and coverage` also reads the documents its suites check, the action
  and the reusable workflow;
- `mutation` runs the action and the reusable workflow;
- `hook managers` installs the checkout without its suites.

So a change to `.docs/reference/` ran `checks`, `lowest dependencies`,
`phar` and `mutation`. A change to `.github/workflows/mutation-gate.yml`
alone ran neither the Docs suite, which reads it, nor the self-gate, which
runs it.

Every file under `tests/Support/` counted as shared by every runner. So a
change to one ran all sixteen legs of `runner contract` and the warm
workers. The legs' tests use 46 of the support's 179 files, and each leg
other than Pest's uses 16 to 22. The warm workers run in another project and
use none.

ADR-0054 rejected one output per job, since adding a job would mean adding
an output. One output that lists the jobs adds none.

## Decision

1. **`what changed` gives `jobs`, the ids of the jobs of `ci.yml` it gates
   that run**, as a JSON list, beside `runners`. Each gated job's `if:` reads
   its own id in `jobs`. Adding a gated job adds its id and its inputs to
   `scope.py`, and no output. `end-to-end.yml` takes `warm` and `hooks`, one
   input for each of its jobs.
2. **A job runs where one of its inputs changed, a script it runs, or its
   own text**, as `scope.py`'s table of inputs says. The table distinguishes:
   - **the program:** every path that is neither documentation, nor the file
     of a gate that reads no PHP (spelling, links, markdown, the changelog,
     the image, the benchmark), nor SonarCloud's settings, the action or the
     reusable workflow;
   - **what the suites read:** the program, the documents they check, the
     action and the reusable workflow.

   The gates read them as follows:

   | Job | Inputs |
   |-----|--------|
   | `checks`, `lowest`, `phar` | the program |
   | `guards` | the program and ARCHITECTURE.md |
   | `tests` | what the suites read, and SonarCloud's settings |
   | `alone` | what the suites read |
   | `mutation` | the program, the action and the reusable workflow |
   | `site` | as ADR-0038 says |
   | `runner contract` legs | as ADR-0054 says, with the test support below |
   | `warm workers` | as ADR-0054 says, without the test support |
   | `hook managers` | the program outside the suites |

   A test-only change still runs `mutation`. ADR-0005's rule 3 has a changed
   test reach every unit its tests execute, and the gate's own plan narrows
   it from there.
3. **A runner's legs run for the test support its own tests use**, which
   `.github/scripts/legs.json` lists by leg, beside each leg's fixture. Each
   leg checks its list: it asks Pest which tests its run selects, reads the
   support those files and the Contract suite's helpers name, transitively,
   and fails where the list differs. `leg_support.py write` lists them again.
4. **A workflow read job by job runs the jobs it adds or changes.** A changed
   job of `ci.yml` runs itself; a changed `runner contract` runs every leg;
   a changed `end-to-end` job runs the end-to-end job it is. A change to what
   every job of `ci.yml` shares, or to its scope job, runs every job; one to
   what every job of `end-to-end.yml` shares runs both of its jobs. ADR-0054's
   other rules for the workflows hold: a removed job, a comment or a job that
   runs on every pull request runs nothing, and a workflow the base cannot
   give runs every job.

A job skipped by its `if:` reports success, so a required check that a run
skips passes, as it does for a documentation-only change. A push, a schedule
or a run with no base runs every job (ADR-0019).

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Category outputs, `php` and `shipped` beside `code`** | Three outputs that overlap would still run some jobs for inputs they do not read, and each new kind of job would mean another output. |
| **Every leg for any test support** | One support file ran all sixteen legs. |
| **Read each leg's support from the test names its filter matches** | A filter matches test names, and most contract tests run under every runner by a dataset, so only Pest can say which tests a leg's run selects. |
| **Skip `mutation` for a test-only change** | ADR-0005's rule 3 has a changed test reach the units it executes, so a weakened test would go unjudged. |

## Consequences

- A change to the documents the suites read runs `tests`, `alone` and, for
  ARCHITECTURE.md, `guards`.
- A change to the action or the reusable workflow runs `tests`, `alone` and
  `mutation`.
- A change to the spelling, link, markdown, changelog, image or benchmark
  files runs no PHP gate.
- A change to a test support file runs the legs whose tests use it, and no
  warm workers.
- A test that starts using another support file fails its leg until
  `legs.json` lists that file.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): what a changed test reaches
- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): every gate on a push, a schedule and a run with no base
- [ADR-0054](0054-a-pull-request-runs-the-gates-its-change-can-move.md): the outputs and the workflow rules this amends
