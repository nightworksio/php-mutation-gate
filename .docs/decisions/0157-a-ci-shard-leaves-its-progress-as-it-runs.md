# ADR-0157: A CI shard leaves its progress as it runs

**Status:** Accepted
**Date:** 2026-11-15

## Context

A job whose hosted runner stops answering ends as "The hosted runner lost
communication with the server", with no log: GitHub keeps a step's log only
once the step ends. The gate says once a minute, in its log, how many
processes ended and run, what the machine has left of its memory, swap and
disk, which process has run longest, and how far its runner judged
(`src/Adapter/Process/Saying.php`). A lost runner takes all of that with it.

Shard 5 of the gate's own CI (`src`, parts 19 to 23) was lost at about 49
minutes in two runs in a row, 43712641293 and 43755388963, with nothing to
tell which mutant ran or what the machine had left. A shard already reaches
the run's artifact service with the job's runtime token, which the action
hands the gate alone in a file it removes once read (ADR-0134). An artifact
is readable through the REST API as soon as it is finalized, whatever
becomes of the job, and the service deletes an artifact by its name.

## Decision

1. **A CI shard leaves its progress as an artifact of the run every
   `shards.progress`,** a duration, 3 minutes by default, and the first one
   as it starts. `0s` leaves none. The setting only reports, so a change to
   it re-runs nothing.
2. **A process forked from the gate's leaves it,** so a gate blocked on a
   process it waits on, or busy reading a result, still reports. The
   reporter makes its own HTTP client, looks every second whether the gate
   still runs, and ends a second after the gate at most, by SIGKILL, so
   nothing the gate holds is let go twice.
3. **The artifact is named for the plan, the run's attempt, the shard and
   its number,** `mutation-gate-progress-<plan digest>-<attempt>-<shard>-<n>`,
   since an artifact's name is taken for the whole run (ADR-0134). Each one
   holds `progress.txt` in a stored zip, as `gh run download` unpacks it,
   and the reporter deletes the one before it once the next is finalized.
4. **It says what a lost job cannot:** how long the shard has run, what the
   machine has left, how many processes run and the fifteen that hold the
   most memory, each with its age, its parent and its name but not its
   arguments, and the gate's last thirty lines of its log, which name the
   longest-running process and the mutant it judges. The gate keeps each
   line it says in a file `MUTATION_GATE_SAID` names while the reporter
   runs, as well as in its log.
5. **A shard that ends deletes the last one.** The gate stops its reporter
   with SIGTERM once the shard ends, however it ends, and waits ten seconds
   at most for it to delete its artifact; so only a lost job keeps its
   progress, and a run that ends leaves none behind.
6. **The action hands every shard the artifact service,** not only one whose
   plan pulls its work or that watches a job, since every shard reports.
7. **A shard that cannot report runs as before:** outside CI, without a
   handed service, where `pcntl` is missing, or where the service refuses a
   write, it leaves no progress and says nothing of it.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Report from the gate's own loop | The loop is blocked whenever the gate waits on a single process or reads a large result; the forked reporter answers to a clock alone. |
| Upload with `actions/upload-artifact` in a step of its own | Steps of a job run one after another, so no step runs while the gate's does. |
| Keep one artifact per report | A five-hour shard would leave a hundred; replacing each keeps one per running shard. |
| Write the progress to the step summary or a job annotation | Both are kept only once the step ends. |
| Name each process with its arguments | A test's arguments may hold what a public repository's artifacts should not; the name, age and memory are what tell a runaway apart. |

## Consequences

- A lost shard leaves its last report, at most `shards.progress` old: the
  mutant it judged and what held the machine's memory.
- Every shard writes one artifact every three minutes and deletes one, a
  few kilobytes each, against the run's artifact quota for the minutes it
  runs.

## Related

- [ADR-0134](0134-shards-pull-the-work-left-instead-of-owning-a-fixed-part.md): the artifact service and the handoff of its token
- [ADR-0158](0158-the-gate-holds-every-process-it-starts-in-a-session-and-a-cgroup.md): what keeps a runaway mutant from starving the runner
