# ADR-0006: Shards are cut by learned cost, planned once, and rendered for any CI

**Status:** Proposed
**Date:** 2026-09-29

## Context

Mutation is the one gate whose work separates cleanly. Each unit's result is its
own, so its mutants can run on any machine. The companion spreads its gate over
a GitHub matrix:
- `mutation.php --list` prints the shards as JSON;
- each matrix job runs `--shard=<id>`;
- a final job, `mutation testing`, answers for all of them.

Shards are cut in path order into runs of about 600 seconds each. A file's
weight is its lines of code times a seconds-per-line figure for its path, and
those figures were fitted by hand to one CI run (`SECONDS_PER_LINE`, "fitted to
every shard of CI run 36163604500"). The seed replaces the hand fit with
measurement: `SecondsMeasured` shares a shard's time among its files by their
mutants' durations.

Two parts of that do not carry over to a public package.
- **Each shard recomputes the cut.** The companion's `--list` and `--shard` both
  derive the shards from constants in the script, so they agree. Learned
  timings move: if the planning job and a shard read different timings, they cut
  differently, and a shard runs somebody else's files or none.
- **It knows one CI.** The matrix JSON, `$GITHUB_OUTPUT` and the aggregating
  job are GitHub's. The approved scope is GitHub, GitLab `parallel:matrix`,
  Buildkite, CircleCI and a generic JSON plan.

## Decision

1. **Three commands, and one plan handed from the first to the others.**
   - **`mutation-gate plan`** does the preparation:
     - reads the config and the trees;
     - takes the coverage map, either by running the suite or from
       `--coverage=<dir>`, which an earlier test job wrote;
     - works out the reach (ADR-0005) and each considered unit's content key
       (ADR-0007), and drops every unit a proof already covers;
     - weighs the rest with the cost model and cuts the shards.

     It writes `.mutation-gate/plan.json` and prints the plan in the CI's
     format.
   - **`mutation-gate run --plan=<file> --shard=<id>`** mutates one shard's
     units and writes `.mutation-gate/results/<id>.json`. That file holds every
     mutant's record, each unit's content key and what the shard measured.
   - **`mutation-gate verdict --plan=<file> --results=<dir>`**:
     - requires a result file for every shard in the plan;
     - merges them with proved and carried results;
     - judges every tree and the new code (ADR-0003);
     - writes the reports (ADR-0009) and the updated ledger (ADR-0007).

   A shard never re-cuts. The plan records the commit it was made on, and a
   shard running on a different checkout stops with exit code 2. Locally,
   `mutation-gate` with no command does all three in one process.

2. **The verdict is the one check a branch protects.** It reads the result
   files, not the CI's job statuses. A shard that crashed, was cancelled or
   never started leaves no result file, and the verdict is then *cannot judge*
   (exit code 2), whatever the CI thinks of the job. A plan with no shards is a
   real answer: nothing is reached, or everything is proved. Its verdict judges
   the trees from proved and carried results and passes if they hold. This is
   the problem the companion's `mutation testing` job solves by reasoning about
   `needs.*.result`. Reading files removes the reasoning.

3. **A shard is a run of units cut to about the same cost.**
   - Units are weighed by the cost model and cut in path order into shards of
     about `shards.seconds` each (600 by default, the companion's
     `SECONDS_PER_SHARD`). The number of shards is the total cost over that size,
     rounded up. Each cut falls on the first unit that takes its shard past an
     equal share, and no shard is empty. This is the companion's
     `cutIntoRuns()`.
   - There are never more than `shards.max` shards, 20 by default. Past that
     limit, shards grow instead.
   - **Floors no longer separate shards.** The verdict adds up per-mutant
     results itself (ADR-0003), so units at different floors can share a shard.
   - **Packages still do.** A shard runs in one package's directory (ADR-0005).
   - **Held units run on their own.** A held unit is one runner invocation of
     its own, against its group. A shard with held units runs each of them, then
     one invocation for all its other units.
   - Each shard is labelled with the trees it takes, and "part 2 of 4" where a
     tree spans several shards.

4. **The cost model learns from every shard.**
   - **What a shard teaches.** A shard's mutation time is measured from the end
     of its opening run to its last mutant, and shared among its units. Each
     unit's share is in proportion to its mutants' durations:
     - from Pest, the durations the plugin records;
     - from Infection, which reports none, each mutant's stand-in, the JUnit
       time of the tests that cover its line.

     A unit's newest share replaces its older one. A unit that was proved
     rather than run keeps its last timing.
   - **Where timings live.** In the ledger (ADR-0007), keyed by unit path, with
     the runner and the time of measurement. They are not committed: they
     describe the CI's machines, not the code, and they change on every run.
   - **Cold start.** A unit with no timing is estimated as its lines of code
     times seconds per line. Lines of code counts lines holding a token that is
     not whitespace, a comment or the opening tag, as the companion's
     `linesOfCode()` does. Seconds per line is 0.2 by default, and
     `costs.secondsPerLine` maps path prefixes to figures of their own, the
     longest prefix winning, as the companion's table does. After one full CI
     run every unit has been measured, and the estimate is no longer used.
   - **Costs decide placement only.** A cost decides which shard a unit goes to,
     never whether its mutants run. A wrong cost makes one runner slower, never
     a verdict wrong.

5. **The CiPlan port renders a plan and says which shard a job is.**

   | CI | How the plan reaches it | Which shard a job is |
   |----|-------------------------|----------------------|
   | **GitHub Actions** | `shards=<JSON array of {id, label}>` in `$GITHUB_OUTPUT`, read by `strategy.matrix.shard: ${{ fromJson(…) }}`. The plan file travels as an artifact. An empty array skips the matrix job, and the verdict still runs. GitHub's limit of 256 jobs per matrix caps `shards.max` there. | The `--shard=<id>` the matrix passes |
   | **GitLab CI** | `parallel:matrix` must be in a pipeline before it starts, so `plan --ci=gitlab` writes a child pipeline. It holds one job with `parallel: matrix: [{SHARD: ["1", "2", …]}]` and a verdict job that needs it. Both fetch the plan with `needs: [{pipeline: $PARENT_PIPELINE_ID, job: <plan job>}]`, and both include the project's own job template (`ci.gitlab.template`) for image and setup. The parent triggers it with `trigger: include: - artifact: …` and `strategy: depend`, so the trigger job takes the child's result. | `SHARD` |
   | **Buildkite** | `plan --ci=buildkite` prints steps for `buildkite-agent pipeline upload`: one command step per shard, a `wait`, then the verdict step, each built from the project's step template (`ci.buildkite.step`: agents, plugins). Plan and results travel with `buildkite-agent artifact`. | The `--shard=<id>` in each step |
   | **CircleCI** | Parallelism is fixed in the config. The plan job runs `plan --shards=<N>`, with N equal to the mutation job's `parallelism`, and persists the plan to the workspace. | `CIRCLE_NODE_INDEX` (0-based). A `CIRCLE_NODE_TOTAL` that differs from the plan's count is *cannot judge*. |
   | **Generic JSON** | `plan --ci=json` prints `{"plan": "<digest>", "commit": "<sha>", "shards": [{"id": 1, "label": "…", "seconds": 540, "units": ["src/A.php", …]}]}` | `--shard=<id>` |

   `--shards=<N>` works on any CI and cuts exactly N shards. Trailing shards
   may be empty, and each says so and passes having run nothing. It is also how
   GitLab's plain `parallel: N` (`CI_NODE_INDEX`, 1-based, and `CI_NODE_TOTAL`)
   and Buildkite's `parallelism` (`BUILDKITE_PARALLEL_JOB`, 0-based, and
   `BUILDKITE_PARALLEL_JOB_COUNT`) are served. A CI the built-ins do not cover
   is an extension (ADR-0001).

6. **GitHub is wired by the package itself** (ADR-0011). The composite action
   runs the whole gate in one job, or one shard when given `shard`. The
   reusable workflow `.github/workflows/mutation-gate.yml` runs a plan job,
   one matrix job per shard and an aggregate job for the verdict. It carries
   the plan, coverage and results between jobs as artifacts, and keeps the
   ledger in the Actions cache.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Each shard recomputes the cut** (the companion's way) | Safe only while costs are constants in the code. With learned timings, a ledger saved between the planning job and a shard would make the two cut differently. |
| **Mutant-level sharding** | Neither runner can be given a list of mutants: Pest's and Infection's `--id` each take one. A file is the smallest thing both accept. |
| **Longest-first bin packing instead of path-order cuts** | Balances a few percent better. It scatters a tree over every shard, so a label says nothing and a shard's contents shift with every timing. Path order with equal shares is near-balanced when units are small against a 600-second shard. |
| **Timings committed to the repository** | Rewritten by every run, conflicting across pull requests, and true only of the CI's machines. |
| **A moving average of timings** | Smoother, and it lags a real change: a file whose tests got twice as slow is misplaced for several runs. The seed takes the newest measurement, and placement errors cost only time. |
| **A fixed `parallel: N` as the only model** | Cannot shrink to zero shards when nothing is reached, and holds N runners for a one-file change. It stays available as `--shards=<N>` for CIs that need it. |
| **Aggregating from the CI's job results** | A job that was skipped by design and one skipped because something upstream broke look the same, which is why the companion's aggregator needs a paragraph of conditions. A missing result file is unambiguous. |

## Consequences

**Every CI gets the same verdict from the same files.** Only how a plan is
rendered and how a job learns its shard differ.

**The first run is estimated and later runs are measured.** Shards balance
after one full run, with no figures to fit by hand.

**Proved units never reach a runner.** A pull request whose reached units are
all proved plans zero shards and costs one planning job.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): why floors no longer split shards
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): what the plan considers
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the ledger that holds proofs and timings
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the composite action and the reusable workflow
