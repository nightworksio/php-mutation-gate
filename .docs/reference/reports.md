# Reports

Every report renders the same verdict
([ADR-0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md)).
Each reporter is registered by a name. Add a file report with `reports` in
the [config](configuration.md#every-key) or `--report=<name>:<path>` on the
command line:

| Name | When it runs | What it writes |
|------|--------------|----------------|
| `console` | Always | The verdict, each tree, new-code set and package's security set, the score of each owner group the change touched, what each suite alone kills where the PHPUnit config declares two or more, the units, the reach, every mutant counted as not killed with its diff, judging tests, hint, and reproduce and explain commands, under its owner group where the project has a CODEOWNERS, the ignores, the mutants proven equivalent, the floors that can rise, failures and warnings, and last what a timed run took and saved and what pruning left out. A sampled tree is said with its estimated score, its interval at `sampling.confidence`, and how many of its units were sampled and are exact |
| `json` | Listed in `reports` | The gate's own report at `path`, `"format": 2`, described by [`resources/report.schema.json`](../../resources/report.schema.json); `why` says why the verdict is what it is and `next` what has to happen to pass, a sentence each; every score and floor in it is a percentage with at most two decimals, truncated; the tests are listed once, and each mutant points at those that cover and killed it; a mutant a static analyser killed has its `rejection`: the analyser, the `file` its finding sits in, and the finding's `code` and `message`; `suites` gives each suite's `covered`, `killed`, `score` and whether it is `exact`; `owners` gives each owner group's `owners`, `score`, `judgement`, `counts` and `mutants`, and is empty without a CODEOWNERS, and `ownerFloors` each owner `owners.floors` holds to a floor, with its `floor`; a timed run adds its `run`, `cost` and `savings`; a run that pruned adds `pruning`: each pruned mutator with its `window` and its `lastSurvivor` where it ever let one through, the `units` and mutants `carried`, `auditSeconds` and `savedSeconds`, and marks each mutant it carried `carriedPruned`; a sampled run adds its `sample`, the `seed`, `confidence` and `margin`, and gives each tree its `estimate`: the `score`, the interval's `lower` and `upper`, its `confidence`, and its `units` `sampled`, `exact` and in `total`, where a tree's `judgement` can be `undecided` |
| `junit` | Listed in `reports` | JUnit XML at `path`: a suite per tree with a `floor` test case, a `new code` suite, a `security` suite with a `floor` test case per package, a `run` suite for failures no floor decides, and an `ignored` suite with a skipped test case per ignored mutant, its message why; a sampled tree whose interval holds its floor fails its `floor` case as `undecided` |
| `sarif` | Listed in `reports` | SARIF 2.1.0 at `path`, for code scanning, with each security mutant's result marked `properties.security: true`, and each ignored mutant a `note` result suppressed with why: `external` for the config's ignores, `inSource` for a runner's own marker; with `CI` unset it also names the repository's root as a `file://` URI, for an editor's SARIF viewer |
| `html` | Listed in `reports` | A self-contained `index.html` and `mutation-report.json` under the `path` directory. `index.html` is the gate's own page of the verdict, rendered from the JSON report: the overview first, with the verdict, a plain sentence on what mutation testing is, why and what to do, the counts each graded good, needs attention or bad with why, each set against its floor, the survivors each with what test would catch it, what each suite alone kills and where the verdict comes from, then a list of the files beside a pane of the one chosen. Each word a reader new to mutation testing may not know has a *What is this?* that links to [reading the report](../guide/reading-the-report.md). `mutation-report.json` is the report in the `mutation-testing-report-schema` format |
| `stryker` | Listed in `reports` | The verdict in the `mutation-testing-report-schema` format at the `path` file, with the source of every mutated file: the file the `html` report writes as `mutation-report.json`, on its own, for Stryker's dashboard and the viewers that read the format |
| `digest` | Listed in `reports` | The digest's overview at `path`: the verdict one fact a line for an agent, as `mutation-gate digest` says it ([reading the report](../guide/reading-the-report.md#the-digest-for-agents)) |
| `gitlab` | Listed in `reports` | GitLab's Code Quality JSON at `path`: an issue per mutant counted as not killed, `major` in a set that failed and `minor` otherwise |
| `sonar` | Listed in `reports` | SonarQube's generic external-issues format at `path`, for SonarQube Server 10.3 or later and SonarQube Cloud: an issue per mutant counted as not killed, at its lines and columns, under the rule SARIF reports it by, or under `survived-security` where it is a surviving security mutant, each rule a medium reliability issue of the engine `mutation-gate`, and `survived-security` a medium security one; the line saying where it wrote also says how many issues are under each top directory |
| `kill-matrix` | Listed in `reports` | CSV at `path`: a record per mutant and covering test, with what the test did with the mutant in place |
| `tests` | Listed in `reports` | JSON at `path`, described by [`resources/tests.schema.json`](../../resources/tests.schema.json), and Markdown beside it: the tests that kill nothing they judged, those never the first to kill, from a full kill matrix those that can go together without losing a kill, and those that assert only existence or shape beside the survivors they let through, with the assertion of value to write |
| `problems` | With `--output=problems` | One `<path>:<line>:<col>: <error\|warning>: <message> [<rule>] <id>` line per result, between `mutation-gate: judging` and `mutation-gate: judged`, for an editor's problem matcher; with `with: {only: changed}`, or `--only=changed`, only the mutants on changed lines |
| `slack` | In CI, on the default branch, when its state changes | A Slack message to the URL `MUTATION_GATE_SLACK_URL` holds, or the variable `with: {urlEnv: …}` names: the change, the trees below their floor, the failures, up to five survivors, and the run. A config never holds a webhook URL itself: `with: {url: …}` is refused for `slack`, `discord` and `webhook` |
| `discord` | In CI, on the default branch, when its state changes | The same as one Discord embed to the URL `MUTATION_GATE_DISCORD_URL` holds, or the variable `with: {urlEnv: …}` names, red, green or grey, mentioning no one |
| `webhook` | In CI, on the default branch, when its state changes | JSON described by [`resources/webhook.schema.json`](../../resources/webhook.schema.json) to the URL `MUTATION_GATE_WEBHOOK_URL` holds, or the variable `with: {urlEnv: …}` names, signed in `X-Mutation-Gate-Signature` ([verifying it](#verifying-a-webhook)) where `MUTATION_GATE_WEBHOOK_SECRET`, or the variable `with: {secretEnv: …}` names, holds a secret |
| `otlp` | Listed in `reports` | The run's trace (`plan`, each `shard <n>` with a span for each step its time went to, and `verdict`) and the verdict's metrics as OTLP/HTTP JSON, to `/v1/traces` and `/v1/metrics` under `with: {endpoint: …}` or `OTEL_EXPORTER_OTLP_ENDPOINT`, with `OTEL_EXPORTER_OTLP_HEADERS`, `OTEL_SERVICE_NAME` and `OTEL_RESOURCE_ATTRIBUTES` |
| `github-annotations` | Under GitHub Actions | Up to 10 error, 10 warning and 10 notice annotations, changed lines first, and one for each cluster of survivors |
| `github-summary` | Under GitHub Actions | The step summary: what a timed run took and saved, what the default branch saved over 30 days, what pruning left out, what each suite alone kills, and every mutant counted as not killed in one table, or one table per owner group where the project has a CODEOWNERS, a cluster of survivors as one row, and every ignored mutant with why in another |
| `github-comment` | On a pull request, with `GITHUB_TOKEN` | One sticky comment, updated in place, headed `sampled` for a sampled run, with what a timed run took and saved and what pruning left out under the verdict, the survivors on changed lines under their owner group where the project has a CODEOWNERS, each user and team mentioned the first time under `owners.mention: first`, up to 20 ignored mutants with why, and what it cost folded at the end, within the 65,536 characters GitHub takes: each list shows fewer entries where it must, and each diff and hint at most 1,500 characters; `with: {identity: …}` names the account it is found by when the token is not `GITHUB_TOKEN` |
| `badge` | In CI, on the default branch | `badge.json`, `trend.json`, `trend.svg`, `savings.json` and `summary.json`, described by [`resources/summary.schema.json`](../../resources/summary.schema.json), in its `path`, or in `--publish-dir` where it names none; a run its budget cut short, or a sampled run, writes none of them. The badge's colours are `badge.colors`, and the commit each trend point records is `with: {commit: …}`, or else the CI's own: `GITHUB_SHA`, `CI_COMMIT_SHA`, `BUILDKITE_COMMIT` or `CIRCLE_SHA1` |

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

## Tests that fail without any mutant

Where an unmutated control that decides a mutant fails, the mutant's tests
fail without it
([ADR-0008](../decisions/0008-a-run-spends-its-time-on-the-riskiest-code-first.md)).
The verdict is `cannot-judge`, exiting 2, and every report says so on a line
of its own, `The tests fail without any mutant: <test>, <test>`, naming the
tests the control recorded failing, with each mutant those tests leave out of
every score listed under it:

- the console and the comment, under *Cannot judge*;
- the JSON report, in `failingUnmutated`: each line, its tests by their places
  in `tests`, and the ids of its mutants; each such mutant's `status` and
  `judgement` are `fails-unmutated`, and it names its failing tests in
  `failingUnmutated`, its `killedBy` empty;
- the HTML report, under *Cannot judge*, each mutant linked;
- the digest, as a `left-out` line beside its `why` line;
- JUnit, as a failed `run` case whose body lists the mutants;
- SARIF, as an `error` result per mutant under the `fails-unmutated` rule.

[Troubleshooting](../guide/troubleshooting.md#fails-unmutated) says how to
make the tests pass.

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

## Survivors and their owners

A project with a CODEOWNERS file has each survivor grouped by the owners of
its file
([ADR-0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md)).
The file is the first of `.github/CODEOWNERS`, `CODEOWNERS` and
`docs/CODEOWNERS`, read as GitHub reads it: on a pull request as the fetched
default branch holds it, so a pull request cannot move its own survivors,
and otherwise at the commit the plan was made on. The last rule matching a
file decides its owner group, such as `@acme/payments @jo`; a file no rule
matches, or whose rule names no owner, is unowned. A line GitHub's syntax
refuses (`!`, `[ ]`, an escaped `#`, an owner that is not `@user`,
`@org/team` or an email address) is skipped with a warning, and a file over
3 MB is not read.

The console, the comment and the step summary say what the code of each
owner group the change touched scores, and list the survivors under their
group, each group headed by its owners and how many survivors it has. The
JSON report's
`owners` gives each group its `owners`, `score`, `judgement`, `said`,
`counts` and `mutants`, the unowned group with no owners; each mutant is in
exactly one group, so the groups add up to the project.

Under `owners.mention: first`, the default, the comment mentions each user
and team in the update that first heads a group of survivors on changed
lines naming it, and names it without a mention in every update after that
([ADR-0049](../decisions/0049-an-owner-is-mentioned-once-and-a-merge-group-trusts-its-pull-request-as-a-merged-one.md)).
The comment ends with a hidden record of who it has mentioned, which each
update reads and carries on. A comment `github-actions[bot]` writes mentions
no team, since `GITHUB_TOKEN` cannot read an organisation's teams; the team
is named without a mention, and `doctor` says so. An email address is never
mentioned, and the console, the step summary and every other report mention
no one. `owners.mention: never` names every owner without a mention.

`owners.floors` holds an owner to a floor of its own, over the code of every
file whose rule names it, in whatever group, matching the owner in any case.
A key that is no owner a rule can write holds nothing, and the run warns of
it. An owner below its floor fails
the run, and every report says so by the owner, its score and its floor:
`Code owned by @acme/payments scores 87.50%, below its floor of 90.00%.`
The console and the comment say each owner with a floor before the groups
the change touched, the JUnit report has a `floor` case for each in its
`owners` suite, and the JSON report's `ownerFloors` gives each its `owner`,
`floor`, `score`, `judgement`, `said`, `counts` and `mutants`.

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
