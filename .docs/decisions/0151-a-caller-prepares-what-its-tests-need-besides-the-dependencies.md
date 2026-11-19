# ADR-0151: A caller prepares what its tests need besides the dependencies

**Status:** Accepted
**Date:** 2026-11-15

## Context

The action and the reusable workflow install the project's dependencies with
Composer and then run its tests. Some suites need more than that install: a
fixture project of their own with its own `composer.json`, a generated file,
a second package manager's install. A test that finds what it needs missing
is skipped, or fails, in every job of the run that never prepared it, and a
skipped test kills nothing: every mutant only it reaches survives, and a
unit it holds (`tests.holding`) is never judged by it.

The gate's own Contract suite is one such suite: its runner contract tests
run a fixture project, `tests/Contract/Runner/fixture`, whose library is
installed apart from the gate's dependencies. The shards ran without it.

## Decision

1. **`prepare`, an input of the action and of the reusable workflow, names a
   shell command.** Empty, the default, runs nothing.
2. **Each job that installs the project's dependencies runs it right after
   them.** In the reusable workflow: `plan`, `shard` (through the action),
   `survivors`, `survivors-report` and `verdict`; in the action: every run
   but a delivery, which installs nothing of the project. A job that runs
   the tests then always finds what they need, wherever a mutant's tests
   run.
3. **It runs under `bash -euo pipefail`.** Any part of the command that
   fails fails its job, so a run never judges on a half-prepared tree.
4. **It runs with no secret and no token.** It is the project's code, run
   as its tests are (ADR-0029): `COMPOSER_AUTH` reaches only the step that
   installs the dependencies.
5. **The gate's own CI prepares the runner contract's fixture**:
   `composer --working-dir=tests/Contract/Runner/fixture install`, in both
   of its callers.

### Settings

| Input | Values | Default | Effect |
|---|---|---|---|
| `prepare` | a shell command | none | runs right after the project's dependencies install, in each job that installs them |

## Why no verdict moves

- **Nothing runs unless the caller names it.** An empty `prepare` leaves
  every job as it was.
- **It only adds what the tests need.** A test that ran before runs as it
  did; a test that was skipped for want of the install now runs and can
  kill.

## Alternatives considered

| Alternative | Why not |
|---|---|
| A Composer script the install runs | The install step holds `COMPOSER_AUTH` (ADR-0029), so whatever a script installs runs with the credentials; `prepare` runs without them. |
| Install the fixture in the test that needs it | Every test of every shard pays the install, and a test that installs reaches the network. |
| A setting in `mutation-gate.json` | The gate would be installed and its config read before the command could run, and the gate would run shell commands from its config; the workflow already names every command its jobs run. |
| Run it in the shards alone | The plan runs the suite under coverage where it is handed no map, the survivors run mutants again, and the verdict runs the project's code too; each would run without what the tests need. |

## Consequences

- A project whose tests need a second install names it once, in its caller,
  and every job that runs its tests has it.
- The command adds its own time to each job that installs the project.

## Related

- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md)
- [ADR-0029](0029-a-caller-passes-the-store-secrets-by-name.md)
- [ADR-0152](0152-the-gate-s-own-map-joins-the-runner-contract-s-report.md)
