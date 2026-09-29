# ADR-0006: Shards are cut by learned cost, planned once, and rendered for any CI

**Status:** Accepted
**Date:** 2026-09-29

## Context

Mutation is the one gate whose work separates cleanly. Each unit's result is its
own, so its mutants can run on any machine. The in-house gate spreads its work
over a GitHub matrix:

- one invocation prints the shards as JSON;
- each matrix job runs one shard;
- a final job answers for all of them.

Shards are cut in path order into runs of about 600 seconds each. A file's
weight is its lines of code times a seconds-per-line figure for its path, and
those figures are fitted by hand to every shard of one CI run, with 0.2 for
paths that have no figure of their own. The seed replaces the hand fit with
measurement: it shares a shard's time among its files by their mutants'
durations.

Two parts of that do not carry over to a public package.

- **Each shard recomputes the cut.** The in-house gate's listing and each shard
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
       `--coverage=<dir>`, which an earlier test job wrote in the runner's
       layout (ADR-0004);
     - works out the reach (ADR-0005) and each considered unit's content key
       (ADR-0007), and drops every unit a proof already covers;
     - weighs the rest with the cost model and cuts the shards.

     It writes `.mutation-gate/plan.json`, keeps the coverage it used under
     `.mutation-gate/coverage/`, and prints the plan in the CI's format.
   - **`mutation-gate run --plan=<file>`** mutates one shard's units and writes
     `.mutation-gate/results/<id>.json`. That file holds every mutant's record,
     each unit's content key and what the shard measured.
     - The shard is `--shard=<id>` or, without it, the one the CI's
       environment names (decision 5).
     - It exits 0 once its result file is written, whatever its mutants did,
       because the verdict judges them. A runner's *cannot judge* is written
       into the result file with the runner's output, so the verdict reports
       it. The shard exits 2 only when it cannot write the file, or when the
       plan does not match the checkout or the CI's shard count.
   - **`mutation-gate verdict --plan=<file> --results=<dir>`**:
     - requires a result file for every shard in the plan;
     - merges them with proved and carried results;
     - judges every tree and the new code (ADR-0003);
     - writes the reports (ADR-0009) and the updated ledger (ADR-0007).

   A shard never re-cuts. The plan records the commit it was made on, the
   run's ref, whether it is a pull request and the default branch (decision
   5). `run` and `verdict` with a plan take all four from it, and a shard
   running on a different checkout stops with exit code 2.
   `mutation-gate`, or `mutation-gate run` without `--plan`, does all three in
   one process and takes the options of `plan`, and `verdict`'s
   `--publish-dir`.

2. **The verdict is the one check a branch protects.** It reads the result
   files, not the CI's job statuses. A shard that crashed, was cancelled or
   never started leaves no result file, and the verdict is then *cannot judge*
   (exit code 2), whatever the CI thinks of the job. Every pipeline this
   package generates or documents runs the verdict after a failed shard job
   too, so that answer is always given. A plan with no shards is a real answer:
   nothing is reached, or everything is proved. Its verdict judges the trees
   from proved and carried results and passes if they hold. The in-house gate's
   aggregating job reasons about its jobs' results to reach the same answer.
   Reading files removes the reasoning.

3. **A shard is a run of units cut to about the same cost.**
   - Units are weighed by the cost model and cut in path order into shards of
     about `shards.seconds` each (an integer, 600 by default). The number of
     shards is the total cost over that size, rounded up. Each cut falls on the
     first unit that takes its shard past an equal share, and no shard is
     empty unless `--shards` fixes the count (decision 5).
   - There are never more than `shards.max` shards (an integer, 20 by default).
     Past that limit, shards grow instead.
   - **Floors do not separate shards.** The verdict adds up per-mutant
     results itself (ADR-0003), so units at different floors can share a shard.
   - **Packages do.** A shard runs in one package's directory (ADR-0005).
   - **Held units run on their own.** A held unit is one runner invocation of
     its own, against its group. A shard with held units runs each of them, then
     one invocation for all its other units.
   - Shards are numbered from 1. Each is labelled with the trees it takes, and
     "part 2 of 4" where a tree spans several shards.

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
     not whitespace, a comment or the opening tag. `costs.secondsPerLine` maps
     path prefixes to seconds per line, the longest matching prefix winning.
     The empty prefix matches every path, and the default is `{"": 0.2}`, so a
     project adds prefixes of its own beside it. After one full CI run every
     unit has been measured, and only new units are estimated.
   - **Costs decide placement only.** A cost decides which shard a unit goes to,
     never whether its mutants run. A wrong cost makes one runner slower, never
     a verdict wrong.

5. **The CiPlan port renders a plan and says which shard a job is.** `ci.plan`
   chooses the adapter, detected by default from `GITHUB_ACTIONS`, `GITLAB_CI`,
   `BUILDKITE` or `CIRCLECI` and otherwise `json`. `--ci=<name>`, accepted by
   `plan` and `run`, overrides it for one command.

   | CI | How the plan reaches it | Which shard a job is |
   |----|-------------------------|----------------------|
   | **GitHub Actions** (`github`) | `shards=<JSON array of {id, label}>` in `$GITHUB_OUTPUT`, read by `strategy.matrix.shard: ${{ fromJson(…) }}`. The plan file travels as an artifact. An empty array skips the matrix job, and the verdict still runs. GitHub's limit of 256 jobs per matrix caps `shards.max` there. | The `--shard=<id>` the matrix passes |
   | **GitLab CI** (`gitlab`) | `parallel:matrix` must be in a pipeline before it starts, so `plan --ci=gitlab` writes a child pipeline, `.mutation-gate/pipeline.yml`. It holds one job with `parallel: matrix: [{SHARD: ["1", "2", …]}]` and a verdict job that needs it and runs `when: always`. Both fetch the plan with `needs: [{pipeline: $PARENT_PIPELINE_ID, job: <plan job>}]`, where the plan job's name is the `CI_JOB_NAME` `plan` ran under. Both extend the hidden job `.mutation-gate`, which the project defines for image and setup in the file `ci.gitlab.template` names (`.gitlab/mutation-gate.yml` by default), and the child pipeline includes that file. The parent triggers it with `trigger: include: - artifact: …` and `strategy: mirror`, so the trigger job takes the child's result. | `SHARD` |
   | **Buildkite** (`buildkite`) | `plan --ci=buildkite` prints steps for `buildkite-agent pipeline upload`: one command step per shard, a `wait` with `continue_on_failure: true`, then the verdict step. Each is built from `ci.buildkite.step`, a map of step keys (agents, plugins, env) merged into every generated step, empty by default. Plan and results travel with `buildkite-agent artifact`. | The `--shard=<id>` in each step |
   | **CircleCI** (`circleci`) | Parallelism is fixed in the config. The plan job runs `plan --shards=<N>`, with N equal to the mutation job's `parallelism`, and persists `.mutation-gate` to the workspace. The verdict job requires the mutation job with the status `terminal`, so it runs after a failure too. | `CIRCLE_NODE_INDEX` + 1 (the variable is 0-based). A `CIRCLE_NODE_TOTAL` that differs from the plan's count stops the shard with exit code 2. |
   | **Generic JSON** (`json`) | `plan --ci=json` prints `{"plan": "<digest>", "commit": "<sha>", "shards": [{"id": 1, "label": "…", "seconds": 540, "units": ["src/A.php", …]}]}` | `--shard=<id>` |

   `--shards=<N>`, accepted by `plan`, works on any CI and cuts exactly N
   shards. Trailing shards may be empty, and each says so and passes having run
   nothing. It is also how GitLab's plain `parallel: N` (`CI_NODE_INDEX`,
   1-based, and `CI_NODE_TOTAL`) and Buildkite's `parallelism`
   (`BUILDKITE_PARALLEL_JOB`, 0-based, and `BUILDKITE_PARALLEL_JOB_COUNT`) are
   served: without `--shard`, `run` reads the first of `SHARD`,
   `CI_NODE_INDEX`, `BUILDKITE_PARALLEL_JOB` and `CIRCLE_NODE_INDEX` the
   detected CI sets, adding 1 to the 0-based ones. A CI the built-ins do not
   cover is an extension (ADR-0001).

   The CiPlan port also answers three things other decisions rely on: the
   run's ref, which is its proof scope (ADR-0007); whether it is a pull
   request, for the new-code set and `baseline.improvement` (ADR-0003); and
   the default branch, for the ledger it reads and for the badge and trend
   (ADR-0009). `plan` reads them from the CI, and a job given a plan reads
   them from the plan, so every shard and the verdict judge as the plan did,
   whatever variables their own jobs receive, as in a GitLab child
   pipeline.

   | CI | Ref | Pull request | Default branch |
   |----|-----|--------------|----------------|
   | GitHub Actions | `GITHUB_REF` | `GITHUB_EVENT_NAME` is `pull_request` | the event payload at `GITHUB_EVENT_PATH` |
   | GitLab CI | `CI_COMMIT_REF_NAME` | `CI_MERGE_REQUEST_IID` is set | `CI_DEFAULT_BRANCH` |
   | Buildkite | `BUILDKITE_BRANCH` | `BUILDKITE_PULL_REQUEST` is not `false` | `BUILDKITE_PIPELINE_DEFAULT_BRANCH` |
   | CircleCI | `CIRCLE_BRANCH` | `CIRCLE_PULL_REQUEST` is set | `ci.defaultBranch` |
   | JSON, and local runs | git's current branch; with a detached `HEAD`, none | never | `ci.defaultBranch` |

   On a pull request the scope is `refs/pull/<n>`, where n is the number the CI
   names, and otherwise `refs/heads/<branch>`. A run with no ref, on a detached
   `HEAD` under the JSON plan, in CI or locally, has no scope of its own: it
   reads the default branch's ledger and writes none (ADR-0007).
   `ci.defaultBranch` is a branch name. By default it is the branch git's
   `refs/remotes/origin/HEAD` points at, and `main` when there is none. Set in
   the config, it replaces the CI's own answer too.

6. **GitHub is wired by the package itself** (ADR-0011). The composite action
   runs the whole gate in one job, or one shard when given `shard`. The
   reusable workflow `.github/workflows/mutation-gate.yml` runs a plan job,
   one matrix job per shard, a verdict job and, on the default branch, a
   publish job. It carries the plan, coverage and results between jobs as
   artifacts, and keeps the ledger in the Actions cache.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Each shard recomputes the cut** (the in-house gate's way) | Safe only while costs are constants in the code. With learned timings, a ledger saved between the planning job and a shard would make the two cut differently. |
| **Mutant-level sharding** | Neither runner can be given a list of mutants: Pest's and Infection's `--id` each take one. A file is the smallest thing both accept. |
| **Longest-first bin packing instead of path-order cuts** | Balances a few percent better. It scatters a tree over every shard, so a label says nothing and a shard's contents shift with every timing. Path order with equal shares is near-balanced when units are small against a 600-second shard. |
| **Timings committed to the repository** | Rewritten by every run, conflicting across pull requests, and true only of the CI's machines. |
| **A moving average of timings** | Smoother, and it lags a real change: a file whose tests got twice as slow is misplaced for several runs. The seed takes the newest measurement, and placement errors cost only time. |
| **A fixed `parallel: N` as the only model** | Cannot shrink to zero shards when nothing is reached, and holds N runners for a one-file change. It stays available as `--shards=<N>` for CIs that need it. |
| **Aggregating from the CI's job results** | A job that was skipped by design and one skipped because something upstream broke look the same, which is why the in-house gate's aggregating job needs a paragraph of conditions. A missing result file is unambiguous. |
| **A shard that exits non-zero when its mutants survive** | Each CI would then need its own way to keep going after a red shard, and the shard's status would say less than the verdict. A shard's exit code says only whether it left its result. |

## Consequences

**Every CI gets the same verdict from the same files.** Only how a plan is
rendered and how a job learns its shard differ.

**The first run is estimated and later runs are measured.** Shards balance
after one full run, with no figures to fit by hand.

**Proved units never reach a runner.** A pull request whose reached units are
all proved plans zero shards and costs one planning job.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): why floors do not split shards
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): what the plan considers
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the ledger that holds proofs and timings
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the composite action and the reusable workflow
