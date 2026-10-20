# ADR-0035: The reusable workflow runs on the runner its caller names, and takes the map another job made

**Status:** Accepted
**Date:** 2026-10-20

## Context

ADR-0011, decision 8 gives the reusable workflow the action's inputs less
`shard`. Each of its jobs ran on `ubuntu-latest`, written into the workflow.
A project could not run the gate on an Arm runner, a larger runner, or its own
runners, without copying the workflow.

GitHub picks a job's runner from the job's `runs-on`, before any step runs.
`runs-on` takes a label or a list of labels, and an expression in it can read
the called workflow's inputs.

A proof key holds the architecture the runner's PHP reports (ADR-0007), so
the plan, the shards, the survivors job and the verdict judge alike only on
one kind of machine.

ADR-0006, decision 1 lets the job that runs the suite under coverage hand the
plan its map. The workflow takes that map as an artifact of the run, which it
names in an input of its own, `coverage`.

## Decision

1. **The reusable workflow takes two inputs of its own besides the action's
   less `shard`**: `coverage`, the artifact that holds the map, and
   `runs-on`.
2. **`runs-on` is the runner every job of the workflow runs on.** It is a
   string: a label, such as `ubuntu-24.04-arm`, or a JSON list of labels a
   runner must all have, such as `["self-hosted", "linux"]`, read as a list
   where it starts with `[`. It defaults to `ubuntu-latest`.
   - Every job runs on it, not only the shards, so every proof of a run is
     keyed on one architecture, and a project with only its own runners needs
     no GitHub-hosted one.
   - Nothing names a runner in the workflow besides it.
3. **The action takes no `runs-on`.** It runs on the runner of the job that
   uses it, which that job's `runs-on` names, so that is where its caller sets
   it.
4. **The gate's own CI names its runner only by measurement.** The `runners`
   suite of `bench.yml`, on demand, plans the gate's own mutants once and runs
   the same three shards on `ubuntu-latest` and on `ubuntu-24.04-arm` at once.
   `ci.yml` names the Arm runner only where its shards are faster there.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **A runner for the shards alone** | The shards' proofs and the plan's would be keyed on two architectures, and a project with only its own runners would still need GitHub's for the rest. |
| **A `runs-on` input on the action** | A job's runner is chosen before its steps run, so the action could not act on it. |
| **Two inputs, one label and one list** | One string read as a list where it starts with `[` says the same thing with nothing to keep in step. |

## Consequences

- Changing the runner's architecture changes every proof's key once, so the
  first run after it mutates what it reaches without carrying proofs.
- A caller that names no runner runs as before.

## Related

- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): decision 1, the map handed to the plan
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): what a proof's key holds
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): decision 8, the reusable workflow's inputs
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): `bench.yml`
