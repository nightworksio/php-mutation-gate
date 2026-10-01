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
       `--coverage=<dir>`: the gate's own map, `<dir>/map.json.gz`, which an
       earlier job wrote with `mutation-gate coverage --into=<dir>`. That
       command runs the suite under coverage and writes the map. A PHP
       coverage map, such as Pest's, is code that reading it runs, so a PHP
       coverage map is read only when this same job wrote it;
     - works out the reach (ADR-0005) and each considered unit's content key
       (ADR-0007), and drops every unit a proof already covers;
     - weighs the rest with the cost model and cuts the shards.

     It writes `.mutation-gate/plan.json`, keeps the coverage it used under
     `.mutation-gate/coverage/`, and prints the plan in the CI's format.
     Coverage leaves the job that read it only as the gate's own map,
     `map.json.gz` in the directory `--coverage=<dir>` names: `"format": 1`,
     compact JSON, gzipped, data that reading never runs. Each shard's map,
     `.mutation-gate/coverage/shard-<id>/map.json.gz`, holds only the lines of
     the files that shard mutates, with every test and its duration. Where the
     runner's report states them, as Infection's does, it also holds each of
     those files' methods some test ran, with the lines the report gives them.
     A shard's runner writes the map back into its own layout in its own job
     (ADR-0004), and the verdict learns what each shard cost from it. A shard
     handed no map mutates nothing and its result cannot judge, since its held
     units' coverage check (ADR-0005, decision 10) and its timeout triage
     (ADR-0008) read that map; a verdict whose shard map is missing cannot
     judge, because the jobs were not handed what the plan wrote.
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
   - **Or a target wall time sets the count.** With `shards.target` (a
     duration) set, the number of shards is the smallest that fits a shard's
     overhead, its opening run plus `shards.setup`, and its share of the cost
     into the target. The cut within that count is the same. A layer of config
     that sets both `shards.target` and `shards.seconds` stops with exit code
     2, and one a later layer sets replaces the other an earlier layer set
     (ADR-0013, decisions 6 to 9).
   - There are never more than `shards.max` shards (an integer, 20 by default).
     Past that limit, shards grow instead, and a target that needs more says
     so in a warning.
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
     - from Pest, the durations the plugin records, and for a mutant judged
       by reference (ADR-0004, decision 8) the time of its judging runs;
     - from Infection, which reports none, each mutant's stand-in, the JUnit
       time of the tests that cover its line.

     A unit's newest share replaces its older one. A unit that was proved
     rather than run keeps its last timing.
   - **Where timings live.** In the ledger (ADR-0007), keyed by unit path, with
     the runner and the time of measurement. They are not committed: they
     describe the CI's machines, not the code, and they change on every run.
     Beside them the ledger keeps each package's newest opening-run time per
     runner, which the shard count of a target wall time uses (ADR-0013,
     decision 7).
   - **Cold start.** A unit with no timing is estimated from what the plan
     measured of its first run. The gate's own engine (ADR-0023, decision 8)
     counts, with the `default` set, the mutants that start on each line of
     each of the unit's files; a plan whose every unit is timed counts nothing
     and runs nothing. It counts them without making them, one for each mutator
     that changes a node, so a change that would print the same code counts,
     where a run makes no mutant of it. A mutant on a line some test covers
     costs a mutant's run starting and the time of every test covering that
     line; one no test covers costs nothing. The unit's cost is the sum, spread
     over the processes the runner runs at once. A mutant's run starting is the
     fastest of three runs of no test, each started as the runner starts a
     mutant's own run (ADR-0004, decision 1), serving the first file the engine
     counted unchanged. The processes are the runner's parallelism on the
     plan's machine: each shard's runner is taken to be like the plan's, and
     `plan` says how many mutants at once it took them to run.
   - **Where nothing was measured, a unit's cost is guessed.** A unit whose
     files the engine counted none of, or any unit where the `default` set is
     not registered or a run of no test cannot run, is estimated as its lines
     of code times seconds per line. The plan is made either way: an estimate
     decides where a unit runs, never whether the gate can plan. Lines of code
     counts lines holding a token that is not whitespace, a comment or the
     opening tag. `costs.secondsPerLine` maps path prefixes to seconds per
     line, the longest matching prefix winning. The empty prefix matches every
     path, and the default is `{"": 0.2}`, so a project adds prefixes of its
     own beside it. After one full CI run every unit has been measured, and
     only new units are estimated. A budgeted run's shard weighs its units by
     what was learned, or guessed.
   - **The plan says what it expects.** Each unit's cost says what it rests
     on: learned from an earlier shard, measured from the plan's first run,
     or guessed from `costs.secondsPerLine`. `plan` prints, on standard
     error, each shard's time with its opening run and `shards.setup` and what
     it rests on, and the run's wall and runner time with the share of each
     basis. `plan.json` keeps each shard's parts beside its `seconds` where
     any of it rests on more than a guess, and its `opening` run.
   - **Costs decide placement only.** A cost decides which shard a unit goes to,
     never whether its mutants run. A wrong cost makes one runner slower, never
     a verdict wrong.

5. **The CiPlan port renders a plan and says which shard a job is.** `ci.plan`
   chooses the adapter. Where it names none, the plan whose CI the job runs in
   is taken: each plan declares `CiPlan::marker()`, the variable its CI marks
   every job with, set to `true` (`CiMarker::saying`) or to any
   value (`CiMarker::setting`), which its registration repeats. So `github`,
   `gitlab`, `buildkite` and `circleci` are taken on `GITHUB_ACTIONS`,
   `GITLAB_CI`, `BUILDKITE` and `CIRCLECI`, in that order, and a job no
   plan's marker shows takes `json`. A plan this package builds in
   wins its own CI: another package's plan is taken only where none of this
   package's markers is shown, and is otherwise chosen by name. `--ci=<name>`, accepted by
   `plan` and `run`, overrides it for one command.

   | CI | How the plan reaches it | Which shard a job is |
   |----|-------------------------|----------------------|
   | **GitHub Actions** (`github`) | `shards=<JSON array of {id, label}>` in `$GITHUB_OUTPUT`, read by `strategy.matrix.shard: ${{ fromJson(…) }}`, and `plan=<the json listing, on one line>` beside it. The plan file travels as an artifact. An empty array skips the matrix job, and the verdict still runs. GitHub's limit of 256 jobs per matrix caps `shards.max` there. | The `--shard=<id>` the matrix passes |
   | **GitLab CI** (`gitlab`) | `parallel:matrix` must be in a pipeline before it starts, so `plan --ci=gitlab` writes a child pipeline, `.mutation-gate/pipeline.yml`. It holds one job with `parallel: matrix: [{SHARD: ["1", "2", …]}]` and a verdict job that needs it and runs `when: always`. Both fetch the plan with `needs: [{pipeline: $PARENT_PIPELINE_ID, job: <plan job>}]`, where the plan job's name is the `CI_JOB_NAME` `plan` ran under. Both extend the hidden job `.mutation-gate`, which the project defines for image and setup in the file `ci.gitlab.template` names (`.gitlab/mutation-gate.yml` by default), and the child pipeline includes that file. The parent triggers it with `trigger: include: - artifact: …` and `strategy: mirror`, so the trigger job takes the child's result. | `SHARD` |
   | **Buildkite** (`buildkite`) | `plan --ci=buildkite` prints steps for `buildkite-agent pipeline upload`: one command step per shard, a `wait` with `continue_on_failure: true`, then the verdict step. Each is built from `ci.buildkite.step`, a map of step keys (agents, plugins, env) merged into every generated step, empty by default. Plan and results travel with `buildkite-agent artifact`. No Buildkite variable names the file a pipeline was uploaded from, so `ci.buildkite.definition` names the one that runs the gate (`.buildkite/pipeline.yml` by default), as `ci.gitlab.template` does for GitLab; reach and the proof key count it as the CI definition (ADR-0005, ADR-0007). | The `--shard=<id>` in each step |
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
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the shard count from a target wall time, and the opening-run timings
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): the first-run estimate `init` prints, and what a run saved
