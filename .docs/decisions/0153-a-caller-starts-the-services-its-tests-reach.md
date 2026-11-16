# ADR-0153: A caller starts the services its tests reach

**Status:** Accepted
**Date:** 2026-11-15
**Grows:** [0151](0151-a-caller-prepares-what-its-tests-need-besides-the-dependencies.md): what a caller readies besides the dependencies

## Context

Some suites reach a server while they run: a database, a cache, a queue. A
test that finds no server is skipped or fails in every job of the run that
started none, and a skipped test kills nothing: every mutant only it reaches
survives, and a unit it holds (`tests.holding`) is never judged by it.

The gate's own suite is one such suite. A split stream that reconnects
(`splitStream.reconnect`, ADR-0147) copies a MySQL, MariaDB or PostgreSQL
database at each fork, and the tests of those copiers run against a real
server of each kind. The jobs of the reusable workflow started none, so they
were skipped in the plan and in every shard.

GitHub starts a job's service containers beside it from
`jobs.<job_id>.services`, which a reusable workflow cannot take from its
caller as YAML. A caller can hand it a string, which the job reads back with
`fromJSON`.

## Decision

1. **`services`, an input of the reusable workflow, holds the service
   containers as a JSON object**, in the shape `jobs.<job_id>.services`
   takes: each container by its id, with its image, its environment, its
   ports and its options. `{}`, the default, starts none.
2. **Each job that runs the project's tests starts them**: `plan`, `shard`,
   `survivors`, `survivors-report` and `verdict`, as
   `services: ${{ fromJSON(inputs.services || '{}') }}`. The jobs that hold a
   key or a token (`fetch`, the deliver jobs, `publish`) and `judge`, which
   runs none of the project's code, start none.
3. **The addresses reach the tests through `prepare` or the caller's own
   job.** A container's published port is fixed by its mapping, so the
   caller knows the address its tests read. `prepare` (ADR-0151) runs in
   each job that starts the services, before the tests, and can write the
   variables to `$GITHUB_ENV`, which every later step of the job reads.
4. **The gate's own CI starts MySQL 8.4, MariaDB 11.4 and PostgreSQL 17**,
   each image pinned by digest, in `tests and coverage` and through
   `services` in both of its mutation callers. `.github/test-services.json`
   holds them once; `what changed` hands them on as an output, and
   `.github/scripts/servers.py` writes the variables
   `tests/Support/Servers.php` reads.

### Settings

| Input | Values | Default | Effect |
|---|---|---|---|
| `services` | a JSON object, as `jobs.<job_id>.services` takes it | `{}` | the service containers each job that runs the project's tests starts beside it |

## Why no verdict moves

- **Nothing starts unless the caller names it.** The default starts no
  container, and every job runs as it did.
- **It only adds what the tests reach.** A test that ran before runs as it
  did; a test that was skipped for want of its server now runs and can
  kill.

## Alternatives considered

| Alternative | Why not |
|---|---|
| A step that runs `docker run` in `prepare` | Every caller writes its own wait for the server to be ready; services wait for each container's health check before the first step. |
| One input per kind of server | The gate would name every server a project might use; the JSON object takes any. |
| Services in the action | A composite action cannot start containers; only a job can. |
| An `env` input of the reusable workflow | `prepare` already runs in each of those jobs, before the tests, and writes what they read. |

## Consequences

- A job that starts services runs on a Linux runner with Docker, as
  GitHub's service containers need.
- Each container adds its start and health check to each job that starts
  it.

## Related

- [ADR-0029](0029-a-caller-passes-the-store-secrets-by-name.md)
- [ADR-0147](0147-a-stream-records-its-own-lines-and-holds-a-child-to-its-parent-s-steps.md)
- [ADR-0151](0151-a-caller-prepares-what-its-tests-need-besides-the-dependencies.md)
