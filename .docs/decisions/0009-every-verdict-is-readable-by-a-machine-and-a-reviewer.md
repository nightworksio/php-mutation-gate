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
   - every test, with the mutants it covers and those it killed (ADR-0014);
   - the reach and its reasons;
   - warnings: unheld hot paths (ADR-0005); expired or expiring ignores
     (ADR-0008); and a shard target the plan could not meet, an ignore a
     proof of equivalence makes redundant, a check for equivalence that could
     not run, and a proof store opened read-only (ADR-0013);
   - failures that belong to no floor: stale ignores (ADR-0008) and held paths
     their group does not cover (ADR-0005).

   Which reporters run, each registered by its name in the package's own
   extension (ADR-0001):
   - **The console** (`console`) always. With `--output=problems` it prints
     one line per result for editors instead of its table (ADR-0015,
     decision 6).
     Every line the gate's console writes, to standard output or standard
     error, starts no command in a CI runner's log. Split at `\r\n`, `\r`
     and `\n`, as the runners split it, a line whose trimmed text starts with
     `::` gets a `\` before it, and `##[` or `##vso[` anywhere gets a space
     before its `[`. So text from outside, such as a test's name, a
     process's output or an analyser's message, reaches the log only as text,
     and so does text the gate prints for a person to read or copy, such as
     `config:show` and `init --stdout`, where a `##[` a project's path holds
     is printed `## [`. Text from outside inside a line, such as the
     analyser's rejection, is also one plain line with no control or format
     character. Two kinds of output are written past the console: the gate's
     own commands, the annotations with their encoding (decision 3) and the
     Azure plan's output variable; and a plan printed for a CI to read, JSON
     with each `#` written `\u0023`, which every reader decodes back and no
     log reads a command in.
   - **File reports**, listed in `reports` (ADR-0002): each entry is
     `{"use": <name or class>, "path": <file or directory>, "with": <options>}`.
     The built-in names are `json`, `junit`, `sarif`, `html`, `tests` and
     `kill-matrix` (ADR-0014), `gitlab`, GitLab's Code Quality JSON
     (ADR-0016), and `sonar`, SonarQube's generic external-issues format
     (ADR-0028), each needing a `path`. `reports` is empty by default, and `--report=<name>:<path>` adds
     one for a single command.
   - **GitHub annotations** (`github-annotations`) and **the step summary**
     (`github-summary`, written to `GITHUB_STEP_SUMMARY`) whenever
     `GITHUB_ACTIONS` is set.
   - **The sticky PR comment** (`github-comment`) under GitHub Actions on a
     `pull_request` event when `GITHUB_TOKEN` is set, as the package's action
     and reusable workflow set it.
   - **The badge and the trend** (`badge`) when the verdict runs in CI on the
     default branch (decision 5).
   - **Chat alerts,** `slack`, `discord` and `webhook`, when listed in
     `reports` and the verdict runs in CI on the default branch and changes
     its state, and **OpenTelemetry**, `otlp`, when listed (ADR-0016).

   A file report reads the `path` of its entry from its options, beside
   `with`.

   A reporter that fails to write says so, and it does not change the verdict's
   exit code.

2. **The formats a machine reads.**
   - **JSON** (`json`), the gate's own format with a `"format": 2` field. Its
     schema is generated like the config's and committed at
     `resources/report.schema.json`. It carries everything in the verdict, and
     it is public API (ADR-0011). A mutant proven equivalent has the judgement
     `equivalent`, while its runner's `status` stays `survived`, and each
     killed mutant names the test that killed it first, where one is known
     (ADR-0013). It lists the tests once, in a `tests`
     table, and gives each mutant `coveredBy` and `killedBy` as indices into
     it (ADR-0014), so each test's id is written once, however many mutants
     it covers, and the report grows with the mutants and the tests, not
     their product. Beside those:
     - At the top: `format`, `judgement`, `cutShort`, `uncovered`, the
       project's `score` and `counts` by judgement, then `trees`, `newCode`,
       `mutants`, `reach`, `warnings` and `failures`.
     - Each tree has its `path` and `package`; its `declared` floor or the
       reason it is `exempt`; its `baseline`, `floor`, `score`, the `base`'s
       score and the floor it `raised` to, where each is known; its
       `judgement` and `counts`; its `units`, each with its `path`, the
       `group` or `filter` that holds it, and its `origin`; and the ids of its
       `mutants`.
     - Each new-code set has its `package`, `floor`, `score` where it has one,
       `judgement`, `counts` and the ids of its `mutants`.
     - Each mutant is written once, at the top: `id`, `file`, `line`, `end`,
       `mutator`, `family`, `diff`, the runner's `status`, the gate's
       `judgement`, the `reason` its record gives, the `rejection` that killed
       it where a static analyser did, with the analyser, the file its
       finding sits in and the finding's code and message (ADR-0020),
       `changedLine`, `judgedBy`, the indices of the tests that judged it,
       where they are not every test that covers it, as a held unit's
       holding tests are not, its `hint`, its `reproduce`
       command, and the `seconds` it ran and the `limit` it was allowed where
       the runner says.
     - A value that is not known is left out, never written as null.
     - The mutants a run reported in full come first, then the kills a
       ledger proved. A ledger keeps a kill's id, line, mutator and killing
       tests, so a proved kill has no `family`, `diff` or `end`.
     - Every score and floor is a percentage: a number from 0 to 100 with at
       most two decimals, truncated from hundredths, as `83.41` or `100.0`.
   - **JUnit XML** (`junit`). One `<testsuite>` per tree, and one for new code.
     In each suite, one `<testcase>` stands for its floor. It fails exactly
     when the gate fails that tree, and the failure body lists every mutant it
     counts as not killed, with line, mutator, diff, hint and reproduce
     command. A tree at 80% that passes its floor of 80 does not show
     as a failed test. One more suite, `run`, holds a `<testcase>` for each
     failure that belongs to no floor. JUnit failures match gate failures one
     to one.
     - A tree's suite is named by its path, and its test case is
       `name="floor"` with the tree's path as `classname`, so a CI's history
       follows it from run to run.
     - The `new code` suite has a test case per package, named by its path.
     - An exempt tree's test case is skipped, with its reason.
     - The `run` suite names each test case by its failure, and is left out
       when there is none.
   - **SARIF 2.1.0** (`sarif`).
     - One run, with the tool named `mutation-gate`. Paths are relative to
       the repository. A local run (`CI` unset) also gives the absolute root
       as `originalUriBaseIds.SRCROOT`, for editors (ADR-0015, decision 9).
     - Four rules: `survived`, `uncovered`, `unjudged` and `flaky`. The
       `unjudged` rule reports unjudged mutants and those too slow or too
       heavy to judge. A mutant proven equivalent is not a result (ADR-0013).
     - Each result is at the mutant's file and lines. Its level is `error` when
       the mutant is in a set that failed (new code, or a tree below its floor)
       and `warning` otherwise.
     - Each result carries the gate's id, itself a digest (ADR-0004), as
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

     Errors mark mutants in a failing set, and warnings mark the rest; the
     title names the gate's judgement and the mutator, as *Mutant survived:
     LessThan*. Where the mutant's record gives a reason, such as why it
     was left unjudged, the message starts with it, before the hint, as the
     step summary's row does. Each warning of the verdict (decision 1) is a notice. GitHub
     keeps at most 10 error, 10 warning and 10 notice annotations per step, and
     50 per job, and drops the rest silently. So the gate writes all its
     annotations from one step, at most 10 of each, ranked: changed lines
     first, then sets that failed. The Infection adapter turns Infection's own annotations off
     (ADR-0004), so they do not use up that step's share. The step summary
     always carries the full table.
   - **The sticky PR comment.**
     - **Where it lives.** One comment per pull request, found by the hidden
       marker `<!-- mutation-gate -->` among comments by the token's identity:
       the user GitHub's `/user` names, or `github-actions[bot]` for
       `GITHUB_TOKEN`, which cannot read `/user`. The option `identity` names
       another. It is updated in place on every run, passing runs included, so
       an old failure never lingers. The token needs `pull-requests: write`.
     - **Planned, then judged.** `plan`, and `run` without a plan, post it
       first, in a *planned* state: the units to be mutated, the estimate
       (ADR-0017), and the changed lines no test runs. The coverage map holds
       each executable line no test ran beside those some test ran, so a
       changed comment or blank line is never one of them. In order: the
       marker; `## mutation-gate: planned`; *Mutating 12 units in 3 shards:
       about 6m wall, 14m runner time (94% measured)*, or *Nothing to mutate*
       where the plan has no units; the units, folded as
       `<details><summary>Units (12)</summary>`; *Changed lines no test
       covers (3)* as a heading over one `path:line` a line; and the link to
       the run. Each list is cut like the verdict's. The plan file holds the
       untested lines as `untested`. The flows write the state with
       `PullRequestComment::planned(PlannedWork)`, where `PlannedWork` holds
       the plan, its estimate, the share of the estimate that was measured,
       and the untested lines by file. The verdict replaces that state with
       what follows (ADR-0019).
     - **What it holds:**
       - the verdict, with the line saying what the run saved directly under
         it (ADR-0017);
       - each tree's floor and score, with the change against the base;
       - the new-code score;
       - up to 20 survivors on changed lines, each with its diff, hint and
         reproduce command;
       - unjudged and flaky mutants;
       - warnings;
       - floors that must or can rise (ADR-0003);
       - a link to the run and its HTML report;
       - the run's cost, collapsed (ADR-0016).

       In that order: the marker; a heading with the verdict and the
       project's score; the line saying what the run saved; a table of the
       trees (tree, floor, score, change against the base, result); a line for
       each new-code set; each survivor on a changed line as a folded block
       with its diff, hint and reproduce command; a table of the unjudged and
       flaky mutants; the failures; the warnings; the floors that can rise
       with `vendor/bin/mutation-gate baseline --write`; the link; and the
       cost. A list longer than 20 says how many more there are. GitHub takes
       at most 65,536 characters in a comment, so where the comment would hold
       more, each list shows half as many entries, and again, down to none,
       each saying how many it left out. Each diff and hint shows at most
       1,500 characters, so one long line of code cannot fill it alone.
     - **The step summary** has the same layout without the marker, and lists
       every mutant counted as not killed in one table. A step's summary holds
       at most 1 MiB, so a table that does not fit is cut and says how many
       rows the JSON report holds beyond it.
     - **What the project wrote stays text.** Paths, diffs, test names and
       reasons come from the project under test, so every one is escaped
       where it lands: no markup, link, bare address, mention or table cell
       in Markdown, no control or format character, a bidirectional override
       included, and a diff's fence is longer than any run of its fence
       character inside it. The config's currency is escaped the same way. In
       a log, no line starts a command of GitHub, Azure Pipelines or TeamCity:
       not in any case, not after the console's colour or a character a
       runner trims or skips, and not across the writes that make one line. Text from outside reaches a
       terminal or a CI's log with no control or format character but a tab
       and the ends of lines, dropped before the console adds its own colour
       and before a workflow command is escaped, so the only escape sequences
       the gate writes are the colours of its console.
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
   | killed, errored, killed by static analysis (ADR-0020), and killed by the memory cap (ADR-0004) | `Killed` |
   | killed by timeout | `Timeout` |
   | survived, unjudged, flaky, too slow to judge, and too heavy to judge | `Survived` |
   | uncovered | `NoCoverage`, or `Ignored` under `uncovered: exclude` |
   | ignored, and ignored by a native marker | `Ignored` |
   | equivalent, proven (ADR-0013) | `Ignored` |

   Judging tests, hints and reproduce commands go in each mutant's
   `description`. Its `coveredBy`, `killedBy` and `testsCompleted`, and the
   report's `testFiles`, come from the kill matrix, so the viewer's test view
   shows which tests kill, which only cover and which cover nothing
   (ADR-0014). The schema requires a column for each location. The gate
   takes it from the file's tokens, and a mutant it cannot place there spans
   its lines from the first column to the end. A kill a ledger proved spans
   its line that way too, and has no `replacement`, since the ledger keeps no
   diff. The viewer's licence
   (Apache-2.0) is shipped with it, and every generated page names the
   viewer, its version and its licence in a comment (ADR-0018).

   - **The files.** Under its `path`, the reporter writes
     `mutation-report.json` (`schemaVersion` 2, Stryker's own thresholds of
     80 and 60) and `index.html`.
   - **The viewer** is `mutation-testing-elements` 3.9.0, carried in
     `resources/mutation-testing-elements` with its licence and a test that
     pins its checksum. The page holds the licence's text in a comment above
     the viewer.
   - **The report in the page** is JSON data in its own script element, with
     every `&`, `<` and `>` escaped, and the page reads it with `JSON.parse`.
     Nothing a diff or a test name holds can end the element.

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
     commit, time, the verdict, the project's score, each tree's score, and
     the run's runner time and full-run time (ADR-0017). The verdict is what
     chat alerts compare against (ADR-0016). It keeps the newest 500. Its
     commit, time and scores are written so:

     ```json
     { "format": 1, "runs": [ { "commit": "3f9a1c2", "time": "2026-09-30T10:00:00Z", "score": 87.41, "trees": { "src": 87.41 } } ] }
     ```

     A score a set does not have is left out, and an entry that is not in
     that shape is dropped when the file is read. The verdict also draws
     `trend.svg`, a plain sparkline with no script, 240 by 40 pixels on a
     scale of 0 to 100, which the step summary and the HTML report show.
   - **Where they are written.** Only a verdict in CI (the `CI` environment
     variable is set) on the default branch writes them, into
     `--publish-dir=<dir>`, an option of `verdict` and of a one-process run,
     `.mutation-gate/publish` by default. It appends to the `trend.json` it
     finds there, so the files published last time are restored into that
     directory first. It also writes `savings.json`, a shields.io endpoint
     with the time saved in the last 30 days (ADR-0017), published with the
     others.
   - **What never updates them.** A run cut short by its budget (ADR-0008).
   - **Where they are published.** The reusable workflow (ADR-0011) restores
     the four files from a branch named `mutation-gate` in the same
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
   same code, because the id holds no absolute path (ADR-0004). Every mutant
   the score counts as not killed has one, so it is also the command that
   judges an unjudged mutant (ADR-0008). The console, HTML and JSON reports
   also give `vendor/bin/mutation-gate explain <id>`, which explains it
   without running anything (ADR-0014).

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

   A mutant the gate judged otherwise than survived gets the sentence of its
   judgement. The sentence of every mutant counted as not killed names its
   judging tests: a survivor's, and a flaky, unjudged, too slow or too heavy
   mutant's, whose tests are the suspects.

   | Judgement | What the hint says |
   |-----------|--------------------|
   | Flaky | Its tests killed it on one run and let it survive on another, so they are the suspects. |
   | Too slow to judge | Its time limit was too short beside its tests' own time, so a timeout says nothing about it. Hold `<file>` with a group of the tests that assert on it, or raise `timeouts.most`. |
   | Too heavy to judge | The unmutated suite holds more than half the memory cap, or was not measured, so the cap says nothing. Raise runner.memory; doctor --measure says what the suite needs. |
   | Unjudged | Nothing judged it, so it counts as not killed. |
   | Ignored | An ignore in the config leaves it out of the score. |
   | Ignored by a native marker | A native ignore marker leaves it out of the score. |
   | Killed | A test fails with it in place. |
   | Killed by static analysis | The static analyser the project runs rejects it, so it could not pass CI, which counts as killed. |
   | Errored | It crashes its tests, which counts as killed. |
   | Killed by timeout | Its tests ran far past their usual time with it in place, so the timeout counts as a kill. |
   | Killed by the memory cap | It ran out of the memory cap, at least twice what the unmutated suite holds, so the cap counts as a kill. |

   The judging tests are named as *It is judged by `A`, `B`, `C` and n more.*

   **As built.** The verdict takes a mutant's judging tests from the kill
   matrix: the tests that cover its line and those its record names as
   killers. For a held unit it keeps those of them its holding tests that
   run it are among, as the shard's result or the unit's proof lists them
   (ADR-0005, decision 10). A held unit's result that lists none, from a
   proof an earlier gate wrote, names none.

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
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): *equivalent, proven*, the first killer, and a shard target the plan could not meet
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): test-level data, the `tests` and `kill-matrix` reports, and `explain`
- [ADR-0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): the problems output, and SARIF's local root
- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): the `gitlab`, chat and `otlp` reporters, the JSON report's `cost` and `run`, and `trend.json`'s `verdict`
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): the savings line, the JSON report's `savings`, `trend.json`'s times and `savings.json`
- [ADR-0018](0018-the-documentation-is-versioned-and-tested-with-the-code.md): the viewer's notice in every HTML report
- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): the comment's planned state
- [ADR-0028](0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md): the `sonar` reporter
