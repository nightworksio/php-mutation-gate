# ADR-0058: A workflow that runs the gate is read by the jobs that run it

**Status:** Accepted
**Date:** 2026-11-02
**Amends:** [0005](0005-what-a-change-reaches-is-what-is-mutated.md): its CI definition that runs the gate, in rule 1
**Amends:** [0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): its CI definitions in the key, and the key's format

## Context

ADR-0005's rule 1 has a change to the CI definition that runs the gate reach
every unit, unless it moved only comment lines. ADR-0007's key holds the
definition the same way. A GitHub Actions workflow holds many jobs, and only
some of them run the gate.

In this repository, `ci.yml` runs the gate through its `mutation` job, beside
about twenty other jobs. Every pull request that edited any of them ran every
unit:

| Pull request | What changed in `ci.yml` | Units run |
|---|---|---|
| #490 | a `site` job added, and the evidence job's `needs` | 2,160 of 2,160 |
| #489 | a check of the action's own copy, in `checks` | 2,160 of 2,160 |
| #524 | each gated job's `if:`, the `mutation` job's among them | 2,220 of 2,220 |

The plan printed no reason. The verdict, half an hour later, said
"`.github/workflows/ci.yml` decides how the gate runs, so every unit is
reached".

## Decision

1. **A GitHub Actions workflow that runs the gate is read by its shared keys
   and the jobs that run the gate.** The shared keys are every top-level key
   besides `jobs`, `name`, `run-name` and `concurrency`, which say what its
   runs are called and whether one waits for another. A job runs the gate
   where:
   - a step or the job `uses` the gate's action or reusable workflow (a
     `uses` naming `php-mutation-gate`, or a path that ends in
     `/mutation-gate.yml`);
   - or a step runs the gate's `run`, `plan`, `verdict` or `survivors`, the
     gate with no command, or `composer mutate`, the script `init` adds.

   Within such a job, its own `name`, `if` and `needs` are left out, with
   every line they hold. They say what the job is called, whether it runs
   and after what, and nothing of how it runs the gate. Every other job is
   left out whole. A step's own `name` and `if` stay.
2. **The rest of ADR-0005's rule 1 and ADR-0007's item holds on what is
   read.** Comment lines between nodes are left out, every other byte stays,
   and a pin stays. A change reaches every unit only where what is read
   moved, and otherwise reaches nothing, which the reason says: "changed
   only comment lines or jobs that do not run the gate".
3. **A definition is read whole where this cannot tell its jobs apart**: one
   with no top-level `jobs`, as GitLab's, Bitbucket's, Azure's, CircleCI's,
   Buildkite's and Jenkins' definitions have; one where no job runs the gate,
   as the gate cannot tell how; or one with a job id or a line between jobs
   that this reader cannot place.
4. **The key's format is `mutation-gate proof 8`**, since what its item for
   the CI definitions holds changed.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep the whole definition** | Any job's edit runs every unit: #490, #489 and #524 ran about 1,300 shard-minutes for no change to how the gate runs. |
| **Also keep the jobs the gate's job `needs`** | In `ci.yml`, `mutation` needs `tests`, which writes the coverage map the plan reads. The map is checked by its commit and its digest before the plan reads it, so how `tests` was edited moves no result the gate stands behind. Keeping it would put #524 back to every unit. |
| **Parse the workflow as YAML** | The reader bounds a GitHub workflow's jobs by their indentation, as ADR-0005's reader bounds values line by line, and reads whole what it cannot bound. |

## Consequences

- A change to a job that does not run the gate, to a gate job's `name`, `if`
  or `needs`, or to the workflow's `name`, `run-name` or `concurrency`,
  reaches nothing. #490's, #489's and #524's diffs reach
  nothing.
- A change to how the gate job runs the gate, such as an input it passes or
  a step it runs, still reaches every unit.
- Every proof of format 7 is read as none, once.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): what a change reaches
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the proof key
- [ADR-0054](0054-a-pull-request-runs-the-gates-its-change-can-move.md): this repository's CI, read job by job for its gates
