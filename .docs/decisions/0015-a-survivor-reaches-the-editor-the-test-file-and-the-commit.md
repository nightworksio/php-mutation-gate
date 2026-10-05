# ADR-0015: A survivor reaches the editor, the test file and the commit, and `init` writes the CI that runs the gate

**Status:** Accepted
**Date:** 2026-09-30

## Context

A survivor is most useful where the developer already is:

- in the editor, beside the line it survives on;
- as a test to fill in;
- before the change leaves the machine.

The gate already has the pieces:

- `reproduce` and `explain` for one mutant (ADR-0004, ADR-0014);
- a hint per family (ADR-0009);
- SARIF 2.1.0 (ADR-0009);
- `watch` and the pre-push hook (ADR-0010);
- a verdict CI protects (ADR-0006).

What the editors read:

- **VS Code, SARIF.** Microsoft's *SARIF Viewer*
  (`MS-SarifVSCode.sarif-viewer`) shows a `.sarif` log's results as squiggles
  in the source, in the Problems list, or in its own results panel.
- **VS Code, problem matchers.** A task's `problemMatcher` turns output lines
  into Problems through a regular expression with `file`, `line`, `column`,
  `severity`, `code` and `message` groups. `fileLocation: relative` resolves
  against the workspace. A background task (`isBackground`, with
  `beginsPattern` and `endsPattern`) reports afresh each cycle, which suits
  `watch`.
- **PhpStorm.** Its quality-tool integrations are fixed to named tools, with
  no generic import. The Qodana plugin opens a local SARIF file (*Tools |
  Qodana | Open Local Report*) into the Problems view. JetBrains documents
  this for Qodana's own SARIF. A `path:line` in the Run window is a link.

ADR-0010 rejected a pre-commit hook: commits are frequent and often partial,
and a mutation run is too slow for them. A score change shown at commit time
needs no mutation run. It needs only what the local ledger already holds.

Setting the gate up in CI means copying the README's example for one CI and
filling in the pins, the PHP version, the image and the default branch. Every
one of those values the gate can know or check.

## Decision

1. **`mutation-gate stub <id>` writes a test for a survivor or an uncovered
   mutant.**
   - For a flaky, unjudged or too-slow mutant it exits 2 with the next step
     instead:
     - `triage` for a flaky one;
     - holding the path, or raising `timeouts.seconds`, for one too slow
       (ADR-0008);
     - a test that references the value, for ADR-0004's *no test reaches
       this value*.
   - A killed, ignored or proven-equivalent mutant is exit 2, *nothing to
     stub*.
   - The id is looked up as `explain` looks it up (ADR-0014).

2. **The stub follows the nearest covering test file's style.**
   - It copies that file's kind (Pest closures or a PHPUnit class), its
     namespace and base class, and its use of `covers()`, `#[CoversClass]` or
     `#[Holds]`.
   - A stub for a held unit carries the same `#[Holds]` or
     `->group('holds:…')`, so the group's coverage check still passes
     (ADR-0005 decision 10).
   - With no covering test, as for an uncovered mutant, the runner decides:
     Pest writes a Pest test and Infection a PHPUnit class, in the first
     `<testsuites>` directory.
   - `--style=pest|phpunit` overrides both.

3. **A stub is printed unless `--write` is given.**
   - With `--write`, it is appended to the nearest covering test file: a
     Pest test at the end of the file, a PHPUnit method before the class's
     closing brace, found by tokens.
   - With no covering file, `--write` creates
     `<test directory>/<source path>/<SourceClass>Test.php`.
   - `--write` never overwrites a file, and prints the file it touched.

4. **A stub fails until it is filled in.**
   - PHPUnit's calls `$this->fail(…)`, and Pest's
     `expect(true)->toBeFalse(…)`. The message names the mutant and its hint.
   - A comment above the failing line holds the diff and the hint, then the
     call to the enclosing function, with its parameters' names as
     placeholders.
   - One fixed assertion scaffold per family (ADR-0009 decision 7):
     - Boundary: two cases, at the boundary and one step either side of it.
     - Return value: `toBe(/* expected */)` on the returned value.
     - Removed call: an assertion on the call's effect.
     - Exception: `toThrow(<the thrown class>)`.
     - Other families: one assertion on the enclosing function's result.
   - A stub cannot be committed half-done: the suite goes red, and the gate
     says *cannot judge* until it is finished.

5. **A stub also offers the ignore.** Its last comment holds the exact
   `ignores.entries` item for the mutant, with `reason` left for a person to
   write. That is the other legitimate outcome, when no test can kill it.
   - **As built.**
     - `Core\Stub\Unstubbable` decides what a mutant asks for, and words
       the next step from its hint. A flaky mutant points at `triage`, an
       unjudged one whose value no test reaches asks for a test that
       references it, and any other unjudged one asks for a run first.
     - `Core\Stub\Nearest` picks the covering file. The file named for
       the class (`MoneyTest.php` for `Money.php`) wins. Otherwise the file
       with the most covering tests wins, then the first by path.
       `Core\Stub\TestFile` reads its kind from its tokens: a file that
       declares a named class is PHPUnit's. It adds a method before that
       class's closing brace, or a closure at the end.
     - Without a covering file, the stub goes under the first `<testsuites>`
       directory, at the source's path from its tree, named for its class
       with that directory's suffix (`tests/Unit/Domain/MoneyTest.php`). A
       file already there is added to, in its own kind. `RunnerBehaviour::testStyle()`
       says which style the runner's tests are written in: Pest for Pest,
       PHPUnit for every other runner.
     - A `--style` that the target file is not written in is exit 2.
     - The stub is printed under a comment naming its file: the test that
       `--write` adds, or the whole of a new file. A new PHPUnit file is a
       class named for the file, extending `TestCase`, in no namespace.
     - `Core\Php\Enclosing` writes the call with parameter names as
       placeholders. A function is called by name, a constructor with
       `new`, a static method on its class, and any other method on an
       object named for the class (`$cart->fits($amount, $limit)`).
     - Each scaffold is a comment, so the file parses, and the one failing
       line is code. The comments drop control characters, and write `?>`
       as `? >`, so no line ends a comment early.
     - A held unit's stub holds it: `->group('holds:…')` on a Pest test, and
       `#[Holds('…')]` on a PHPUnit method, with `#[Group('holds:…')]`
       beside it where Pest runs the class (ADR-0005, decision 9).
     - A weak test that let the mutant through gets its assertion of value,
       in that test's own style (ADR-0025, decision 6).
     - The ignore is written as the project's config writes one:
       `Ignore::mutant(…)` for a PHP config, or where there is none, and a
       JSON object otherwise. A cluster gets one item per member.

6. **Editors read SARIF, and a problems output.**
   - The `sarif` report is unchanged (ADR-0009).
   - `--output=problems`, on `run`, `watch` and `pre-push`, prints one line
     per result:

     ```text
     <path>:<line>:<col>: <error|warning>: <message> [<rule>] <id>
     ```

   - Each judgement is framed by a line `mutation-gate: judging` before it
     and `mutation-gate: judged` after it, for background matchers.
   - `<col>` is where the mutant starts, from the file's tokens (decision 9),
     and the message is SARIF's, with any line break read as a space so each
     result stays on one line. It is the reporter `problems`, whose option
     `only: "changed"` is what `--only=changed` sets.
   - The line format is public API (ADR-0011 decision 7).
   - **The first step of the build proves** that PhpStorm's Qodana plugin
     opens a SARIF 2.1.0 log the gate wrote, through *Open Local Report*, and
     lists its results. If it does not, the README's PhpStorm recipe is an
     External Tool running `--output=problems` with an output filter.
   - **As built.**
     - `run` takes `--output=problems` and `--only=changed`. `--only`
       takes `changed` alone, and only beside `--output=problems`; any other
       value is exit 2, before anything runs.
     - `Problems::PATTERN` is the line as a problem matcher reads it, with
       `Problems::GROUPS` naming its file, line, column, severity, message
       and code groups. The code is the rule, and the mutant id stays in the
       message. A test runs the pattern over every line the output writes.
     - Each message is one plain line (`Fit::plain`), and each path and the
       id of the run a proved or carried result names keeps its text but
       drops every control character (`Fit::verbatim`): no line break, escape
       or other control character, so neither a file's name nor a ledger's
       run starts a line a matcher reads as a result.
     - The Qodana plugin is not part of the build, so the README's PhpStorm
       recipe is the External Tool, with the output filter
       `$FILE_PATH$:$LINE$:$COLUMN$`.

7. **`init --editor=vscode` wires VS Code.**
   - It writes `.vscode/tasks.json` with a `mutation-gate: watch` background
     task and its problem matcher.
   - It writes `.vscode/extensions.json` recommending the SARIF Viewer.
   - It writes each file only where none exists, and otherwise prints the
     block to add, as `hook install` leaves an existing hook alone
     (ADR-0010 decision 3).
   - PhpStorm gets a README recipe (decision 6).
   - **As built.** `Core\Editor\VsCode` holds what it writes: `tasks()`, the
     whole `tasks.json`; `task()`, the block to add to one that exists; and
     `extensions()`. The task runs `vendor/bin/mutation-gate watch
     --output=problems` in the background. Its matcher's owner and source
     are `mutation-gate`, it reads files relative to the workspace folder,
     and its background patterns are the whole `judging` and `judged`
     lines.

8. **Editors show what CI shows.**
   - They get SARIF's four rules (ADR-0009 decision 2).
   - A mutant in a failing set is an *error*, and everything else a
     *warning*.
   - Proved and carried survivors are included, marked with the run they came
     from: the message ends with a space and `(proved in run <id>)` or
     `(carried from run <id>)`, with the run a proof names, and `(proved)` or
     `(carried)` where no proof names one.
   - `--only=changed` limits the problems output to mutants on changed lines.

9. **Locations are repository-relative everywhere.**
   - Locally (`CI` unset), SARIF also gives the absolute root as
     `originalUriBaseIds.SRCROOT`, a `file://` URI. The viewer resolves
     relative paths against it, and code scanning ignores it.
   - Columns come from the file's tokens, as the HTML report computes them
     (ADR-0009 decision 4). A mutant the gate cannot place spans its line.

10. **An optional pre-commit hook shows the score change, runs nothing and
    never blocks.**
    - `mutation-gate pre-commit` prints each reached tree's score change
      from what the local ledger holds. It adds a count of reached units with
      no local result, such as *3 units unjudged since your last run:
      `mutation-gate watch`*.
    - It always exits 0.
    - `hook install --pre-commit` adds it. `hook install` alone still
      installs the pre-push hook only.
    - `pre-push` prints the same table before its verdict (ADR-0010
      decision 2).
    - This supersedes ADR-0010's rejection of a pre-commit hook. That
      rejection was about a slow hook that blocks partial commits, and this
      hook is neither slow nor blocking.

11. **The change is against the merge base with the default branch.**
    - Each tree is completed by carried results from the local ledger and
      the default branch's ledger (ADR-0003 decision 4).
    - This is the "change against the base" the PR comment shows (ADR-0009
      decision 3), so the number seen at a commit is the number the pull
      request will show.
    - Each tree's floor is shown beside its score, so the headroom is
      visible too.

12. **The working tree is what is scored, and the output says so.** Proofs
    are keyed by content on disk (ADR-0007). When a reached unit has unstaged
    changes, the table says *includes unstaged changes in 2 files*. The hook
    never stashes, and never checks out the index: a hook touches none of
    the user's files.

13. **`init --ci=github|gitlab|buildkite|circleci` creates files, and never
    edits one.**

    | CI | Written | Printed, for the user to add |
    |----|---------|------------------------------|
    | GitHub Actions | `.github/workflows/mutation.yml` | nothing |
    | GitLab CI | the hidden-job template at `ci.gitlab.template` (`.gitlab/mutation-gate.yml`) | the `include:` and the two jobs for `.gitlab-ci.yml` |
    | Buildkite | `.buildkite/mutation-gate.yml`, a pipeline to upload | the step that uploads it |
    | CircleCI | nothing, because CircleCI reads one `config.yml` and has no include | the jobs and the workflow |

    - A target that already exists is exit 2, and `--stdout` prints it
      instead.
    - `init --ci` writes the gate's config only when none exists, in
      `--format`.

14. **The templates ship in the package, pinned to what the package tested.**
    - They live in `resources/ci/<provider>/`.
    - Every action and orb is pinned by the full SHA the package's own
      workflows use, with its tag in a comment.
    - The gate's own pin is its installed `source.reference`, from
      `vendor/composer/installed.json`, with its version in the comment.
    - A CI job in the package asserts that each template's pins equal the
      pins of the same actions in the package's own workflows, which
      Dependabot keeps current.
    - `init` needs no network.

15. **On GitHub, the estimated cost picks one job or the sharded workflow.**
    - When the cost model estimates a full run within `shards.seconds`, one
      shard's worth, `init` writes the one-step action. Otherwise it writes
      the reusable workflow.
    - `--sharded` and `--single` override the choice.
    - `init` prints the choice and the estimate. On a first run the estimate
      comes from one coverage run (ADR-0017), or from lines of code with
      `--no-measure` (ADR-0006), and the output says which.

16. **Everything the gate can know is filled in, and the rest is loud.**
    - **Detected:**
      - the default branch, by `ci.defaultBranch`'s rule;
      - the PHP version: `config.platform.php`, else the lowest version
        `require.php` allows, at least 8.5;
      - the runner;
      - the ledger cache for that CI, keyed as ADR-0007 decision 4 says;
      - the twice-weekly schedule (ADR-0013).
    - **Left for the user**, each as a comment:
      - an image with the PHP version and pcov, for GitLab, Buildkite and
        CircleCI. No official image is known to include pcov, and a wrong
        image makes the first run *cannot judge*;
      - the S3 secrets.

      A comment keeps the file valid.
    - The branch-protection step is printed with the exact check to require:
      `mutation testing` for the one-step action, `mutation / verdict` for
      the reusable workflow.
    - **As built.** The one-step action's job is named for `ci.check`,
      `mutation / verdict` by default, so its check-run is the one a merged
      pull request's verdict is trusted by; `init` prints that check for both
      definitions. Every value the project gives a template is checked to
      hold no character a shell or YAML reads as code, and lands quoted.

17. **The templates are checked in the package's CI.**
    - The GitHub template runs through `actionlint`, and is the shape the
      package dogfoods (ADR-0011 decision 8).
    - The GitLab, Buildkite and CircleCI templates are validated offline
      against each provider's published JSON Schema.
    - A snapshot test fixes each rendered file.
    - Syntax is guaranteed for all four, and behaviour for GitHub's. The
      README says so.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Stubs for every mutant not killed** | A test skeleton for a timeout the clock caused points the user at the wrong fix. |
| **The stub's style by runner only** | A Pest project with PHPUnit classes would get a closure file beside class files. |
| **Always a new file per stub** (`tests/Mutation/<id>Test.php`) | A trail of files named after mutants that nobody organises. |
| **Pest's `->todo()` or `markTestIncomplete()`** | Can be committed and forgotten: the suite stays green, and the survivor stays. |
| **A bare skeleton, the name and the diff only** | Safe and unhelpful. The family scaffold is the value. |
| **Stubs about tests only, with no ignore** | Leaves out the other legitimate outcome. The reason is still a person's to write. |
| **SARIF only, documented per editor** | No live update in `watch`, because the viewer shows a file, not a stream. |
| **First-party editor plugins** | Two more products to build, sign and publish, outside ADR-0011's one repository. They can come later and read the same two outputs. |
| **README recipes only, with no `init --editor`** | Every user copies a regular expression by hand. |
| **Only survivors on changed lines, by default** | Hides a failing tree's survivors, which is why CI is red. The quiet view stays available with `--only=changed`. |
| **Absolute paths in local reports** | The same SARIF uploaded from a local run would then be wrong. |
| **Pre-push only, with the delta in its output** | No conflict with ADR-0010, and the feature's "before commit" would not be met. |
| **A blocking pre-commit hook that mutates under a small budget** | What ADR-0010 rejected, for the reasons it gave. |
| **The change against the committed baseline** | The baseline holds floors, not scores (ADR-0003), so it shows headroom, not change. |
| **The change against the previous local run** | Moves with every save. That is the delta `watch` already shows. |
| **Scoring the index through a temporary checkout** | Copies the tree on every commit, and keys proofs to a path that is not the project's. |
| **Stashing unstaged changes around the check** | Rewrites the user's working tree inside a hook, and an interrupted hook loses work. |
| **Merging into the existing CI file** | A YAML round trip loses comments and anchors, and a bad merge breaks CI. |
| **Resolving the latest tags through GitHub's API at `init` time** | Needs a network and a token, and writes pins nobody tested together. |
| **Tags instead of SHAs in the templates** | Contradicts the package's own advice in the first file a user copies. |
| **Always the reusable workflow, or always the one-step action** | Three jobs of overhead for a small library, or an hour-long first check for a large project. |
| **Detecting and choosing images too** (`cimg/php:<v>` and the like) | Could write an image with no coverage driver, whose first run is *cannot judge*. |
| **Snapshot tests alone** | Catch changes, not errors. |

## Consequences

**A survivor can be fixed without leaving the editor:** it is underlined,
`explain` says why, and `stub` writes the failing test to fill in.

**The score a pull request will show is visible at commit time,** at no cost
and with no block.

**Adopting the gate in CI is one command,** and what it writes is pinned,
linted and the shape the package runs itself.

## Related

- [ADR-0002](0002-one-typed-config-from-several-formats.md): `init` and its options
- [ADR-0003](0003-a-floor-only-rises.md): carried results complete each tree
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): holding groups a stub must join
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): SARIF, hints, and the problems output beside the reporters
- [ADR-0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): the pre-commit hook this ADR adds, and `hook install`
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the problems output as public API, and the pinned templates
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): the schedule the templates write
- [ADR-0014](0014-every-test-is-judged-by-what-it-kills.md): `explain`, which `stub` shares its lookup with
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): the guided `init` these options belong to, and its estimate
