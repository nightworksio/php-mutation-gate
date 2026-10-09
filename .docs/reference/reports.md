# Reports

Every report renders the same verdict
([ADR-0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md)).
Each reporter is registered by a name. Add a file report with `reports` in
the [config](configuration.md#every-key) or `--report=<name>:<path>` on the
command line:

| Name | When it runs | What it writes |
|------|--------------|----------------|
| `console` | Always | The verdict, each tree, new-code set and package's security set, what each suite alone kills where the PHPUnit config declares two or more, the units, the reach, every mutant counted as not killed with its diff, judging tests, hint, and reproduce and explain commands, the ignores, the mutants proven equivalent, the floors that can rise, failures and warnings, and last what a timed run took and saved and what pruning left out |
| `json` | Listed in `reports` | The gate's own report at `path`, `"format": 2`, described by [`resources/report.schema.json`](../../resources/report.schema.json); every score and floor in it is a percentage with at most two decimals, truncated; the tests are listed once, and each mutant points at those that cover and killed it; a mutant a static analyser killed has its `rejection`: the analyser, the `file` its finding sits in, and the finding's `code` and `message`; `suites` gives each suite's `covered`, `killed`, `score` and whether it is `exact`; a timed run adds its `run`, `cost` and `savings`; a run that pruned adds `pruning`: each pruned mutator with its `window` and its `lastSurvivor` where it ever let one through, the `units` and mutants `carried`, `auditSeconds` and `savedSeconds`, and marks each mutant it carried `carriedPruned` |
| `junit` | Listed in `reports` | JUnit XML at `path`: a suite per tree with a `floor` test case, a `new code` suite, a `security` suite with a `floor` test case per package, a `run` suite for failures no floor decides, and an `ignored` suite with a skipped test case per ignored mutant, its message why |
| `sarif` | Listed in `reports` | SARIF 2.1.0 at `path`, for code scanning, with each security mutant's result marked `properties.security: true`, and each ignored mutant a `note` result suppressed with why: `external` for the config's ignores, `inSource` for a runner's own marker; with `CI` unset it also names the repository's root as a `file://` URI, for an editor's SARIF viewer |
| `html` | Listed in `reports` | `mutation-report.json` and a self-contained `index.html` under the `path` directory, shown with Stryker's viewer, with what each suite alone kills above it |
| `gitlab` | Listed in `reports` | GitLab's Code Quality JSON at `path`: an issue per mutant counted as not killed, `major` in a set that failed and `minor` otherwise |
| `sonar` | Listed in `reports` | SonarQube's generic external-issues format at `path`, for SonarQube Server 10.3 or later and SonarQube Cloud: an issue per mutant counted as not killed, at its lines and columns, under the rule SARIF reports it by, or under `survived-security` where it is a surviving security mutant, each rule a medium reliability issue of the engine `mutation-gate`, and `survived-security` a medium security one; the line saying where it wrote also says how many issues are under each top directory |
| `kill-matrix` | Listed in `reports` | CSV at `path`: a record per mutant and covering test, with what the test did with the mutant in place |
| `tests` | Listed in `reports` | JSON at `path`, described by [`resources/tests.schema.json`](../../resources/tests.schema.json), and Markdown beside it: the tests that kill nothing they judged, those never the first to kill, from a full kill matrix those that can go together without losing a kill, and those that assert only existence or shape beside the survivors they let through, with the assertion of value to write |
| `problems` | With `--output=problems` | One `<path>:<line>:<col>: <error\|warning>: <message> [<rule>] <id>` line per result, between `mutation-gate: judging` and `mutation-gate: judged`, for an editor's problem matcher |
| `slack` | In CI, on the default branch, when its state changes | A Slack message to the URL `MUTATION_GATE_SLACK_URL` holds, or the variable `with: {urlEnv: …}` names: the change, the trees below their floor, the failures, up to five survivors, and the run |
| `discord` | In CI, on the default branch, when its state changes | The same as one Discord embed to the URL `MUTATION_GATE_DISCORD_URL` holds, red, green or grey, mentioning no one |
| `webhook` | In CI, on the default branch, when its state changes | JSON described by [`resources/webhook.schema.json`](../../resources/webhook.schema.json) to the URL `MUTATION_GATE_WEBHOOK_URL` holds, signed in `X-Mutation-Gate-Signature` ([verifying it](#verifying-a-webhook)) where `MUTATION_GATE_WEBHOOK_SECRET`, or the variable `with: {secretEnv: …}` names, holds a secret |
| `otlp` | Listed in `reports` | The run's trace (`plan`, each `shard <n>` with a span for each step its time went to, and `verdict`) and the verdict's metrics as OTLP/HTTP JSON, to `/v1/traces` and `/v1/metrics` under `with: {endpoint: …}` or `OTEL_EXPORTER_OTLP_ENDPOINT`, with `OTEL_EXPORTER_OTLP_HEADERS`, `OTEL_SERVICE_NAME` and `OTEL_RESOURCE_ATTRIBUTES` |
| `github-annotations` | Under GitHub Actions | Up to 10 error, 10 warning and 10 notice annotations, changed lines first, and one for each cluster of survivors |
| `github-summary` | Under GitHub Actions | The step summary: what a timed run took and saved, what the default branch saved over 30 days, what pruning left out, what each suite alone kills, and every mutant counted as not killed in one table, a cluster of survivors as one row, and every ignored mutant with why in another |
| `github-comment` | On a pull request, with `GITHUB_TOKEN` | One sticky comment, updated in place, with what a timed run took and saved and what pruning left out under the verdict, up to 20 ignored mutants with why, and what it cost folded at the end, within the 65,536 characters GitHub takes: each list shows fewer entries where it must, and each diff and hint at most 1,500 characters; `with: {identity: …}` names the account it is found by when the token is not `GITHUB_TOKEN` |
| `badge` | In CI, on the default branch | `badge.json`, `trend.json`, `trend.svg` and `savings.json` in `--publish-dir` |

## SonarQube

SonarQube imports the `sonar` report from the path the scanner's
`sonar.externalIssuesReportPaths` names, as in `sonar-project.properties`:

```properties
sonar.externalIssuesReportPaths=build/mutation-sonar.json
```

with `{"use": "sonar", "path": "build/mutation-sonar.json"}` in `reports`.
SonarQube drops an issue on a file outside `sonar.sources`, and `doctor`
names each tree that lies outside it
([ADR-0028](../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md)).

## Survivors and clusters

Every mutant the score counts as not killed carries its reproduce command,
`vendor/bin/mutation-gate reproduce <id>`, and a sentence saying what the
tests miss. The console, JSON and HTML reports also give
`vendor/bin/mutation-gate explain <id>`. A survivor proven equivalent
([ADR-0013](../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md))
is left out of the score and listed as *equivalent, proven*.

Survivors that share one cause form a cluster: changes that overlap within
one statement, or changes of one kind in one function that the same tests
judge
([ADR-0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md)).
The console, the comment, the step summary and the annotations show a
cluster once, with its members' diffs, one hint and
`vendor/bin/mutation-gate stub <cluster id>`. Every member still counts in
the score, and the JSON and SARIF reports keep each as an entry of its own
that names its cluster.

## Verifying a webhook

A signed `webhook` request carries `X-Mutation-Gate-Signature:
t=<unix seconds>,sha256=<hex>`, the HMAC-SHA256 of `<t>.<body>` under the
secret. A receiver checks it before it trusts the body:

```php
[$t, $mac] = sscanf($_SERVER['HTTP_X_MUTATION_GATE_SIGNATURE'] ?? '', 't=%d,sha256=%64s');
$body = file_get_contents('php://input');
$fresh = abs(time() - (int) $t) <= 300;
$valid = hash_equals(hash_hmac('sha256', "{$t}.{$body}", $secret), (string) $mac);

if (! $fresh || ! $valid) {
    http_response_code(401);
    exit;
}
```
