# ADR-0161: The welcome is swept on a schedule, and no bot workflow runs on `pull_request_target`

**Status:** Accepted
**Date:** 2026-11-16
**Amends:** [0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): the triggers the bot's rules and their test hold, the `pr` workflow's events, and the welcome
**Amends:** [0110](0110-the-bot-s-app-writes-only-a-pull-request-s-own-branch-and-moves-the-release-line.md): the jobs that enter `bot`, and the checklist and the welcome

## Context

- **GitHub refuses `pull_request_target` in public repositories from
  2026-12-02.** During its brownouts, every run of a workflow on it ends in
  `startup_failure`.
- **`bot-checklist.yml` was the bot's one workflow on it.** On a pull
  request's `opened`, `ready_for_review` and `converted_to_draft`, it wrote
  the sticky comment where one existed. For an author `checklist.welcomed`
  names, it opened the comment with the welcome.
- **The welcome is needed while CI waits.** A first pull request's CI waits
  for a maintainer to approve it, so no `workflow_run` fires for the relay to
  hand on (ADR-0110).
- **Every other write of the sticky comment already goes through the relay.**
  Each run of `ci` and `pr` is relayed by number, and the explainer writes
  the comment on `issue_comment`, whose cache is read-only.
- **A scheduled run is the default branch's.** It runs `main`'s workflow with
  secrets, and its cache saves as `refs/heads/main`, the scope `main`'s ledger
  is restored from, as `workflow_run`'s does.

## Decision

1. **A change between draft and ready reaches the comment through `pr`.**
   - The `pr` workflow runs on `ready_for_review` and `converted_to_draft`
     too, beside `opened`, `edited`, `synchronize` and `reopened`.
   - Its runs are relayed like every other `pr` run, and the explainer
     brings the checklist's "ready for review" up to date.
   - An outside contributor's run waits for approval (ADR-0019), so their
     change shows when a maintainer approves the run.

2. **The welcome is swept on a schedule.** `bot-checklist.yml` runs on
   `schedule` and `workflow_dispatch`.
   - **When.** `.github/bot/config.json`'s `checklist.sweep.cron`, every ten
     minutes by default. A test holds the workflow's `cron` to it.
   - **`find`**, with the job's token (`contents` and `pull-requests` read),
     and outside any environment. It lists the open pull requests, newest
     first and at most `checklist.sweep.most_pulls` (100). It keeps each one
     whose author is a person, whose `author_association` is in
     `checklist.welcomed`, and which has no sticky comment, or one whose
     welcome mark names an older head. It writes their numbers as its output.
   - **`welcome`** runs only where `find` named a pull request. It enters
     `bot`, and mints the App's token narrowed to pull requests. It holds the
     numbers to digits and to `most_pulls` again, reads each pull request
     and its comments again, and decides again. As the App, it opens the
     sticky comment with
     `<!-- mutation-gate-bot: welcome sha=<head> -->` and a line saying the
     checklist is being written, or writes a moved head's mark over the old
     one.
   - **Numbers alone.** Both jobs keep ADR-0019's rule for `workflow_run`.
     They read each pull request's number, its head (40 hex), its
     `author_association` (matched against the config's list), its author's
     account type, and whether a bot's comment starts with the sticky mark
     and carries the welcome's. They read no string a pull request controls
     into anything they write, check out only `main`'s scripts and tables,
     and install nothing.
   - **The relay's write drops the welcome mark** and its line, beside its
     own older mark.

3. **The explainer acts on the welcome mark.**
   - `bot-explain.yml` starts where a bot's comment starts with the sticky
     mark and carries the relay's mark or the welcome's.
   - On the welcome mark, it writes only where:
     - the mark names the pull request's current head;
     - `checklist.welcomed` still names the author, and a bot's pull request
       is never welcomed;
     - the sticky comment exists.
   - It writes the welcome and the checklist with the job's own token,
     through the writer the relay's mark uses, and that write drops the
     mark. Where the relay's mark is there too, it is the one read.

4. **V5 holds every bot workflow, whatever starts it.**
   - The test reads every `.github/workflows/bot-*.yml` as a workflow that
     runs with a token that can write. It reads, as before, any workflow
     that `issue_comment`, `issues`, `pull_request_target` or `workflow_run`
     starts.
   - So ADR-0019's rules for a privileged job (`permissions: {}` at the
     workflow, the permissions each job names, and only a checkout of
     `main`, the stdlib scripts and the pinned token action) hold the
     sweep's jobs on `schedule` and `workflow_dispatch`.
   - `pull_request_target` stays in the test's events, so a workflow that
     took it up again would be held, and zizmor's `dangerous-triggers`
     audit names it.

5. **Five jobs enter `bot`.** ADR-0110's table gains one row:

   | Job | Token |
   |-----|-------|
   | the welcome sweep's `welcome` | pull requests |

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Keeping `pull_request_target`** | GitHub refuses it in public repositories from 2026-12-02, and its brownouts already fail every run. |
| **The checklist on `pull_request`** | A fork's run gets a read-only token and no secrets, and a first pull request's run waits for approval, as its CI does. |
| **The welcome from the relay of `pr`** | No `workflow_run` fires while the run waits for approval (ADR-0110). |
| **The sweep writing the welcome itself** | The welcome and checklist read the pull request's title, body and runs. The sweep holds the App's key and writes `main`'s cache scope; the explainer holds neither. |
| **One sweep job that always enters `bot`** | A deployment and a minted App token on every run, nearly all of them for nothing. |
| **`schedule` added to V5's events** | It would hold `ci`, `codeql`, `scorecard`, `bench` and `canary`, whose scheduled jobs run third-party actions and tools over `main`'s own code, to rules meant for the bot. Naming the bot's files holds them whatever trigger they gain. |
| **Opening the welcome again at every head** | A comment the explainer has written follows the pull request through the relay. Only a welcome the explainer never wrote, because the head moved first, is marked again. |

## Consequences

**A first pull request is welcomed within about one sweep of opening**, and
GitHub can delay a scheduled run under load. The interval is a setting.

**A change between draft and ready follows CI**: at once where the run needs
no approval, and when a maintainer approves it where it does.

**No workflow of this repository runs on `pull_request_target`.**

## Related

- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): the bot, its rules and V5
- [ADR-0110](0110-the-bot-s-app-writes-only-a-pull-request-s-own-branch-and-moves-the-release-line.md): the App, the relay, the explainer and the checklist
