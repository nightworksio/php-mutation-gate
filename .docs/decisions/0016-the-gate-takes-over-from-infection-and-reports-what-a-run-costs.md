# ADR-0016: The gate takes over from an Infection config, says what a run costs, alerts on the default branch, and exports its runs

**Status:** Accepted
**Date:** 2026-09-30

## Context

Operating the gate raises four needs its reports do not yet meet.

- **Adopting it from Infection.** Many projects arrive with an
  `infection.json5`. The Infection adapter already starts from that file and
  keeps what the runner owns: `mutators` with their profiles and settings,
  `bootstrap`, `phpUnit`, `initialTestsPhpOptions` and
  `testFrameworkExtraArgs` (ADR-0004 decision 4). The rest either becomes
  something of the gate's or is refused by it:
  - `minMsi` is a floor;
  - `source` decides the trees;
  - `ignore` and `ignoreSourceCodeByRegex` are native markers, refused by
    default (ADR-0008);
  - the `logs` are reports.

  Infection's MSI is not the gate's score. It counts errors and, by
  default, timeouts as killed, and it leaves out skipped mutants, where the
  gate triages timeouts (ADR-0003, ADR-0008). Infection never generates a
  mutant its `ignore` patterns match, so no mutant id can be recovered from
  them. `source.excludes` has no equivalent: a tree is a path, and only the
  phpunit tree source knows `<source><exclude>`.
- **Knowing what a run cost.** The plan and the shards already hold every
  figure needed:
  - the cost model's estimate for each unit;
  - each shard's measured time and opening run (ADR-0006, ADR-0013);
  - the time of every unit that reach or a proof spared.

  Nothing reports them.
- **Hearing when the default branch breaks.** A red default branch, or one
  whose verdict could not be reached, is seen today only by whoever opens
  CI. The chat services differ:
  - **A Slack incoming webhook** requires `text`, a missing one being
    `no_text`, and takes optional Block Kit `blocks`. It answers `400
    invalid_payload`, `403 action_prohibited` or `404 no_service`. Its
    channel, name and icon cannot be overridden.
  - **A Discord webhook** takes `content` (up to 2,000 characters), up to
    10 `embeds`, and an optional `username` and `avatar_url`.
  - **A webhook URL is a credential**, and config files are committed.
- **Watching runs over time.** OpenTelemetry is how CI telemetry is collected.
  - `open-telemetry/sdk` 1.15 requires, among others, `php-http/discovery`
    and `tbachert/spi`, both Composer plugins that each consuming project
    would have to allow. ADR-0001 rejected that cost for its own discovery.
  - Its OTLP exporter adds `google/protobuf`.
  - OTLP over HTTP also accepts JSON, and one request with
    `symfony/http-client`, already required, can carry it.
  - The gate emits a handful of spans and data points once per process, and
    needs none of the SDK's batching, sampling or context propagation.

## Decision

1. **`init --from=<file>` starts the gate's config from an Infection config.**
   - It reads `infection.json5`, `infection.json` or a `.dist` of either.
   - It writes the gate's config in `--format`, as `init` does (ADR-0002
     decision 2), seeded from that file as well as from zero-config.
   - It prints every key of the file with *imported as*, *stays in
     infection.json5* or *dropped, because*.
   - It never rewrites the Infection file, because a JSON5 rewrite loses
     its comments. It prints the keys to delete instead.
   - `mutation-gate import <file>` is the same command.
   - A `testFramework` other than `phpunit` stops it with exit code 2 before
     anything is written (ADR-0004 decision 4).

   | `infection.json5` | Becomes |
   |-------------------|---------|
   | `source.directories` | `trees[].path`, one tree per directory. `trees` replaces the tree source's list whole (ADR-0002 decision 5), and the report says where `phpunit.xml`'s `<source>` differs |
   | `source.excludes` | `trees[].exclude` of the tree holding each (decision 3) |
   | `minMsi`, `minCoveredMsi` | `trees[].floor`, and `uncovered` (decision 2) |
   | `timeout` | `timeouts.seconds` |
   | `timeoutsAsEscaped: true` | `timeouts.mode: unjudged` |
   | `mutators.<Name>.ignore`, `global-ignore`, `ignoreSourceCodeByRegex`, `global-ignoreSourceCodeByRegex` | `ignores.entries`, or `ignores.native: allow` (decision 4) |
   | `logs.html`, `logs.stryker.report` | a `reports` entry `html` |
   | `logs.json` | a `reports` entry `json`, as a suggestion. The gate's JSON is its own format |
   | `logs.gitlab` | a `reports` entry `gitlab` (decision 5) |
   | `logs.github` | dropped: annotations are automatic under GitHub Actions (ADR-0009) |
   | `logs.stryker.badge` | dropped: the gate publishes its own badge (ADR-0009) |
   | `logs.text`, `logs.summary`, `logs.summaryJson`, `logs.perMutator`, `logs.debug` | dropped: the gate owns `logs.json` and `logs.text` in the config it generates |
   | `maxTimeouts` | dropped: timeouts are triaged, not capped (ADR-0008) |
   | `ignoreMsiWithNoMutations` | dropped: *nothing to mutate* already passes (ADR-0003) |
   | `threads`, `tmpDir` | stays in the file; the gate overrides both (ADR-0004) |
   | `mutators` profiles and settings, `bootstrap`, `phpUnit`, `initialTestsPhpOptions`, `testFrameworkExtraArgs`, `staticAnalysisTool`, `staticAnalysisToolOptions` | stays in the file; the adapter reads it |

2. **`minMsi` becomes each tree's declared floor.** It is truncated to two
   decimals, and it is the policy minimum of ADR-0003 decision 2, not a
   baseline entry, because nothing was measured.
   - `minCoveredMsi` sets `uncovered: exclude` and, when `minMsi` is absent,
     the floor.
   - The report ends: *run `mutation-gate` locally once; it writes the
     baseline at what the gate measures, and says if a tree is below the
     imported floor.* The difference between the formulas surfaces on the
     developer's machine, not in CI.

3. **A tree may exclude paths: `trees[].exclude`.**
   - It is a list of globs spelt from the repository root, with the gate's
     glob semantics: `*` within one segment, `?` for one character, `**`
     for any number of segments.
   - A file a tree's exclude matches belongs to no tree. It is not mutated,
     counted or reached as a unit.
   - An exclude that matches no file in its tree stops the run with exit
     code 2, as a misspelt key does (ADR-0002 decision 6), because it would
     otherwise do nothing silently.
   - The PHP builder takes it as `Tree::at('src', floor: 90, excluding:
     ['src/Legacy/**'])`.
   - It decides what the tree is, so it affects results and is in the proof
     key beside `trees[].path` (ADR-0007 decision 2.3). The phpunit tree
     source's `<source><exclude>` keeps working as before.

4. **Infection's ignores are converted where they map, and allowed where they
   do not.**
   - **`ignore` patterns become path entries.** `mutators.<Name>.ignore:
     ["App\\Money::add"]` becomes `{path: <the class's file, from the
     autoload>, mutator: <Name>}`. A `Class::method` or `Class::method::line`
     pattern widens to the whole file, and the report lists every entry that
     widened.
   - **`global-ignore` becomes one entry per family,** because a gate ignore
     names a mutator or a family (ADR-0008 decision 4).
   - **Every imported entry has a reason and an end.** Its reason is
     `Imported from infection.json5 (<pattern>): write the real reason`,
     and it expires 90 days after the import, so no imported entry outlives
     a person's look at it.
   - **Regex ignores are kept native.** `ignoreSourceCodeByRegex` has no
     equivalent, so where any exists the import sets `ignores.native: allow`
     and says so.
   - **The markers left in the file** would make the run refuse to start
     under `ignores.native: refuse`. The report prints the keys to delete,
     and so does the refusal itself.

5. **A `gitlab` report writes GitLab's Code Quality JSON.**
   - Each result carries `description`, `check_name` (the SARIF rule),
     `fingerprint` (a digest of the gate's id, as SARIF's is), `severity` and
     `location.path` with `location.lines.begin`.
   - `severity` is `major` for a mutant in a failing set, and `minor`
     otherwise.
   - GitLab's merge request widget then shows survivors per line, the view
     ADR-0009 decision 3 gives GitHub through annotations.

6. **A run's cost is its time, planned and measured, and what it was spared.**
   - **Planned:** the wall time and runner-minutes the plan expects. That is
     the cost model's estimate plus each shard's overhead, its opening run
     and `shards.setup` (ADR-0013).
   - **Measured:** the same two figures from the shards' results.
   - **Spared:** the cost-model time of every unit reach carried or a proof
     covered, "saved 41 runner-minutes".
   - `shards.setup` is included, and labelled *estimated*, where the gate
     cannot see the CI's setup. On GitHub the jobs' own start and end times
     replace it (ADR-0017).

7. **Money is shown only when the team gives a rate.**
   `costs.perRunnerMinute` is `{amount, currency}`, and unset by default.
   When it is set, each time figure is shown with its price. It sits under
   `costs`, which the proof key leaves out (ADR-0007 decision 2.3).

8. **The cost appears once, out of the way.**
   - It is a collapsed `<details>` section at the end of the sticky PR
     comment (ADR-0009 decision 3). What the run saved is one line directly
     under the verdict, outside it (ADR-0017).
   - The step summary and the console each give one line.
   - The JSON report gains a `cost` object.
   - **The section** is `<details><summary>What this run cost</summary>`,
     then a table of the planned and measured wall and runner time, then
     *Reach and proofs spared 41m of runner time.* Where the CI did not
     measure setup it adds *Setup is estimated at 1m a job, from
     `shards.setup`.* With a rate it adds *At 0.20 EUR a runner minute:
     planned 3.00 EUR, measured 2.80 EUR, spared 8.20 EUR.* Money is written
     with two decimals and the currency as the team gave it.
   - **The `cost` object** is `{"planned": {"wallSeconds", "runnerSeconds",
     "price"?}, "measured": {…}, "spared": {"seconds", "price"?},
     "setupEstimated", "perRunnerMinute"?}`. Each `price` and
     `perRunnerMinute` is `{"amount", "currency"}`, present only where a
     rate is set.

9. **Chat alerts are reporters: `slack`, `discord` and `webhook`.**
   - They are built-in `Reporter` adapters (ADR-0009 decision 1). They send
     with `symfony/http-client`, which is already required (ADR-0001).
   - A failed post says so, and never changes the exit code.

10. **An alert fires when the default branch changes state, in CI only.**
    - **The states:**
      - *failed*: a tree below its floor, or a failure that belongs to no
        floor;
      - *cannot judge*;
      - *recovered*: the first pass after either;
      - *floor lowered*: a baseline `lowered` entry landed (ADR-0003
        decision 7).
    - **The previous state** comes from the newest entry of `trend.json`,
      which gains a `verdict` field (ADR-0009 decision 5). It is restored
      into `--publish-dir` as the badge is.
    - With no previous state, only a failure alerts.
    - A run cut short by its budget never alerts, as it never updates the
      badge (ADR-0009 decision 5).
    - Pull requests never alert. Their authors have the sticky comment.

11. **A webhook URL comes from the environment, never from the config.**
    - Each reporter reads the URL from the environment variable its
      `with.urlEnv` names:
      - `slack`: `MUTATION_GATE_SLACK_URL` by default;
      - `discord`: `MUTATION_GATE_DISCORD_URL` by default;
      - `webhook`: `MUTATION_GATE_WEBHOOK_URL` by default.
    - A literal `url` in `with` is refused by the validator, and the message
      names the variable to use.
    - The reusable workflow takes these as optional secrets, passed to its
      `verdict` job only, with `MUTATION_GATE_WEBHOOK_SECRET` (decision 12).
      The one-step action reads them from its step's `env`.

12. **Each alert is one short summary, rendered three ways.**
    - **Every message carries** the event, the failing trees with floor,
      score and previous score, at most five survivors, and the run's link.
    - **Slack:** `text` as the one-line fallback, and Block Kit sections.
    - **Discord:** one embed, red, green or grey. It sets
      `allowed_mentions: {"parse": []}`, so no tree or test name can ping
      anyone.
    - **Webhook:** `{"format": 1, "event", "repository", "ref", "commit",
      "run", "verdict", "trees": [{"path", "floor", "score", "previous"}]}`.
      Its schema is generated as the report's is, and committed at
      `resources/webhook.schema.json`. When `MUTATION_GATE_WEBHOOK_SECRET`
      (or the variable `with.secretEnv` names) is set, the request carries
      `X-Mutation-Gate-Signature: sha256=<HMAC-SHA256 of the body>`.
    - Every chat limit, Discord's 2,000 characters among them, holds by
      construction.

13. **An alert is sent within 10 seconds or reported unsent.**
    - The request times out at 10 seconds.
    - A 429 or a 5xx is retried once, after its `Retry-After` up to 30
      seconds.
    - After that the reporter says *not written*, with the status and the
      body.

14. **`otlp` exports traces and metrics as OTLP/HTTP JSON, without the SDK.**
    - It is a built-in `Reporter` adapter. It POSTs to `/v1/traces` and
      `/v1/metrics` with `symfony/http-client`.
    - A contract test checks each payload against OTLP's JSON mapping: 64-bit
      integers as decimal strings, trace and span ids as lowercase hex.
    - Nothing is added to `require`, and no Composer plugin.

15. **One trace per CI run, across its jobs, and metrics from the verdict.**
    - **The trace id** is the first 16 bytes of `sha256("mutation-gate:" +
      run)`, where `run` is the run a proof names (ADR-0007 decision 3). So
      the plan job, every shard job and the verdict job add spans to the same
      trace, with nothing passed between them.
    - **Span ids** are derived the same way from the trace id and the span's
      path. The gate draws no random number (ADR-0001).
    - **Spans:** `plan`, `shard <n>` with the children `opening run` and
      `mutate`, and `verdict`.
    - **Metrics, emitted by the verdict:**
      - `mutation_gate.score`, a gauge per tree and for new code;
      - `mutation_gate.mutants`, a sum by status;
      - `mutation_gate.units`, a sum by run, proved or carried;
      - `mutation_gate.duration`, per phase;
      - `mutation_gate.runner_minutes`.

16. **Attributes follow OpenTelemetry's semantic conventions, and stay few.**
    - The resource carries `service.name=mutation-gate`.
    - Spans carry `vcs.ref.head.name`, `vcs.ref.head.revision`,
      `cicd.pipeline.run.id` and `cicd.pipeline.name`.
    - The gate's own attributes are `mutation_gate.tree`,
      `mutation_gate.status`, `mutation_gate.shard`, `mutation_gate.runner`
      and `mutation_gate.mode`.
    - None is per mutant or per file, so cardinality is bounded by trees
      times statuses.

17. **`otlp` is opt-in, and reads OpenTelemetry's own variables.**
    - A `reports` entry `{"use": "otlp"}` turns it on. A CI that sets
      `OTEL_*` for other tools does not make the gate send.
    - The endpoint, headers, service name and resource attributes come from
      `OTEL_EXPORTER_OTLP_ENDPOINT`, `OTEL_EXPORTER_OTLP_HEADERS`,
      `OTEL_SERVICE_NAME` and `OTEL_RESOURCE_ATTRIBUTES`. `with.endpoint`
      may override the endpoint.
    - Each request times out at 5 seconds, is not retried, and a failure is
      *not written*.
    - The reusable workflow takes `OTEL_EXPORTER_OTLP_ENDPOINT` and
      `OTEL_EXPORTER_OTLP_HEADERS` as optional secrets, passed to every job,
      because every job adds spans.

18. **The JSON report gains a `run` section.** It holds:
    - the phase durations and per-shard timings;
    - the units run, proved and carried;
    - the runner-minutes;
    - the trace id.

    The `cost` object of decision 8 sits beside it. A team without a
    collector reads the same numbers from a file CI already keeps.

    Its shape: `{"id", "traceId", "phases": {"plan"?: {"start",
    "seconds"}, "verdict"?: {…}}, "shards": [{"shard", "start",
    "openingRunSeconds", "mutateSeconds"}], "units": {"run", "proved",
    "carried"}, "wallSeconds", "runnerSeconds", "measured"}`. `start` is an
    instant in UTC, as `2026-09-30T11:50:00Z`, and `measured` is false where
    the runner time is estimated from `shards.setup` (ADR-0017 decision 12).
    `run`, `cost` and `savings` are left out where the flows gave the
    verdict no timings.

19. **Phases are timed in `Cli`, and handed to `Core` as a value.**
    - The flows read the PSR-20 clock (ADR-0001), and the verdict carries a
      `RunTimings` value.
    - Shards write their timings into their result files, which already hold
      what the shard measured (ADR-0006 decision 1), and the verdict merges
      them.
    - The console, JSON, cost and OTLP output all read these same numbers.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Only `import <file>`, a separate command** | A second command writing the file `init` writes. It stays as an alias of `init --from`. |
| **Writing `minMsi` into the baseline** | The baseline records what was achieved (ADR-0003), and nothing was measured. |
| **Dropping `minMsi`** | Loses a policy the team chose. |
| **Splitting a tree around `source.excludes`** | Multiplies trees and floors, and reverts to a `phpunit.xml` edit past twenty trees. |
| **An ignore per excluded path** | The excluded code is mutated at full cost, only to be left out. |
| **Leaving Infection's ignores native** | They stay without reasons and without ends, which ADR-0008 exists to stop. |
| **Resolving ignores to mutant ids by running Infection** | Infection never generates what its `ignore` patterns match, so there is nothing to resolve. |
| **Rewriting `infection.json5`** | A JSON5 rewrite loses the file's comments. |
| **No GitLab Code Quality report** | GitLab users would lose per-line survivors in the merge request. |
| **Measured time only** | Cannot say what reach and proofs spared, which is the figure worth showing. |
| **Money by default, from list prices** | Prices vary by plan and runner size and change over time. A wrong price in every pull request is worse than none. |
| **No money at all** | Teams that budget in money would compute it by hand. |
| **A second sticky comment for cost** | Two gate comments per pull request. ADR-0009 settled on one. |
| **Leaving CI setup out of the estimate** | Understates what a pull request costs. |
| **A port for notifications** | The Reporter port already takes the whole verdict and never changes an exit code. |
| **A separate chat package** | The HTTP client is already required. A split adds a version matrix and saves nothing. |
| **An alert on every failing default-branch run** | A red default branch would post on every merge until fixed. |
| **Alerts on pull requests** | Authors already have the sticky comment, and a channel would flood. |
| **`${ENV}` interpolation in every config string** | Adds a template language to a config that is data (ADR-0002), and makes every value depend on the environment. |
| **A literal URL with a warning** | One leak per careless commit. |
| **The whole JSON report as the webhook body** | Megabytes per post, in a format built for another job. |
| **No retry** | A 429 from a busy workspace would lose the alert. |
| **The OpenTelemetry SDK, required or suggested** | Twelve or more packages and two Composer plugins for a handful of spans. |
| **A JSON file only, with no OTLP** | Most OpenTelemetry users expect to point a tool at an endpoint. |
| **Metrics only, or traces only** | Metrics alone lose where the time went. Traces alone bury scores in span attributes. |
| **The gate's own attribute names throughout** | Foreign to every backend's CI views. |
| **Exporting whenever `OTEL_EXPORTER_OTLP_ENDPOINT` is set** | The gate would start sending in any CI that configured OpenTelemetry for something else. |
| **A separate `metrics` report** | A second JSON format with overlapping counts. |
| **Each reporter timing what it needs** | The PR comment and the dashboard would disagree. |

## Consequences

**A project on Infection adopts the gate with one command,** keeps its
mutator settings where they were, and sees every place where the gate means
something different.

**Every pull request says what its check cost and what it was spared,** in
time, and in money for teams that give a rate.

**A broken default branch is announced once,** where the team talks, and its
recovery is announced too.

**Runs become telemetry** in any OpenTelemetry backend, one trace per CI run
across its jobs, with no new dependency.

## Related

- [ADR-0002](0002-one-typed-config-from-several-formats.md): `init --from`, and a config that holds no secret
- [ADR-0003](0003-a-floor-only-rises.md): `trees[].exclude`, and `minMsi` as a declared floor
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): what the Infection adapter keeps from `infection.json5`
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): `trees[].exclude` in the key, and `costs` left out
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): native ignores, and the entries that replace them
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the new reporters, the JSON report's `cost` and `run`, and `trend.json`'s `verdict`
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the reusable workflow's secrets
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): shard overhead and `shards.setup`
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): measured job times on GitHub, and the savings line outside the collapsed section
