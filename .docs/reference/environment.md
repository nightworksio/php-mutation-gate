# Environment variables

The variables the gate reads. Most are set by your CI; the ones you set
yourself are credentials, webhook URLs and the store's location for `deliver`
and `fetch`.

## What the gate reads

| Variable | What it does | Decided in |
|----------|--------------|------------|
| `CI`, or Azure Pipelines' `TF_BUILD` | Set: a tree with no floor stops the run, `baseline.improvement` applies, and on the default branch the badge and trend are written. Unset: a full run writes missing floors and raises improved ones | [0003](../decisions/0003-a-floor-only-rises.md), [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `GITHUB_ACTIONS`, `GITLAB_CI`, `BUILDKITE`, `CIRCLECI`, `TF_BUILD`, `BITBUCKET_BUILD_NUMBER`, `BUILD_TAG` | Choose the CI plan, and under GitHub Actions the annotations and step summary | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `SHARD`, `CI_NODE_INDEX`, `CI_NODE_TOTAL`, `BUILDKITE_PARALLEL_JOB`, `BUILDKITE_PARALLEL_JOB_COUNT`, `CIRCLE_NODE_INDEX`, `CIRCLE_NODE_TOTAL`, `BITBUCKET_PARALLEL_STEP`, `BITBUCKET_PARALLEL_STEP_COUNT`, `CI_JOB_NAME` | Which shard a job is. GitLab's child pipeline finds the plan through `PARENT_PIPELINE_ID`, which the gate writes into the child pipeline and never reads | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md) |
| `GITHUB_REF`, `GITHUB_EVENT_NAME`, `GITHUB_EVENT_PATH`, `CI_COMMIT_REF_NAME`, `CI_MERGE_REQUEST_IID`, `CI_DEFAULT_BRANCH`, `BUILDKITE_BRANCH`, `BUILDKITE_PULL_REQUEST`, `BUILDKITE_PIPELINE_DEFAULT_BRANCH`, `CIRCLE_BRANCH`, `CIRCLE_PULL_REQUEST`, `BUILD_SOURCEBRANCH`, `BUILD_REASON`, `SYSTEM_PULLREQUEST_PULLREQUESTNUMBER`, `SYSTEM_PULLREQUEST_PULLREQUESTID`, `BITBUCKET_BRANCH`, `BITBUCKET_PR_ID`, `BITBUCKET_TAG`, `BRANCH_NAME`, `CHANGE_ID`, `CHANGE_BRANCH`, `TAG_NAME`, `CI_COMMIT_TAG`, `BUILDKITE_TAG`, `CIRCLE_TAG` | The run's ref, whether it is a pull request, and the default branch | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `GITHUB_WORKFLOW_REF`, `CI_CONFIG_PATH` | Which CI definition runs the gate, for reach and the proof key | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md), [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `GITHUB_OUTPUT`, `GITHUB_STEP_SUMMARY` | Where the GitHub plan and the step summary are written | [0006](../decisions/0006-shards-are-cut-by-learned-cost-and-planned-once.md), [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `GITHUB_RUN_ID`, `GITHUB_RUN_ATTEMPT`, `CI_PIPELINE_ID`, `BUILDKITE_BUILD_ID`, `CIRCLE_WORKFLOW_ID`, `BUILD_BUILDID`, `BITBUCKET_BUILD_NUMBER`, `BUILD_TAG` | The run a proof names | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md), [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `GITHUB_REPOSITORY`, `GITHUB_SHA`, `GITHUB_SERVER_URL`, `GITHUB_WORKFLOW`, `GITHUB_API_URL` | The repository, the commit, the server, the workflow and the API a GitHub Actions run names: its links, the comment, the badge's commit, the alerts and trace, `doctor --online` and `dashboard` | [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md), [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md), [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `CI_PROJECT_PATH`, `CI_COMMIT_SHA`, `CI_PIPELINE_URL`, `CI_PIPELINE_NAME`, `BUILDKITE_ORGANIZATION_SLUG`, `BUILDKITE_PIPELINE_SLUG`, `BUILDKITE_COMMIT`, `BUILDKITE_BUILD_URL`, `BUILDKITE_PIPELINE_NAME`, `CIRCLE_PROJECT_USERNAME`, `CIRCLE_PROJECT_REPONAME`, `CIRCLE_SHA1`, `CIRCLE_BUILD_URL`, `CIRCLE_JOB`, `BUILD_REPOSITORY_NAME`, `BUILD_SOURCEVERSION`, `SYSTEM_COLLECTIONURI`, `SYSTEM_TEAMPROJECT`, `BUILD_DEFINITIONNAME`, `BITBUCKET_REPO_FULL_NAME`, `BITBUCKET_COMMIT`, `GIT_URL`, `GIT_COMMIT`, `BUILD_URL`, `JOB_NAME` | The repository, the commit, the link to the run and the pipeline a run names on GitLab, Buildkite, CircleCI, Azure DevOps, Bitbucket and Jenkins, for the alerts and the trace; `CI_COMMIT_SHA`, `BUILDKITE_COMMIT` and `CIRCLE_SHA1` also give the badge's commit | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md), [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `GITHUB_TOKEN` | Lets the sticky PR comment be posted, and the GitHub change source prove which pull request's run passed | [0005](../decisions/0005-what-a-change-reaches-is-what-is-mutated.md), [0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md) |
| `GH_TOKEN` | The token `doctor --online` asks GitHub with where `GITHUB_TOKEN` is not set | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `PRE_COMMIT_FROM_REF`, `PRE_COMMIT_TO_REF`, `PRE_COMMIT_LOCAL_BRANCH`, `PRE_COMMIT_REMOTE_BRANCH` | The commits being pushed, where the pre-commit framework runs `pre-push` and git's lines are not handed over | [0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md) |
| `COMPOSER_VENDOR_DIR`, `COMPOSER_BIN_DIR` | Where Composer installed the project's packages and their scripts, where the project moves them | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `XDEBUG_MODE` | Which Xdebug modes are on, which `doctor` reports where it slows the tests | [0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md) |
| `PHP_INI_SCAN_DIR`, `PATH` | Kept and extended for the processes the Pest runner starts: the directory its memory cap is added to, and where its binaries are found | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`, `AWS_ROLE_ARN` | The S3 proof store's credentials, and its only ones: no `~/.aws` file, instance, container or web identity role is read. With `AWS_ROLE_ARN` set, those keys assume that role | [0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md) |
| `GOOGLE_APPLICATION_CREDENTIALS` | The external-account credentials file `google-github-actions/auth` writes, which the `gcs` store exchanges the CI's token through, impersonating the service account it names; only its `file` and `url` credential sources are read, and a file holding a service-account key or any other long-lived credential is refused (exit 2) | [0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `MUTATION_GATE_GCS_TOKEN`, `MUTATION_GATE_AZURE_TOKEN` | A ready bearer token for the `gcs` or `azure` store, which a CI with a federation of its own hands over | [0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `ACTIONS_ID_TOKEN_REQUEST_URL`, `ACTIONS_ID_TOKEN_REQUEST_TOKEN`, `AZURE_TENANT_ID`, `AZURE_CLIENT_ID` | The `azure` store's federation: GitHub's OIDC token, asked for the audience `api://AzureADTokenExchange`, exchanged at Microsoft Entra ID for the tenant and client `azure/login` reads | [0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `ACTIONS_ID_TOKEN_REQUEST_URL`, `ACTIONS_ID_TOKEN_REQUEST_TOKEN`, `MUTATION_GATE_GCS_PROVIDER`, `MUTATION_GATE_GCS_SERVICE_ACCOUNT` | The `gcs` store's federation on GitHub Actions: GitHub's OIDC token, asked for the provider's default audience, exchanged at `sts.googleapis.com` through the workload identity provider `projects/<number>/locations/global/workloadIdentityPools/<pool>/providers/<id>`, then for the token of the service account `<name>@<project>.iam.gserviceaccount.com` at `iamcredentials.googleapis.com`; any other provider or service account is refused (exit 2). It goes before `GOOGLE_APPLICATION_CREDENTIALS` where both are set | [0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md) |
| `MUTATION_GATE_SLACK_URL`, `MUTATION_GATE_DISCORD_URL`, `MUTATION_GATE_WEBHOOK_URL` | The webhook URLs of the `slack`, `discord` and `webhook` reporters, unless their `with.urlEnv` names other variables | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `MUTATION_GATE_WEBHOOK_SECRET` | Signs each `webhook` request, with the time it was sent, as `X-Mutation-Gate-Signature`, unless `with.secretEnv` names another variable | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `OTEL_EXPORTER_OTLP_ENDPOINT`, `OTEL_EXPORTER_OTLP_HEADERS`, `OTEL_SERVICE_NAME`, `OTEL_RESOURCE_ATTRIBUTES` | Where and how the `otlp` reporter sends | [0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md) |
| `MUTATION_GATE_RESULTS` | Set by the Pest adapter for its own plugin, by the PHPUnit, PhpSpec and Codeception runners for their extensions, and by the Testo runner for its plugin; not for users | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md), [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md), [0027](../decisions/0027-codeception-phpspec-and-testo-get-native-runners.md) |
| `MUTATION_GATE_PRUNED` | Set by the Pest and Infection adapters for the lines `pest:patch` and `infection:patch` write: the file listing the mutators a run leaves out of the files it prunes them in; not for users | [0025](../decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md) |
| `MUTATION_GATE_MUTATORS` | Set by the Pest adapter for its own plugin: the file of bridges to the registered mutators the config turns on; not for users | [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `MUTATION_GATE_TIMED_TESTS` | Set by the Pest adapter for its own plugin in a coverage run: the file each process appends every test it finished to, with how long it took, so the map knows a test that ran no line; not for users | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md), [0037](../decisions/0037-a-value-is-covered-by-the-tests-that-read-it.md) |
| `MUTATION_GATE_SOURCE_PINNED` | Set by the patched Pest plugin in a mutant's own run where its mutator is pinned by source, so the run serves the mutated file through pest-plugin-mutate's wrapper; not for users | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md), [0021](../decisions/0021-mutators-are-written-once-and-first-party-sets-can-leave.md) |
| `MUTATION_GATE_MUTANT_FLOOR` | Set by the Infection adapter for every Infection run, for the lines `infection:patch` writes into Infection: the least seconds one mutant may run; not for users | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `MUTATION_GATE_MUTANT`, `MUTATION_GATE_MUTATED`, `MUTATION_GATE_GUARD` | Set by the PHPUnit, PhpSpec, Codeception and Testo runners for each mutant's run: the file its override serves the mutated file in place of, the mutated file, and where the override and the extension or plugin say what they served; not for users | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `MUTATION_GATE_SHARED_COVERAGE`, `MUTATION_GATE_SUITE_SECONDS`, `MUTATION_GATE_MUTANT_FLOOR`, `MUTATION_GATE_MUTANT_CAP`, `MUTATION_GATE_CANARY`, `MUTATION_GATE_ONLY`, `MUTATION_GATE_VERDICTS` | Set by the Pest adapter for the lines `pest:patch` writes into pest-plugin-mutate: the planning job's coverage map, its suite's seconds, the least and the most seconds one mutant may run, the canary group, the file listing the only mutants a run again makes, and the file of the gate's verdicts on the mutants static analysis checks before their tests; not for users | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md), [0020](../decisions/0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md) |
| `MUTATION_GATE_WARM` | Set by the Pest adapter for a patched run: the directory its warm workers share, where each mutant's run forks from them; not for users | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |
| `PEST_MUTATION_TESTING`, `PEST_MUTATION_FILE` | Set by Pest's mutation plugin in a mutant's run, and read by the gate's own plugin there; not for users | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md) |
| `MUTATION_GATE_NAMES`, `MUTATION_GATE_ORDER`, `MUTATION_GATE_ORDER_OF`, `MUTATION_GATE_STOP_AFTER`, `MUTATION_GATE_NARROW`, `MUTATION_GATE_KILL_MATRIX` | Set by the Pest adapter for its own plugin: the file it writes each test's name to, the directory it reads each mutant's order from, the mutated copy whose order a replay of a kill's own run takes, how many tests that replay runs before it stops, whether a run loads only the test files each mutant's covering tests need, and how much of the kill matrix a run records; not for users | [0004](../decisions/0004-pest-and-infection-behind-one-runner-port.md), [0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md), [0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md) |
| `MUTATION_GATE_MUTANT_START_UP`, `MUTATION_GATE_TIGHTER_FLOOR`, `MUTATION_GATE_TIGHTER_MUTATORS` | Set by the Pest and Infection adapters for the lines their patches write: how long a run of no test took to start on the runner's machine, which each mutant's limit is laid on, and the lower floor of the silence limit and the mutators `timeouts.tighter` lists; not for users | [0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md) |
| `TEST_TOKEN`, `UNIQUE_TEST_TOKEN`, `PARATEST`, `LARAVEL_PARALLEL_TESTING` | Set, as paratest sets them for its workers, for each process the PHPUnit, PhpSpec, Codeception and Testo runners judge mutants in and the Pest runner tries its unexecutable mutants in, where the machine has more than one core and they run one per core: the process's place, its place and the run, and `1` for the other two. A suite that shares a database needs one database per token. Laravel creates it, named after its test database with `_test_<token>`, for each test case that refreshes, migrates, truncates or wraps the database in a transaction, so its database user must be allowed to create databases | [0023](../decisions/0023-the-gate-mutates-for-native-runners-and-reuses-what-it-measured.md) |

## What a test process never inherits

The processes the gate starts to run the project's tests start without the
CI's credentials and the secrets the gate reads, so no test and no mutant
can read or send one, and without the variables that would make a process
another run's worker or have a tool print for an AI agent. A `*` stands for
any rest of the name.

- **Every run's:** `AWS_*`, `ACTIONS_*`, `AZURE_*`, `GITHUB_TOKEN`,
  `GH_TOKEN`, `SONAR_TOKEN`, `COMPOSER_AUTH`, `GOOGLE_APPLICATION_CREDENTIALS`,
  `GOOGLE_GHA_CREDS_PATH`, `CLOUDSDK_AUTH_CREDENTIAL_FILE_OVERRIDE`,
  `MUTATION_GATE_GCS_TOKEN`, `MUTATION_GATE_AZURE_TOKEN`,
  `MUTATION_GATE_SLACK_URL`, `MUTATION_GATE_DISCORD_URL`,
  `MUTATION_GATE_WEBHOOK_URL`, `MUTATION_GATE_WEBHOOK_SECRET`,
  `OTEL_EXPORTER_OTLP_HEADERS`, `OTEL_EXPORTER_OTLP_TRACES_HEADERS`,
  `OTEL_EXPORTER_OTLP_METRICS_HEADERS` and `OTEL_EXPORTER_OTLP_LOGS_HEADERS`.
- **The CI's own credentials:** GitLab's `CI_JOB_TOKEN`,
  `CI_REPOSITORY_URL`, `CI_JOB_JWT*`, `CI_REGISTRY_PASSWORD`,
  `CI_DEPLOY_PASSWORD` and `CI_DEPENDENCY_PROXY_PASSWORD`; Buildkite's
  `BUILDKITE_AGENT_ACCESS_TOKEN`, `BUILDKITE_AGENT_TOKEN`,
  `BUILDKITE_AGENT_JOB_API_TOKEN` and `BUILDKITE_AGENT_JOB_API_SOCKET`;
  CircleCI's `CIRCLE_OIDC_TOKEN*`; Azure DevOps' `SYSTEM_ACCESSTOKEN` and
  `AZURE_DEVOPS_EXT_PAT`; Bitbucket's `BITBUCKET_STEP_OIDC_TOKEN`.
- **Another run's:** `MUTATION_GATE_*`, `INFECTION_*`,
  `PEST_MUTATION_*`, `PARATEST*`, `TEST_TOKEN*`, `UNIQUE_TEST_TOKEN*` and
  `LARAVEL_PARALLEL_TESTING*`, which would make a process another run's
  worker or mutant; what the gate's own command sets, the process still
  gets.
- **A coding agent's markers**, which have a tool print for an agent rather
  than as it does in a terminal: `AI_AGENT`, `CLAUDECODE`, `CLAUDE_CODE*`,
  `CURSOR_AGENT`, `GEMINI_CLI`, `CODEX_SANDBOX`, `CODEX_CI`,
  `CODEX_THREAD_ID`, `AUGMENT_AGENT`, `OPENCODE`, `OPENCODE_CLIENT`,
  `AMP_CURRENT_THREAD_ID`, `REPL_ID`, `COPILOT_MODEL`, `COPILOT_ALLOW_ALL`,
  `COPILOT_GITHUB_TOKEN`, `COPILOT_CLI`, `ANTIGRAVITY_AGENT`,
  `PI_CODING_AGENT`, `KIRO_AGENT_PATH` and `PAO_FORCE`.
- **The config's:** each variable `runner.withhold` names, and each a report
  reads its secret from, such as a `with.urlEnv` or `with.secretEnv`.

## The store, for `deliver` and `fetch`

[`deliver` and `fetch`](../guide/concepts/proofs-and-trust.md#credentials-in-a-job-of-their-own)
take the store's whole location from their own environment, never from a
delivery or a config:

| Variable | What it sets |
|----------|--------------|
| `MUTATION_GATE_STORE` | The store: `s3`, `gcs` or `azure`. Unset, `deliver` and `fetch` use no store, and the ledger stays in the Actions cache |
| `MUTATION_GATE_STORE_BUCKET` | `bucket`, for `s3` and `gcs` |
| `MUTATION_GATE_STORE_PREFIX` | `prefix` (`mutation-gate` by default) |
| `MUTATION_GATE_STORE_REGION` | `region`, for `s3` (`us-east-1` by default) |
| `MUTATION_GATE_STORE_ENDPOINT` | `endpoint`, for `s3` alone, and only an `https://` URL |
| `MUTATION_GATE_STORE_ACCOUNT` | `account`, for `azure` |
| `MUTATION_GATE_STORE_CONTAINER` | `container`, for `azure` |
| `MUTATION_GATE_STORE_PUBLIC_CONTAINER` | `publicContainer`, for `azure` |
| `MUTATION_GATE_DEFAULT_BRANCH` | The default branch, where the event payload names none |

The store's credentials are its usual variables: `AWS_ACCESS_KEY_ID` and
`AWS_SECRET_ACCESS_KEY` for `s3`, the federation's or
`MUTATION_GATE_GCS_TOKEN` for `gcs`, and the federation's or
`MUTATION_GATE_AZURE_TOKEN` for `azure`
([ADR-0007](../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)).
