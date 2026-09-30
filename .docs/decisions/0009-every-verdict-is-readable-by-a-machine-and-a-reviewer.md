# ADR-0009: Every verdict is readable by a machine, a reviewer and a badge, and every survivor says how to reproduce it and what the tests miss

**Status:** Accepted
**Date:** 2026-09-29

## Context

The in-house gate reports through Pest's console output and one exit code.
When a shard fails, a person opens that shard's log, finds the `UNTESTED` block
and works out from the diff what the tests did not check. There is nothing for a
machine to read, nothing on the pull request's diff and no history.

A gate other people adopt has more readers:

- **CI systems**, which read JUnit and SARIF;
- **reviewers on a pull request**, who read annotations on the diff and one
  summary comment;
- **the developer who has to fix a survivor**, who needs one command to see it
  fail and a sentence saying what the tests miss;
- **visitors to the repository**, who read the badge;
- **maintainers**, who watch the trend.

All of it comes from the same normalised records (ADR-0004), so every reader
sees the same verdict.

## Decision

1. **The Reporter port takes the whole verdict.** One immutable value holds:
   - every tree, with its declared floor, baseline floor, score, counts by
     status and verdict: *passed*, *failed* or *nothing to mutate*;
   - the new-code set;
   - every unit, and whether its result was run, proved or carried;
   - every mutant's record, with its hint and reproduce command;
   - the reach and its reasons;
   - warnings: unheld hot paths (ADR-0005), and expired or expiring ignores
     (ADR-0008);
   - failures that belong to no floor: stale ignores (ADR-0008) and held paths
     their group does not cover (ADR-0005).

   Which reporters run:
   - **The console** always.
   - **File reports**, listed in `reports` (ADR-0002): each entry is
     `{"use": <name or class>, "path": <file or directory>, "with": <options>}`.
     The built-in names are `json`, `junit`, `sarif` and `html`, each needing
     a `path`. `reports` is empty by default, and `--report=<name>:<path>` adds
     one for a single command.
   - **GitHub annotations and the step summary** (written to
     `GITHUB_STEP_SUMMARY`) whenever `GITHUB_ACTIONS` is set.
   - **The sticky PR comment** under GitHub Actions on a `pull_request` event
     when `GITHUB_TOKEN` is set, as the package's action and reusable workflow
     set it.
   - **The badge and the trend** when the verdict runs in CI on the default
     branch (decision 5).

   A reporter that fails to write says so, and it does not change the verdict's
   exit code.

2. **The formats a machine reads.**
   - **JSON** (`json`), the gate's own format with a `"format": 1` field. Its
     schema is generated like the config's and committed at
     `resources/report.schema.json`. It carries everything in the verdict, and
     it is public API (ADR-0011).
   - **JUnit XML** (`junit`). One `<testsuite>` per tree, and one for new code.
     In each suite, one `<testcase>` stands for its floor. It fails exactly
     when the gate fails that tree, and the failure body lists every mutant it
     counts as not killed, with line, mutator, diff, hint and reproduce
     command. A tree at 80% that passes its floor of 80 does not show
     as a failed test. One more suite, `run`, holds a `<testcase>` for each
     failure that belongs to no floor. JUnit failures match gate failures one
     to one.
   - **SARIF 2.1.0** (`sarif`).
     - One run, with the tool named `mutation-gate`.
     - Four rules: `survived`, `uncovered`, `unjudged` and `flaky`. The
       `unjudged` rule reports both unjudged mutants and those too slow to
       judge.
     - Each result is at the mutant's file and lines. Its level is `error` when
       the mutant is in a set that failed (new code, or a tree below its floor)
       and `warning` otherwise.
     - Each result carries a digest of the gate's id as
       `partialFingerprints.primaryLocationLineHash`, the one fingerprint
       GitHub code scanning reads. Code scanning can then match a result across
       commits when code above the mutant moves.
     - The README says to upload it with `github/codeql-action/upload-sarif`.

3. **What GitHub shows on the pull request.**
   - **Line annotations.** Written as workflow commands, which need no
     permissions. Property values escape `%`, `:`, `,` and line breaks, and the
     message escapes `%` and line breaks:

     ```text
     ::error file=src/Money.php,line=42,title=Mutant survived%3A LessThan::No test uses a value at the boundary of `$amount < $limit`. Reproduce: vendor/bin/mutation-gate reproduce 3f9a1c2b7d04
     ```

     Errors mark mutants in a failing set, and warnings mark the rest. GitHub
     keeps at most 10 error, 10 warning and 10 notice annotations per step, and
     50 per job, and drops the rest silently. So the gate writes all its
     annotations from one step, ranked: changed lines first, then trees that
     failed. The Infection adapter turns Infection's own annotations off
     (ADR-0004), so they do not use up that step's share. The step summary
     always carries the full table.
   - **The sticky PR comment.**
     - **Where it lives.** One comment per pull request, found by the hidden
       marker `<!-- mutation-gate -->` among comments by the token's identity. It
       is updated in place on every run, passing runs included, so an old
       failure never lingers. The token needs `pull-requests: write`.
     - **What it holds:**
       - the verdict;
       - each tree's floor and score, with the change against the base;
       - the new-code score;
       - up to 20 survivors on changed lines, each with its diff, hint and
         reproduce command;
       - unjudged and flaky mutants;
       - warnings;
       - floors that must or can rise (ADR-0003);
       - a link to the run and its HTML report.
     - **Fork pull requests.** The token GitHub gives a `pull_request` run from
       a fork is read-only, so no comment is written. The step summary carries
       the same content, and the reporter says why without failing. The README's
       examples use `pull_request`, not `pull_request_target`, which would run
       the fork's code with a token that can write.
     - **Other CIs** have no comment. The console, JUnit and the HTML report
       carry the same content there.

4. **The HTML report is Stryker's viewer, carried in the package.** The HTML
   reporter (`html`, whose `path` is a directory) writes a report in the open
   `mutation-testing-report-schema` format and a single self-contained page.
   The page embeds the `mutation-testing-elements` viewer, which is vendored
   and inlined, so the page loads nothing from a network. The viewer is the
   same one Infection's HTML log embeds.

   The viewer computes its own score: killed and timed out over those plus
   survived and no coverage, leaving out every other status. Each mutant is
   therefore given the viewer status that the gate's score treats the same
   way, so the page shows the gate's score. The gate's own status goes in
   `statusReason`.

   | Gate status | Viewer status |
   |-------------|---------------|
   | killed, and errored | `Killed` |
   | killed by timeout | `Timeout` |
   | survived, unjudged, flaky, and too slow to judge | `Survived` |
   | uncovered | `NoCoverage`, or `Ignored` under `uncovered: exclude` |
   | ignored, and ignored by a native marker | `Ignored` |

   Judging tests, hints and reproduce commands go in each mutant's
   `description`. The schema requires a column for each location. The gate
   takes it from the file's tokens, and a mutant it cannot place there spans
   its lines from the first column to the end. The viewer's licence
   (Apache-2.0) is shipped with it.

5. **The badge and the trend on the default branch.**
   - **The badge.** The verdict writes `badge.json` for shields.io's endpoint
     badge:

     ```json
     { "schemaVersion": 1, "label": "mutation score", "message": "87.41%", "color": "green" }
     ```

     The score is over the whole project's mutants, by the same formula as a
     tree's (ADR-0003). Colours follow `badge.colors`, a map from a shields.io
     colour to the lowest score that earns it, which defaults to:
     - brightgreen at 90 or above;
     - green at 80;
     - yellow at 70;
     - orange at 60;
     - red below the lowest.
   - **The trend.** `trend.json` gets one entry per run on the default branch:
     commit, time, the project's score and each tree's score. It keeps the
     newest 500. The verdict also draws `trend.svg`, a plain sparkline with no
     script, which the step summary and the HTML report show.
   - **Where they are written.** Only a verdict in CI (the `CI` environment
     variable is set) on the default branch writes them, into
     `--publish-dir=<dir>`, an option of `verdict` and of a one-process run,
     `.mutation-gate/publish` by default. It appends to the `trend.json` it
     finds there, so the files published last time are restored into that
     directory first.
   - **What never updates them.** A run cut short by its budget (ADR-0008).
   - **Where they are published.** The reusable workflow (ADR-0011) restores
     the three files from a branch named `mutation-gate` in the same
     repository before its verdict, and its publish job pushes them back
     through GitHub's contents API with the job's own token and no custom
     author. GitHub signs commits made that way, so a ruleset that requires
     signatures accepts them. The publish job runs only on the default branch,
     and it is the only job that needs `contents: write`. The one-step action
     leaves them in `--publish-dir`, because its job would need
     `contents: write` on pull requests too. The README's badge line is:

     ```markdown
     ![mutation score](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/<owner>/<repo>/mutation-gate/badge.json)
     ```

     On other CIs, restoring and publishing `--publish-dir` is the pipeline's
     job.

6. **Every survivor has a one-line reproduce command.** In every report it is
   `vendor/bin/mutation-gate reproduce <id>`. It works on any machine with the
   same code, because the id holds no absolute path (ADR-0004).

7. **Every survivor says what the tests miss.** Each runner adapter maps its
   native mutator names to a family. Each family has one sentence, filled in
   from the diff and from the enclosing function, found by the file's tokens.
   The sentence names the judging tests (ADR-0004), where there are any: up to
   three, then *and n more*.

   | Family | Example | What the hint says |
   |--------|---------|--------------------|
   | Boundary | `<` → `<=` | No test uses a value at the boundary of `$amount < $limit`. |
   | Condition | `===` → `!==`, `if ($x)` → `if (! $x)` | These tests run the condition, but none asserts on anything that depends on it. |
   | Logical | `&&` → `\|\|` | No test has exactly one side of the `&&` true. |
   | Arithmetic | `+` → `-` | No test checks the result of this calculation with a non-zero operand. |
   | Return value | `return $total;` → `return 0;` | Tests call `total()`, but none asserts on what it returns. |
   | Removed call | `$this->save($order);` removed | Every test passes without the call to `save()`, so nothing asserts on its effect. |
   | Literal | `3` → `4`, `true` → `false` | No test depends on this value being `3`. |
   | Collection | an array item removed, a loop given no items | No test notices the item missing, or runs the loop with items and checks the outcome. |
   | Exception | `throw` removed | No test expects this exception. |
   | Unwrap | `array_values($xs)` → `$xs` | No test passes a value `array_values()` would change. |
   | Visibility | `public` → `protected` | Nothing outside the class calls this. It can be narrower. |
   | Uncovered (a status, not a family) | | No test runs this line. |

   A mutator with no family gets the general sentence: *These tests run line
   N, but none fails when it becomes `<mutated line>`.* A table test fails when
   a mutator of a supported runner version has no family and is not explicitly
   marked as having none.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Our own HTML viewer** | A UI to build and maintain. Stryker's viewer is maintained, has a published schema, and is familiar to anyone who has used Infection. |
| **The Stryker dashboard as the report's home** | An outside service with its own account, and nothing there for Pest. An extension can add it. |
| **Annotations through the Checks API** | More annotations per request, but it needs `checks: write` and an API client. Workflow commands need nothing, and the step summary holds the full list. |
| **A review comment on every surviving line** | One notification per survivor per push. One sticky comment says it once and keeps saying it correctly. |
| **JUnit with a failing test case per surviving mutant** | At a floor below 100 the gate passes with survivors, and a JUnit report full of failures would contradict it. |
| **Badge and trend through a gist, GitHub Pages or a commit to the default branch** | A gist needs a personal token. Pages needs a site and a deploy job. A commit to the default branch meets its protection rules and fills its history. A data branch written through the contents API needs neither. |
| **Publishing from the one-step action** | Its one job runs on pull requests as well, so it would need `contents: write` wherever a pull request's code runs. The reusable workflow keeps that permission in a job that runs only on the default branch. |
| **Hints written by a language model** | Needs a network and a key, and gives a different sentence each run. A hint has to be reproducible and reviewable, like the verdict. |

## Consequences

**One verdict, many readers.** JSON, JUnit, SARIF, annotations, comment, HTML
and badge are all rendered from the same value, and a test holds every
reporter to the same example verdict.

**A developer's first contact with a survivor is actionable**: where it is,
what changed, which tests ran it, what they miss and one command to see it.

**The default branch gets a visible history**, on a branch that holds nothing
but data.

## Related

- [ADR-0003](0003-a-floor-only-rises.md): the score the badge shows
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): records, ids and mutator families
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): hot-path warnings and reach reasons
- [ADR-0008](0008-a-run-spends-its-time-on-the-riskiest-code-first.md): unjudged, flaky and ignored mutants
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the action and workflow that post and publish
