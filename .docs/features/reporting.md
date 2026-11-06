# Reporting

Every report renders the same verdict, for its own reader: the developer at
the terminal, the reviewer on the pull request, the agent, the machine and the
team watching the default branch
([ADR-0009](../decisions/0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md)).
[Reports](../using/reports.md) lists each reporter and its options, and
[reading the report](../using/reading-the-report.md) says what each term
means.

## Every survivor, explained

Each mutant the tests let through comes with:

- its diff, the tests that ran it, and whether it is on a changed line;
- a sentence saying what the tests miss, from its mutator's hint;
- `Try: …`, a concrete way to kill it, found by rules from its diff and its
  file
  ([ADR-0101](../decisions/0101-rules-find-a-concrete-way-to-kill-each-survivor.md));
- the one command that reproduces it, and `explain`, which shows its diff,
  its tests, their outcomes and its history without running anything
  ([ADR-0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md)).

Survivors with one cause are clustered into one item with one suggested
test, a surviving removal is flagged as possibly dead code, and `stub` writes
a failing Pest or PHPUnit test or PhpSpec example to fill in
([ADR-0015](../decisions/0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md),
[ADR-0022](../decisions/0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md),
[ADR-0025](../decisions/0025-unchanged-code-is-pruned-and-tests-are-judged-by-their-assertions.md)).

## On the pull request

- **A sticky comment**, posted when the plan is made with the units it
  mutates, the time it should take and the changed lines no test runs, then
  updated in place with the verdict, the files that fell most since the base,
  and a link to the run's HTML report. On GitLab it is a merge request note,
  on Bitbucket a pull request comment
  ([ADR-0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md),
  [ADR-0048](../decisions/0048-the-gitlab-and-bitbucket-comments-relay-the-delivery-and-bitbucket-finds-its-own-by-editing.md)).
- **Code owners**: survivors are grouped by their owners from the CODEOWNERS
  at the base, each group's score is reported, and each owner is mentioned
  once per pull request
  ([ADR-0049](../decisions/0049-an-owner-is-mentioned-once-and-a-merge-group-trusts-its-pull-request-as-a-merged-one.md)).
- **Line annotations** on GitHub, survivors in GitLab's Code Quality report,
  and Bitbucket Code Insights.
- **Cost**: what the run planned, measured and spared, and money at the
  team's rate where one is set
  ([ADR-0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md)).

## Files

- **JSON**, the gate's own report under a published schema; **JUnit**;
  **SARIF**, for code scanning; **SonarQube**'s external issues; and the
  **Stryker** report format
  ([ADR-0046](../decisions/0046-the-stryker-report-is-a-reporter-of-its-own.md)).
- **An HTML report**: one self-contained page with the verdict, the counts,
  every survivor, and a heatmap of every file's and function's score against
  the base
  ([ADR-0100](../decisions/0100-a-heatmap-scores-every-file-and-function-against-the-base.md)).
- **A digest for agents**: the verdict one fact a line, at a cost in tokens
  that does not grow with the run, with depth one file, mutant or cluster at
  a time, from `mutation-gate digest`.
- **The tests report**: tests that cover code and kill none of it, tests whose
  every kill another test makes too, weak assertions paired with the survivors
  they let through, and a score per suite; and the **kill matrix**, as CSV and
  in the JSON and HTML reports
  ([ADR-0014](../decisions/0014-every-test-is-judged-by-what-it-kills.md)).

## The default branch and the organisation

- **A badge and a trend**: a shields.io endpoint with the gate's helix as its
  logo, and an SVG of the score run by run, published by the default branch.
- **Chat alerts** to Slack, Discord or a webhook when the default branch
  fails, cannot be judged, or recovers; **OpenTelemetry** traces and metrics
  of each run
  ([ADR-0016](../decisions/0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md)).
- **What every run saved** against a full run in one job, and over the last
  30 days
  ([ADR-0017](../decisions/0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md)).
- **An organisation dashboard**: `mutation-gate dashboard` builds a static
  site of every repository's trends, floors and savings, its lowest files, and
  each repository's costliest mutators; a public site never shows a private
  repository
  ([ADR-0024](../decisions/0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md)).
- **What each mutator costs and finds**: `mutation-gate mutators` reads it
  from the ledger, and `config:suggest` proposes config changes with their
  evidence, as a diff it never applies; `suggest.enabled: false` turns every
  suggestion off
  ([ADR-0076](../decisions/0076-a-ledger-tallies-what-each-mutator-costs-and-finds-and-config-suggest-proposes-changes.md),
  [ADR-0104](../decisions/0104-suggestions-can-be-turned-off-and-each-ci-has-a-context.md)).
