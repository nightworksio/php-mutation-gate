# ADR-0189: A job starts its services in the background

**Status:** Accepted
**Date:** 2026-11-18
**Amends:** [0153](0153-a-caller-starts-the-services-its-tests-reach.md): when and how the reusable workflow's jobs start the services a caller names

## Context

The reusable workflow handed the caller's `services` to each job that runs
the project's tests, as `jobs.<job_id>.services`. GitHub starts a job's
services before its first step, pulling and starting each image one after
another, then waits for every health check. On the gate's own probe of three
shards, shard 9 spent 34 s on its MariaDB, MySQL and PostgreSQL containers
before its checkout began: three pulls in turn and a 6 s wait. Every shard,
the plan, the survivors' jobs and the verdict pay it, while the checkout,
the artifacts, PHP and the dependencies that follow need no service.

## Decision

1. **`services-start: background`, the default, has each job start its
   services itself, right after it checks the gate's workflow out,** before
   PHP, the dependencies or any of the project's code: every container at
   once, detached, from the same JSON object, with its `env`, `ports`,
   `volumes` and `options`, named for the run, its attempt and the job. The
   containers come up while the job downloads its artifacts and installs. A
   service with an empty image starts nothing, as in a job's `services`.
2. **No secret outlives that step.** It masks a private image's password and
   every service `env` value of four or more characters (a shorter one would
   mask every match in the log); it logs in and pulls a private image under
   a Docker config of its own in `RUNNER_TEMP`, removed whether the pull
   worked or not; and it waits for every start before it ends. What it
   leaves is a read-only list of the containers' names in `RUNNER_TEMP`;
   the runner's own Docker config is never written. A pull or a start that
   fails fails the job.
3. **Nothing of the project runs before every service reports healthy.**
   Right before the project's `prepare`, and before the gate, the job waits
   for each container the list names to report healthy, or to run where it
   has no health check, in a shell started with an empty environment
   (`env -i PATH=/usr/bin:/bin`, `bash --noprofile --norc`), so nothing the
   project's install wrote into the job's environment, such as `PATH` or
   `BASH_ENV`, reaches it, and it is told nothing of the services. One that
   exits, turns unhealthy, is gone or is not healthy within 300 s fails the
   job with its log, as does a missing list. The shard waits in the action,
   which the workflow tells to (`wait-for-services`).
4. **Each job removes its containers as it ends**, whatever it ended in, in
   the same clean shell, so a runner that runs the next job keeps none.
5. **`services-start: job` keeps the job's own start**: the job's
   `services` start them before its first step, as before this decision.
   Any other value fails the job.

## Why no verdict moves

The tests run against the same images, configured alike, published on the
same ports of the runner, and none runs before each service is as ready as
GitHub's own start leaves it: healthy where a health check is given, running
otherwise.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Keep GitHub's start (`services-start: job`) | Kept as the setting, for a caller whose steps read `job.services`. |
| Start the services only in the shards whose tests reach them | A shard's covering tests are known only once its Pest run reads the map; a database a test reaches only through the project's configuration is not visible to it. |
| Start them in the background and wait only where a test first connects | No step can tell when a test first connects; waiting before the project's code runs is the first point that is surely early enough. |
| Pull and start in a process that runs on beside the install | It would hold the services, a database's password among them, and a registry's Docker config, while the project's Composer scripts and plugins run. |
| Hand the wait the services | The wait runs after the install, whose code can read a step's environment and rewrite what later steps run with. |

## Consequences

- `job.services` holds nothing under the default; a caller that reads it
  sets `services-start: job`.
- The pulls run side by side, not one after another, and the containers'
  own start overlaps the job's downloads and install.

## Related

- [ADR-0153](0153-a-caller-starts-the-services-its-tests-reach.md)
