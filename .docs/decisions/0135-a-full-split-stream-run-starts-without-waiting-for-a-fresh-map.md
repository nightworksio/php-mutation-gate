# ADR-0135: A full split-stream run starts without waiting for a fresh map

**Status:** Accepted
**Date:** 2026-11-09
**Amends:** [0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the plan's coverage run, for a full split-stream run that takes no proof; [0005](0005-what-a-change-reaches-is-what-is-mutated.md): the check of a held unit's coverage, made by the verdict in such a run; [0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the digests a proof keeps of its key's items, read also to tell that no proof can match, and the keys of such a run, built by its verdict

## Context

A plan reads a fresh coverage map: the reusable workflow's caller hands it
one as an artifact (`coverage`), or the plan runs the suite under coverage
itself (`.github/workflows/mutation-gate.yml:75-80`). In the gate's own CI
the `tests and coverage` job runs the suite under coverage and converts the
report to the map (`.github/workflows/ci.yml:367-379`), and `mutation` waits
for it and runs only where it succeeded (`ci.yml:820-822`).

That job took 4.5, 7.0, 5.6 and 8.2 minutes in runs 42493050329,
42494457581, 42493881214 and 42485819353, after waiting up to 9.1 minutes to
start. In push run 42493050329, from `what changed` to the end of `plan` took
9.5 minutes, 4.5 of them the tests job.

A split stream records which test executes which site as it runs (ADR-0117).
A full run, every unit reached by a file that decides how the gate runs
(ADR-0005), by `--full` or by the `unproven` mode, reads no map to decide its
reach. Cutting the suite into test parts reads only test times, which the map
kept beside the default branch's ledger supplies; times move balance, never a
result.

A run that takes proofs reads the map for more than reach. A unit's key
holds its covered lines and their tests (key item 9), the files its tests
executed (item 10), and, in the base, the files no map shows run (item 6)
(ADR-0007). Without the fresh map those keys cannot be built, and a key that
cannot be built proves nothing.

A model of a full split-stream run's critical path on the gate's own CI is
9.5 minutes to the plan's end, 5 to 10 minutes of shards and 2.7 minutes of
verdict: 17 to 22 minutes, of which the tests job is 4.5.

## Decision

1. **`splitStream.early` says which full split-stream runs plan without a
   fresh map:** `no-match`, the default, `always`, `unproven-only` or
   `never`. Where `splitStream.enabled` and `splitStream.sharding: tests`
   hold, a plan given no map:
   - reaches by the kept map and the config, as every full run does;
   - where the run is full and the value lets it start early (decision 2),
     cuts the test parts by the kept map's times, skips its own coverage
     run, takes no proof, and hands the shards no map;
   - otherwise runs the suite under coverage itself, as today, or, where the
     reusable workflow's input `early-only` is `true` (`false` by default),
     plans nothing and says why, leaving the run to a caller that hands a
     fresh map.
2. **Which full runs start early.**
   - **`no-match`**: where nothing could prove a unit anyway: under
     `unproven`, or where no proof in the ledgers the run reads shares the
     digests of the run's key items 1 to 5, which a proof keeps item by item
     and which read no map (the format, the gate's verdict code, the config
     that affects results, the runner and its PHP, what is installed). A
     base that differs in any of them is another base, and no key of it
     matches a proof of the old one (ADR-0007). ADR-0007 keeps those digests
     for `doctor`; the plan reads them only to tell that nothing can match,
     never to match.
   - **`always`**: every full split-stream run, taking no proof even where
     one could have spared a unit. It costs runner minutes, never soundness:
     a unit it judges again is judged as a run without proofs judges it.
   - **`unproven-only`**: only a run under `unproven`.
   - **`never`**: no run; every plan waits for a fresh map, as today.
3. **The verdict writes the map the streams recorded, and keys by it.** Each
   stream records, per test, the sites and lines it executed, and the verdict
   merges the parts into the map it keeps beside the ledger (ADR-0023), for
   the next plan. Since the plan built no keys, the verdict builds each
   unit's key from that map, as a plan would (ADR-0007), and records the
   proofs of the units that ran to the end under them.
4. **The verdict checks held coverage against the recorded map.** In an
   early run a held unit's holding tests still run alone under coverage in
   their shard (ADR-0005); the verdict, not the shard, compares their lines
   with every line the recorded map shows the suite covering, and a held unit
   they miss lines of fails the verdict, as today.
5. **A shard stops between items once a named job has failed.** The reusable
   workflow takes `watch-jobs`, the names of jobs of the calling run, one per
   line, empty by default. Where it is set, a shard reads their conclusions
   through the REST API before each item, and stops, leaving the items it
   did not start without a result, once one concluded in failure. The shard
   job asks for `actions: read` to do so, a permission that only reads.
6. **The gate's own CI starts mutation early on such runs.** `what changed`
   outputs `streams`, true where `mutation-gate.json` lets a full
   split-stream run start early in any of its contexts. Only then does a
   second caller of the reusable workflow run: needing only `what changed`,
   it plans with no map, `early-only: true` and `watch-jobs` naming
   `checks`, `rules` and `scripts`, so an early run that a fast gate fails
   stops between items. The caller that hands `tests`' map runs where that
   caller was skipped or planned nothing. A job of `ci.yml` of its own,
   `mutation verdict`, needs both callers and passes only where the caller
   that ran passed its verdict. It is the one check `main` requires, and the
   gate's config names it in `ci.check`, so `passed`, `lastRun` and the trust
   of merged pull requests read one name whichever caller ran (ADR-0005,
   ADR-0007). A skipped caller's own verdict check is required by nothing.
7. **For a project, the reusable workflow does the same alone.** Its plan job
   runs the suite under coverage itself where no map is handed, so on an
   early run it skips that run; a project's own test job is what
   `watch-jobs` names.
8. **On a full early run, the streams stand in for the suite's own run.**
   Each stream parent records, beside each test's lines, whether the test
   passed, whether it raised an issue (risky, or a deprecation, error, notice
   or warning from PHP, the code or PHPUnit), and whether it ran in a
   process of its own; once it ran every test of its stream it records every
   line pcov reported in each file its tests loaded, executed or not, which
   a coverage report counts as executable. Each recording file starts empty,
   so a stream that stopped short leaves one that says so. Each shard leaves
   `<shard>.suite.json` beside its recorded map, and the verdict merges them
   into `.mutation-gate/suite/`, with the merged map and the judging suites,
   which the reusable workflow uploads as `mutation-gate-suite` and reports
   in its output `suite`. In the gate's own CI, `tests and coverage` needs
   the early caller: where every stream ran each of its tests, each passed
   with no issue and none ran in a process of its own, it runs only the
   suites the streams did not, merges the streams' lines into that run's
   report and holds the whole to its floor; anything short of that, the
   floor included, runs the whole suite as before. The streams never fail
   the job: a failure is always the suite's own run's.
9. `splitStream.early` decides how the gate runs and is in no key; the
   inputs `early-only` and `watch-jobs` are the reusable workflow's.

### Settings

| Setting | Values | Default | Effect |
|---|---|---|---|
| `splitStream.early` | `no-match`, `always`, `unproven-only`, `never` | `no-match` | decides how the gate runs |
| reusable-workflow input `early-only` | `true`, `false` | `false` | the caller's |
| reusable-workflow input `watch-jobs` | job names, one per line | empty | the caller's |

### Why no verdict moves

- **A stream judges by its own parent** (ADR-0117 decision 4). Nothing it
  decides reads the map: not its kills, its timeouts or its survivors.
- **Nothing reads a stale map where it matters.** An early run takes no
  proof, reach of a full run reads none, and the keys its proofs are recorded
  under are built from the map its own streams recorded. The kept map's test
  times only balance the parts.
- **The next run reads a map as fresh as today's.** The streams record what
  the coverage run would have measured: which test executes which line.

### The trade

Mutation starts before the suite has passed. A run whose tests fail spends
shard minutes that waiting saves today: at most one item per shard after the
failure shows, by decision 5.

### Proof before it ships

- On one commit, the map the streams recorded against the map the coverage
  run measured: the keys the verdict builds from the one are the keys the
  plan builds from the other, and the next plan made from each is the same,
  unit for unit and key for key.
- A held unit whose holding tests miss a line only the recorded map shows:
  the verdict fails it, as a shard's check does today.

### Where today's path stays

- A run that is not full, or one its value of `splitStream.early` does not
  start early, waits for a fresh map.
- `splitStream.early: never`, a run that does not split its streams, and
  `splitStream.sharding: source` wait as today.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Keep every run waiting for the tests job** | The tests job stays on the critical path of the runs that read nothing from it. |
| **Start every split-stream run early, keys from the kept map** | A key built from a stale map can match a proof of code that has moved. |
| **Shards run to the end whatever the tests do** | A red pull request spends its whole shard phase. |
| **Start early only on a schedule** | A pull request that changes the gate's verdict code or a keyed setting is full and takes no proof too; `unproven-only` keeps it selectable. |
| **Require both callers' verdict checks** | A skipped required check passes, and the ledger and the trust of merged pull requests read one check name. |
| **One caller whose plan waits for the map** | It holds a runner while the tests job runs, on most pull requests. |

## Consequences

- A full split-stream run that takes no proof loses the tests job from its
  critical path, about a fifth to a quarter of it in the model above.
- The gate's own CI runs two callers of the reusable workflow, of which one
  plans the run and the other plans nothing, and one more short job,
  `mutation verdict`, that `main` requires.
- Shards hold `actions: read`.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): full runs and held units
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the plan and its coverage run
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the items of a key, and the digest a proof keeps of each
- [ADR-0023](0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md): the map kept beside the ledger
- [ADR-0084](0084-the-gate-is-keyed-by-the-code-a-verdict-depends-on.md): `unproven`, and the verdict code in item 2
- [ADR-0117](0117-a-kill-judged-against-its-own-unmutated-stream-needs-no-control.md): split streams and test parts
