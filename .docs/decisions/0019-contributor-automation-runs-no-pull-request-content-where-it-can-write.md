# ADR-0019: Contributor automation knows rather than guesses, and runs no pull request content where it can write

**Status:** Accepted
**Date:** 2026-09-30

## Context

Contributors need to know three things:

- which gate failed, and why;
- the exact command that reproduces or fixes it;
- where they stand on the way to a merge.

One maintainer cannot answer every one of those by hand. Automation that
answers them has to talk to pull requests from forks and write to
branches. That is where CI systems are most often attacked, and this
package's own ledger lives in the Actions cache (ADR-0007).

The maintainer wants as much automation as static analysis allows, and no
AI anywhere in the workflows. Every word the bot posts must come from a
table in `main`, or from a tool's structured output validated against one.

What the design rests on, read at source, in GitHub's documentation, and
by probes on 2026-09-30:

- **The repository's settings** give workflows a write token by default,
  let Actions approve pull requests, and require workflow approval from
  first-time contributors only.
- **`main`'s protection:**
  - strict required checks;
  - signed commits;
  - squash only, with the PR title as subject and the PR body as message;
  - linear history.

  Every merge therefore leaves open pull requests behind `main`.
- **`ci.yml` runs on `pull_request`** (opened, synchronize, reopened) only,
  so editing a PR's title re-runs nothing.
- **`GITHUB_TOKEN` starts no workflow.** An event it causes starts no
  workflow run, except `workflow_dispatch` and `repository_dispatch`. A pull
  request it opens or updates gets runs in an approval-required state. A
  GitHub App's token starts workflows as a person's does.
- **Commits made through the API are signed.** A commit made with
  `createCommitOnBranch` or the git-data REST endpoints, as an App or with
  `GITHUB_TOKEN`, and with no custom author or committer, is signed by
  GitHub. A server-side rebase of a pull request's branch makes unsigned
  commits.
- **Which triggers carry what.** `pull_request` from a fork gets a
  read-only token and no secrets. `pull_request_target`, `issue_comment`
  and `workflow_run` run the default branch's workflow with a token that
  can write, and with secrets.
- **The cache probe.** `issue_comment` and `pull_request_target` run with a
  read-only Actions cache, and a save fails whatever `permissions` says.
  **`workflow_run` runs with a writable cache even under `permissions: {}`,
  and saves as `refs/heads/main`**, the scope `main`'s ledger is restored
  from.
- **`workflow_run` and forks.** It is the only event raised when another
  workflow finishes. For a fork's pull request, its `pull_requests` field
  is empty.
- **Pushing to a fork.** "Allow edits by maintainers" lets *users* with
  push access push to a fork's branch. `GITHUB_TOKEN` cannot push there.
- **The tools report in structured form:**
  - PHPStan (`--error-format=json`);
  - Pint (`--format=json`);
  - Rector (`--output-format=json`);
  - Pest (`--log-junit`);
  - typos, lychee, actionlint, gitleaks (redacted) and osv-scanner (JSON);
  - the gate itself (its JSON report, ADR-0009).
- **OIDC roles and `ref`.** Every job a workflow runs on the default
  branch, whatever its trigger, carries `ref: refs/heads/main` in its OIDC
  token. An OIDC role that trusts a bare `ref` therefore trusts all of
  them.

## Decision

### Foundations

1. **The bot lives in this repository, is written in Python's standard
   library, and uses no model.**
   - Its workflows are `.github/workflows/bot-*.yml`, and its scripts live in
     `.github/scripts/`. Each script has an I/O half and a pure deciding
     half, as `attribution_check.py` and `commit_lint.py` do.
   - The deciding halves are unit-tested with `python3 -m unittest` in a
     required CI job, `scripts`.
   - A privileged job installs nothing: no Composer, no pip, no npm.
   - No language model is called anywhere. Every sentence the bot posts is
     text from `main`, filled with values validated against `main`.

2. **Two identities: `GITHUB_TOKEN`, and an App of our own.**
   - `GITHUB_TOKEN`, with per-job permissions, comments and re-runs jobs.
   - The App, `mutation-gate-bot`, is installed on this repository only, with
     Contents and Pull requests set to read and write. It makes the writes
     that must start CI: the `/fix` commits and the release pull request.
   - The App's private key lives in a GitHub environment, `bot`, whose
     deployment-branch policy allows `main` only.
   - Tokens are minted per job with `actions/create-github-app-token`, pinned
     by SHA and narrowed with its `permission-*` inputs.
   - Every commit the App makes goes through `createCommitOnBranch` with no
     custom author, so GitHub signs it and CI starts on it.

3. **The rules every bot workflow keeps.**
   - **`workflow_run` handles no pull request data.** It writes the default
     branch's cache (the probe), so its one job is the relay of decision 4.
   - **`issue_comment` and `pull_request_target` run no tool over pull
     request content.** They carry a write token and secrets, so they read
     a pull request only as data: through the API, or from git objects at a
     pinned SHA, which also defeats planted symlinks. They never check it
     out as a working tree, install it or load it.
   - **No pull request or comment string appears inside `${{ }}` in a
     `run:`.** It passes through `env:` and is handled by the script.
   - **Every write names the head SHA it acted on** (`expectedHeadOid`,
     `expected_head_sha`). A push in between fails the write.
   - **`permissions: {}` at the workflow level,** and each job asks for
     exactly what it writes.
   - **Commands count only from `created` comments.** Comments by bots are
     ignored, except the App's own relay marker (decision 4). `concurrency`
     is set per pull request.
   - **A test reads every workflow** and fails when a job started by
     `pull_request_target`, `issue_comment` or `workflow_run` has a step
     other than a checkout of `main`, the stdlib scripts, and the pinned
     token action.
   - **CodeQL's `actions` analysis stays on,** and zizmor joins `hygiene`.
   - **Three repository settings change:**
     - the default workflow token becomes read;
     - Actions may not approve pull requests;
     - workflow approval is required for all outside contributors.

4. **A relay of numbers carries "CI finished" out of `workflow_run`.**
   - **What it reads.** On `workflow_run` (completed) for `ci`, one job
     reads the run's id (an integer), its `head_sha` (validated as 40
     hex) and its `conclusion`. It finds the pull request whose head SHA
     equals the run's. For a fork, it looks it up by head repository and
     requires the SHA to match.
   - **What it ignores.** It reads no string the pull request controls,
     downloads no artifact, checks nothing out and installs nothing.
   - **How it hands off.** As the App, it sets the pull request's sticky
     comment to an *explaining…* state that carries
     `<!-- mutation-gate-bot: run=<id> sha=<sha> -->`. A comment written by
     the App starts workflows.
   - **Where the work happens.** Evidence parsing and posting (decisions 6
     to 12) run on `issue_comment` (`edited`), whose cache is read-only.
     They act only on the App's own comment carrying the marker, and read
     the run again by its id.
   - **One more job.** The re-run of infrastructure failures (decision 9)
     runs in the relay too, because it reads only job metadata.

### What the bot knows

5. **Every CI job leaves structured evidence.**
   - Each job in `ci.yml` asks its tool for machine-readable output.
   - A stdlib converter turns that output into `evidence/<job>.json`, with
     `format`, `job`, `tool` and `findings: [{path, line, rule, detail}]`.
   - Where the fix is mechanical, the job adds `fix.patch` (decision 8).
   - All of it is uploaded as the artifact `evidence`.
   - A test fails when a job in `ci.yml` has no evidence producer.

6. **Evidence is as untrusted as the pull request that produced it.**
   - A `pull_request` run takes its workflow and scripts from the pull
     request, so the evidence can say anything.
   - The pipeline downloads it through the API and unpacks it in memory,
     with only the expected file names, a size cap per file and in total,
     and no paths taken from the archive.
   - Each file is parsed against its schema, and a file that fails is
     dropped whole.
   - Only these reach a comment:
     - `path`: it must be a file the pull request changed, checked against
       the API's list at the run's head SHA;
     - `line`: an integer;
     - `rule`: it must be in `main`'s vocabulary (decision 7).
   - Free text from evidence, such as a PHPStan message or an assertion
     message, is never rendered by the bot. The pull request's own job shows
     it, as an annotation it writes itself with no privilege.
   - A rule outside the vocabulary is shown as *unrecognised (see the job's
     annotations)*, and counted.

7. **The words come from tables in `main`.**

   | Table | Holds | Source of each explanation |
   |-------|-------|----------------------------|
   | `.github/gates.json` | Per `ci.yml` job: what it checks, the command that reproduces it, the command that fixes it where one exists, and its troubleshooting slug (ADR-0018) | Written in this repository |
   | `ARCHITECTURE.md` | The package's own rules (`C5`, `H3`, …) | Its rule rows |
   | `.github/bot/rules/phpstan.json` | PHPStan identifiers | One line per identifier, and phpstan.org's page for it |
   | `.github/bot/rules/rector.json` | Rector rule classes | One line per rule, and its page in Rector's rule reference |
   | `.github/bot/rules/pint.json` | php-cs-fixer fixers | One line per fixer, and its page in the fixer reference |
   | `.github/bot/rules/markdownlint.json` | markdownlint rules (`MD013`, …) | One line per rule, and its page in markdownlint's docs |
   | `.docs/guide/troubleshooting.md` | The gate's own statuses and message slugs | Its sections (ADR-0018) |

   - A test fails when a job has no `gates.json` entry, or an entry has no
     job.
   - A test fails when a rule met in the recorded evidence fixtures has no
     entry.
   - Each job in `ci.yml` also ends with an `if: failure()` step that writes
     its `gates.json` entry to the step summary and as an `::error::`
     annotation. That needs no privilege, and works for forks.

### What the bot does

8. **It explains each failure exactly, and fixes what is mechanical.**
   - **What each gate shows.** The sticky comment lists, per failed gate,
     its cause from decision 7's tables, at the validated `path:line`, with
     the rule's explanation and link.

     | Gate | Shown | Mechanical fix |
     |------|-------|----------------|
     | `checks`: Pint | each file and fixer | `composer lint:fix` |
     | `checks`: Rector | each file and rule | `composer refactor:fix` |
     | `checks`: PHPStan | `path:line`, identifier, its line and link, or the `ARCHITECTURE.md` row | none |
     | `checks`: normalize, validate | the file | `composer normalize` |
     | `tests and coverage` | the failing test from JUnit at `path:line`, and uncovered lines of changed files | none |
     | `rules`, `the rules refuse violations` | the rule and its `ARCHITECTURE.md` row | none |
     | `hygiene / typos` | `path:line` and the typo | proposed only; a corrected word inside an identifier can be wrong |
     | `hygiene / markdown` | `path:line` and the rule | `markdownlint-cli2 --fix` |
     | `commitlint`, `attribution` | the commit or the title, and the rule | none; history is the author's |
     | the mutation verdict | a link to the gate's own comment (survivors, hints, `reproduce`, `stub`) | none |

   - **How the fix is made.** When `checks` or `markdown` fails, that job
     itself runs the fix commands and adds `fix.patch` to its evidence.
   - **Always proposed.** The comment shows the patch as suggested changes
     for hunks inside the diff, and as a collapsed patch for the rest.
   - **Committed only on `/fix`** (decision 13), and only for a branch in
     this repository. Every hunk must touch a file the pull request already
     changes (or `composer.json`, for normalize), under a size cap. The App
     makes one commit, `style: apply the fixes CI computed`, whose body
     names the run and its SHA.
   - **A fork only gets the suggestions.** The author's own "commit
     suggestion" makes the commit.
   - **The bot runs no tool.** The bytes are the pull request's own CI's,
     committed only to that pull request's branch, where its author could
     push them anyway.

9. **Infrastructure failures are re-run once.**
   - This applies when the failed step is checkout, `setup-php`,
     `composer-install` or an artifact download, or when the runner was
     lost. The relay reads that from the run's job and step metadata, never
     from logs.
   - It runs once per job per head SHA, with `actions: write` on
     `GITHUB_TOKEN`.
   - The comment says what was re-run, and why.

10. **A first pull request is walked through by its state.**
    - **The checklist.** The sticky comment holds one, updated on every
      event:
      - waiting for a maintainer to start CI (the run's `action_required`
        status);
      - title conventional (decision 12);
      - every gate green, each red one expanded as decision 8 says;
      - new code tested (the gate's comment, decision 11);
      - a `Spec:` line, when the pull request touches a path the spec map
        covers;
      - ready for review.
    - **When `.github/` changes,** it adds: *this pull request changes CI
      itself, so a maintainer reviews that before any check here means
      anything.*
    - **The welcome.** A `pull_request_target` (opened) job, with no
      checkout and `pull-requests: write`, opens the comment for
      `FIRST_TIME_CONTRIBUTOR`, `FIRST_TIMER` and `NONE`. The welcome text
      comes from `main`: setup, the local gates, CI waiting for approval,
      the title rule, and that assistant trailers are refused.
    - Ticked items collapse.

11. **Missing tests are the gate's to report, and it reports them early.**
    - The gate's own sticky comment (ADR-0009 decision 3) gains a
      **planned** state, posted by the `plan` job. It holds:
      - the units to be mutated;
      - the estimate (ADR-0017);
      - the changed lines no test covers, read from the coverage map the
        plan already holds.
    - It is posted minutes before the verdict replaces it.
    - This is a feature of the gate for every user, and the bot links it.

12. **Pull request text is checked on edit, and helped, never rewritten.**
    - **The `pr` workflow.** It runs on `pull_request` (opened, edited,
      synchronize, reopened), reads only metadata and runs no PHP.
      - `commitlint` and `attribution` move there from `ci.yml`, under the
        same job names, so a fixed title or body is checked at once.
      - Every `Spec:` number in the body must be an ADR in `.docs/decisions`,
        because the body becomes `main`'s commit message.
      - A title with `!` needs a *Migration* section.
    - **The comment shows:**
      - the changelog preview: the exact line and section ADR-0018's
        git-cliff run will write, computed in the `pr` job and carried as
        evidence;
      - when the title fails, a suggestion. The type comes from the changed
        paths (`.docs/` only → `docs`, `tests/` only → `test`, `.github/`
        only → `ci`), and the scope from the top directory under `src/`
        (`src/Adapter/Pest/` → `pest`);
      - with no `Spec:` line, a suggestion from `.github/spec-map.json`,
        which maps globs to ADR numbers. A test fails when a glob matches
        nothing or names a missing ADR.
    - The bot never edits a title or a body.
    - There is no changelog-entry check: the title is the changelog line.

13. **The commands, and who may use them.**

    | Command | Does | Who |
    |---------|------|-----|
    | `/fix` | Commits decision 8's patch | write access, or the pull request's author |
    | `/update` | GitHub merges the base into the head (update-branch, merge method, with the expected head SHA). GitHub makes and signs the merge commit, `commitlint` exempts subjects that start with "Merge", and the squash erases it. `/rebase` does the same and says why | write access, or the pull request's author |
    | `/retest` | Re-runs the failed jobs of the latest CI run on the current head, never one awaiting approval, at most three times per head SHA | write access, or the pull request's author |
    | `/docs <words>` | Searches a heading index generated from `.docs/`, and replies with at most three links and no prose. The words are reduced to `[a-z0-9]+` tokens | anyone |

    - The pull request's author is matched by user id, read from the API's
      pull request, never from the comment's text.
    - Anyone else is checked against the collaborator-permission endpoint,
      which must answer `write`, `maintain` or `admin`.
      `author_association` is not read: `MEMBER` names any member of the
      organisation, and `COLLABORATOR` includes read and triage roles.
    - Anyone refused gets one reply naming who may run the command.

14. **Duplicate issues are matched by message slug.**
    - An `issues` (opened, edited) job, with `issues: write`, finds slugs
      (ADR-0018) in the body by pattern, and keeps only slugs that exist in
      `main`'s troubleshooting page.
    - It replies once, with the other issues carrying the same slug, open and
      closed, and the section's link.

15. **A release is drafted by the bot and signed by the maintainer.**
    - **Drafting.** On `workflow_dispatch` by the maintainer, a job computes
      the next version from the commits since the last tag: `feat` is a
      minor, `fix` and `perf` a patch, `!` a major, and 1.0.0 is given by
      hand (ADR-0018). It writes that version's `CHANGELOG.md` section with
      git-cliff, pinned by version and checksum, and opens or updates
      `release/vX.Y.Z` as the App.
    - **Signing.** The maintainer edits and merges that pull request, runs
      `git tag -s vX.Y.Z` on the squash commit, and pushes the tag.
    - **Publishing.** `release.yml`, on the tag push:
      - checks the tag's signature against `.github/allowed_signers`, and
        that the tag points at a commit on `main`;
      - runs the gate in `mode: full` (ADR-0011);
      - publishes the GitHub release with that section as its notes;
      - moves `v1`.
    - **A tag ruleset** lets only the maintainer create `v*` tags.

### The ledger's OIDC roles

16. **An OIDC role that can write a ledger never trusts a bare `ref`.**
    - Where the ledger's store is reached through an OIDC role (S3, as the
      approved S1 setup does, and the `gcs` and `azure` stores, whose only
      credential is a federated one: ADR-0028 decision 3), the role that can write the default branch's
      prefix trusts either:
      - a GitHub environment whose deployment-branch policy allows the
        default branch only, and which only the verdict job uses; or
      - the `job_workflow_ref` of the workflow that runs the verdict, at
        the default branch.
    - A bare `ref: refs/heads/main` condition is refused, because every job
      a workflow runs on the default branch carries it. That includes this
      bot's jobs, and anything started by `issue_comment`, `workflow_run` or
      a schedule.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **A language model that explains failures and answers questions** | The maintainer wants no AI in the workflows. It would also cost per call, give different words each run (ADR-0009 rejected model-written hints for that reason), need a second credential, and read text that pull request code writes, which is an injection surface. |
| **Parsing raw job logs** | Console text changes with every tool release, and pull request code writes it. Structured output does not change with the release. |
| **Rendering the tools' own messages, escaped** | Escaping Markdown completely is a known trap. The job's own annotations already show those messages on the diff. |
| **Parsing evidence and posting from `workflow_run`** | `workflow_run` writes the cache `main`'s ledger is restored from. |
| **Handing off through `repository_dispatch`** | Its cache mode is unprobed and may be writable, as `workflow_dispatch`'s is. |
| **A `pull_request_target` job that polls until CI finishes** | It holds a runner idle for every CI run on every push. |
| **Running Pint from `main` inside a privileged workflow** | It rests on a per-tool claim ("never loads the code it reads") that an upgrade can break, and it could not cover Rector. |
| **Committing fixes automatically** | It pushes to someone's branch unasked, and their next local push fails as non-fast-forward. |
| **Pushing fixes to forks** | `GITHUB_TOKEN` cannot, and an App token scoped to this repository is not expected to. Suggestions let the author commit. |
| **`GITHUB_TOKEN` only** | Every fix commit and release pull request would wait for a maintainer to approve its CI. |
| **The App for everything** | Its key would sit in every bot job, not two. |
| **A personal access token** | The bot would act as the maintainer, with a long-lived credential and the maintainer's reach. |
| **A hosted bot (Mergify, a Probot app run by someone else)** | A third party with write access, and CI behaviour that changes with no commit here, against ADR-0011's "the package stands alone". |
| **`actions/github-script`** | Logic in YAML strings that nothing tests. |
| **The bot in PHP with Composer** | Every privileged job would run `composer install`, a large supply chain beside a write token. |
| **A server-side rebase for `/rebase`** | GitHub cannot sign rebased commits. |
| **The bot rebasing and re-creating each commit** | Every commit's author would become the bot. |
| **Write access only for commands** | Contributors would wait for the one maintainer for every update and reformat. |
| **Anyone may run any command** | Drive-by comments would spend CI minutes and cause writes. |
| **Duplicates by word similarity or embeddings** | Wrong matches on a small tracker cost more goodwill than they save, and an exact message slug is the same failure. |
| **The bot rewriting titles and bodies** | Its edits would conflict with the author's next edit, and the body is where the author states intent. |
| **A required changelog entry or fragment** | A second place for what the conventional title already says (ADR-0018). |
| **Labelling by area, by type or by path** | One maintainer. The conventional scope names the area, and the notes come from commits. |
| **Closing stale issues and pull requests** | Real reports would close unanswered. The backlog is read by a person. |
| **Predicting which gates a pull request will trip** | The fast gates report within minutes, sooner than a prediction could be read. The one slow answer worth having early is the gate's planned state. |
| **release-drafter** | It needs labels, its autolabeler runs under `pull_request_target`, and it ignores conventional commits. |
| **release-please** | The bot would create the tag, unsigned, which ADR-0011 decision 9 refuses. |
| **OIDC roles trusting `ref: refs/heads/main`** | Every default-branch job would be trusted, including ones that read pull request data. |

## Consequences

**A contributor learns the cause, the place and the fix of every red gate**
without asking. The words are `main`'s, the numbers are validated, and
nothing is guessed.

**No pull request content runs anywhere that can write.** The one job in
the cache scope `main` restores from handles only numbers.

**Mechanical failures cost a comment, `/fix`,** on a branch in this
repository, or a click on a suggestion from a fork.

**Releases stay the maintainer's act:** a signed tag, verified before
anything is published.

**Three repository settings and one environment change,** and an App and a
tag ruleset are created, once, by the maintainer.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the ledger's scopes, and the OIDC roles that write them
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the sticky comment's planned state, and why the reports stay free of generated prose
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the CI table, signed commits, releases and the reusable workflow's permissions
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): the estimate in the planned state
- [ADR-0018](0018-the-documentation-is-versioned-and-tested-with-the-code.md): the message slugs, the changelog and the release notes
