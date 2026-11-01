# ADR-0039: The jobs that hold a credential run on a runner of their own

**Status:** Accepted
**Date:** 2026-10-31

## Context

ADR-0035 runs every job of the reusable workflow on the one runner its
`runs-on` input names. Those jobs are of two kinds. The plan, the shards,
the survivors job and the verdict run the project's code, a pull request's
included, and hold no credential. `fetch`, `deliver-plan`,
`deliver-survivors`, `deliver` and `publish` run none of it, and hold a store
key, a token that may comment, or a token that may write the repository
(ADR-0007, ADR-0029).

A self-hosted runner that is not ephemeral keeps its disk, its processes and
its tool cache from one job to the next. A pull request's code that ran on it
can leave something the next job runs. Where that job holds a key, the key is
the pull request's.

None of the jobs that hold a credential keys a proof: they read, send and
publish what the verdict made, so the architecture they run on reaches no
proof's key.

## Decision

1. **The reusable workflow takes `keyed-runs-on`**, the runner `fetch`,
   `deliver-plan`, `deliver-survivors`, `deliver` and `publish` run on. It is
   read as `runs-on` is: a label, or a JSON list of labels where it starts
   with `[`. It defaults to `ubuntu-latest`.
2. **`runs-on` names the runner of the plan, the shards, the survivors job
   and the verdict**, the jobs whose proofs are keyed on its architecture.
   This supersedes ADR-0035's decision 2 for the jobs in decision 1.
3. **The guide says to name a GitHub-hosted or an ephemeral runner in
   `keyed-runs-on`** wherever `runs-on` names a persistent self-hosted one.
4. **`.github/scripts/test_workflows.py` holds it**: each job that holds a
   credential runs on `keyed-runs-on`, every other job on `runs-on`, and
   both default to `ubuntu-latest`.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Every job on `runs-on`, with a warning** | A project with persistent runners would hand a pull request's code the runner its keys run on until it read the warning. |
| **`keyed-runs-on` defaulting to `runs-on`** | The same default as the warning alone: the safe runner would be the one a project has to ask for. |
| **The action's own jobs on two runners** | The action runs on the runner of the job that uses it; its caller already names each job's runner. |

## Consequences

- A project that runs only on its own runners names one for `keyed-runs-on`
  too.
- A caller that names neither runner runs as before.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the jobs that hold the gate's keys
- [ADR-0029](0029-a-caller-passes-the-store-secrets-by-name.md): the secrets those jobs read
- [ADR-0035](0035-the-reusable-workflow-runs-on-the-runner-its-caller-names.md): which this supersedes for the jobs that hold a credential
