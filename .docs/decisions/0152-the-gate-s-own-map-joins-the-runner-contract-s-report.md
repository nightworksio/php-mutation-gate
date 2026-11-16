# ADR-0152: The gate's own map joins the runner contract's report

**Status:** Accepted
**Date:** 2026-11-15
**Amends:** [0067](0067-the-newest-pull-requests-are-planned-again-so-a-plan-that-widens-says-why.md): the job whose map `replay` plans with

## Context

The gate's own CI read its map from the `tests and coverage` job's PHP
report alone. That job runs the Contract suite without the runner contract's
fixture library, so every Contract test that runs the fixture is skipped
there, and the map names no test for the lines only those tests run: the
Pest adapter's split stream, its fresh runs and its served children among
them. The plan reads those lines as uncovered, and their mutants are never
judged by the tests that run them.

The `coverage` job already runs Pest's highest runner contract leg under
coverage, every PHP process its tests start recording its lines
(`covered.sh`), and reads the `tests and coverage` job's PHP report for the
floor (G7).

## Decision

1. **`coverage` builds the map.** It joins the suite's PHP report, which
   `tests and coverage` hands it as the artifact `report-of-tests`, with the
   runner contract leg's (`process-coverage.php joined`), reads the joined
   report with `mutation-gate coverage --from`, and uploads the map as
   `mutation-gate-map`. `mutation` and `replay` wait for `coverage` and take
   the map where that step succeeded; `mutation` runs without it otherwise,
   and its plan runs the suite under coverage itself.
2. **A test of a later report stands in for the same test of an earlier
   one.** A Contract test skipped in the suite's run and run in the leg's
   is timed as the leg ran it, and its lines are the leg's.
3. **The leg's report names the test that started each process.**
   `covered.sh` merges the lines each process recorded into the report as
   the test's own (`process-coverage.php tested`), as the suite's run does.
4. **The joined report is read in `coverage`, which holds no secret and no
   token that can write.** ADR-0006 reads a PHP map only in the job that
   wrote it, since reading it runs it; `coverage` runs the project's code
   already, reads the suite's report for the floor, and hands on only the
   gate's own map, which reading never runs.
5. **Pest's lowest leg runs in `coverage` too, for the floor alone.** Once
   the map is uploaded, `coverage` runs the leg against Pest's and PHPUnit's
   lowest releases under `covered.sh` into a directory of its own, and merges
   what it collected into the floor (G7), as it merges the highest leg's. A
   line that runs only against a release below the newest, such as one
   gated on PHPUnit below 13.2, counts as run. It joins no map: every shard
   installs the fixture's lock, so no mutant's tests run against the lowest
   releases.

## Why no verdict moves

- **Each line keeps every test that ran it.** Joining adds the leg's tests
  to a line; it removes none of the suite's.
- **The map the shards read is still the gate's own.** Only `map.json.gz`
  leaves `coverage`.

## Alternatives considered

| Alternative | Why not |
|---|---|
| Install the fixture in `tests and coverage` | The leg runs the Contract suite at the fixture's pins with the leg's settings; running it twice would double the slowest suite. |
| Join two gate maps instead of two PHP reports | The gate reads one coverage report per map; a second format for joining maps would be a feature for one caller. |
| Hold the fixture's units out of the verdict | A survivor that only the runner contract can kill would pass by exemption. |

## Consequences

- `mutation` starts once `coverage` has run both legs, minutes after
  `tests and coverage` ends; the early caller (ADR-0135) waits for neither.
- A red floor in `coverage` withholds no map: the map is uploaded before
  the floor is held.

## Related

- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md)
- [ADR-0067](0067-the-newest-pull-requests-are-planned-again-so-a-plan-that-widens-says-why.md)
- [ADR-0135](0135-a-full-split-stream-run-starts-without-waiting-for-a-fresh-map.md)
- [ADR-0151](0151-a-caller-prepares-what-its-tests-need-besides-the-dependencies.md)
