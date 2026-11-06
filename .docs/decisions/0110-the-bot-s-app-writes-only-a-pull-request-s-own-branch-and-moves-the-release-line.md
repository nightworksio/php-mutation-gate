# ADR-0110: The bot's App writes only a pull request's own branch, and moves the release line

**Status:** Accepted
**Date:** 2026-11-06
**Amends:** [0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): the App's reach and environment, the relay, `/fix`, the re-run, the checklist, the changelog preview and the tag ruleset
**Amends:** [0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): who may create and move `v*` tags

## Context

ADR-0019 decides a contributor bot of stdlib scripts and two identities: the
job's `GITHUB_TOKEN`, and an App of the repository's own,
`mutation-gate-bot`. Building it settles what that ADR leaves open:

- **Where the App may write.** `/fix` commits to a pull request's head
  branch. A branch name is the author's to choose, so a pull request whose
  head is `main`, its own base or a protected branch would point the App at
  a branch no fix belongs on.
- **Which run's evidence the App trusts.** Runs are listed by head SHA, and
  two pull requests, or a push, can share one.
- **The release line's tag.** Consumers pin `@v0.1`. Left outside a ruleset,
  any token with `contents: write` can move it.
- **Who writes the sticky comment.** A first pull request's CI waits for
  approval, so no `workflow_run` fires. Only a `pull_request_target` job can
  say it waits.
- **The re-run.** Failed jobs re-run one at a time cannot overlap, and
  re-running only some of a run's failed jobs needs one request per job.
- **The changelog preview.** Evidence is as untrusted as the pull request,
  and the preview is the title's own words.

## Decision

1. **The App.**
   - It is `mutation-gate-bot`, owned by the organisation, with no webhook
     and no events, and installed on this repository only.
   - It holds Contents and Pull requests (read and write) and Metadata (read).
     It has no Workflows permission, so GitHub refuses any write the App
     makes under `.github/workflows/`.
   - Its key, `MUTATION_GATE_BOT_PRIVATE_KEY`, and its client ID,
     `MUTATION_GATE_BOT_CLIENT_ID`, live in the environment `bot`.
   - `bot`'s deployment policy allows the branch `main` and the tags
     `v*.*.*`, which only the maintainer can create (item 6).
   - Four jobs enter `bot`, and each mints a token narrowed to what it
     writes:

     | Job | Token |
     |-----|-------|
     | the release `draft` | contents and pull requests |
     | the relay | pull requests |
     | `/fix` | contents |
     | the release `line` | contents |

2. **The relay and the explainer.**
   - **What is relayed.** The relay runs on `workflow_run` (completed) for
     `ci` and `pr`, the workflows `.github/bot/config.json` lists. It skips
     Dependabot's runs, which get no secrets there.
   - **The relay's edit.** As the App, it sets the sticky comment to its
     explaining state, or opens the comment where there is none. The edit
     carries `<!-- mutation-gate-bot: run=<id> sha=<sha> -->`.
   - **When the explainer acts.** It runs on `issue_comment` (edited), only
     where a bot edited a bot's comment that starts with
     `<!-- mutation-gate-bot -->` and carries that mark. It writes with the
     job's own token, so its edit starts nothing.
   - **What the explainer reads.** It reads only the runs on the head that
     GitHub lists for this pull request, or, for a fork's, for none.
   - **The suggestions.** The explainer posts the fix's suggested changes
     once per head, marking the head with
     `<!-- mutation-gate-bot: suggested=<sha> -->`. A review GitHub refuses
     leaves the comment written and the head unmarked.

3. **`/fix` writes only a pull request's own branch, from its own run.**
   - The App commits only where all of these hold:
     - the head branch is in this repository;
     - it is neither the pull request's base nor the default branch;
     - it is not protected;
     - no ruleset rule that `config.json`'s `fix.protecting_rules` lists
       applies to it.
   - The fix comes only from the pull request's latest `ci` run, where that
     run is on the head branch, is at the head the bot read, and lists this
     pull request.
   - Every file the fix changes is one the pull request changes, or one
     `fix.beyond_the_diff` lets the gate's fix reach (`composer.json` for
     `checks`). None is under `fix.never` (`.github/workflows/`), and the
     whole fix is within `fix.most_patch_bytes`.
   - Every hunk must match the head's text where it applies. The job ran on
     the merge of the pull request into its base, so a hunk the base moved is
     refused.
   - The commit names the head it read (`expectedHeadOid`), so a push in
     between fails it.

4. **The re-run is of a run whose every failed job is the infrastructure's.**
   - The re-run applies only to the head's first run of a relayed workflow,
     at its first attempt. There, when every failed job failed at a step
     `config.json`'s `rerun.steps` names, or at no step (a lost runner), the
     failed jobs are re-run with one request.
   - A run with a real failure needs a push, which runs CI again.
   - The explainer works out what was re-run, and why, from the run's first
     attempt, by the same rule. So the comment says it while the re-run runs
     and after, and nothing is stored.
   - Job names come from the pull request's workflow, so the comment names a
     re-run job by its gate in `gates.json`, or as "a job", with its link.

5. **The checklist and the welcome.**
   - `bot-checklist.yml` runs on `pull_request_target` (opened, ready for
     review, converted to draft). It checks out only main's tables, reads the
     pull request through the API, and writes the sticky comment where it
     exists.
   - Where the author is `FIRST_TIME_CONTRIBUTOR`, `FIRST_TIMER` or `NONE`
     (`checklist.welcomed`), it opens the comment with the welcome while CI
     waits for approval.
   - The checklist is written by one function, whichever job runs it.

6. **Three tag rulesets.** One ruleset's bypass skips all its rules, so each
   kind of write has its own ruleset.

   | Ruleset | Tags | Rules | Bypass |
   |---------|------|-------|--------|
   | release tags: the maintainer creates | `refs/tags/v*.*.*` | creation | the Admin repository role |
   | release tags never move | `refs/tags/v*.*.*` | update, deletion | none |
   | line tags: the bot moves | `refs/tags/v*`, less `refs/tags/v*.*.*` | creation, update, deletion | the App |

   - The maintainer signs and pushes `vX.Y.Z`, as ADR-0019 decides.
   - Nobody moves or deletes a release tag.
   - Only the App creates or moves `v0.1`, and from 1.0.0 `v1`. The release
     workflow's `line` job moves it with the App's token.

7. **The changelog preview is the explainer's.** The explainer computes the
   line and its section from the title and body it reads through the API, by
   main's `cliff.toml`'s groups and template. It shows the line inside a code
   block, which renders no Markdown, link or mention.

8. **Every line of the bot's scripts is covered.** The `scripts` job runs
   `unit.py`, which records each line with `sys.monitoring` and fails on any
   line of `bot_*.py` or `github_api.py` that no test runs, less each
   script's `__main__` entry.

9. **The bot's words and limits are tables in main.**
   - `.github/bot/explain.json` holds every sentence the relay, the
     explainer, the checklist, the welcome and the duplicate-issue reply
     write.
   - `.github/bot/commands.json` holds every reply a command writes.
   - `.github/bot/config.json` holds every limit and list:
     - the relayed workflows;
     - the infrastructure's steps;
     - the evidence, comment and fix caps;
     - the branches and paths `/fix` never writes;
     - who is welcomed.
   - A test fails where a sentence is used and missing, or present and
     unused.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **A name check alone on `/fix`'s branch** | A branch named `main`, or after a protected branch, passes any pattern. |
| **Trusting the latest run on the head SHA** | Another pull request, or a push, can have the same head. |
| **The line tag outside any ruleset, moved by `GITHUB_TOKEN`** | Every workflow holding `contents: write` could move the tag every consumer runs. |
| **`GITHUB_TOKEN` as the ruleset's bypass actor** | It would let every workflow of the repository move the line tags. |
| **The App creating the release tags** | A tag the App makes is unsigned, and ADR-0011 publishes only a tag the maintainer's key signs. |
| **A second copy of the App's key in the `release` environment** | Two copies to rotate. A `v*.*.*` tag run is as trusted as `main`, because only the maintainer can create one. |
| **Re-running each infrastructure job by itself** | A re-run cannot start while another runs, and a run with a real failure needs a push anyway. |
| **Storing what was re-run in the comment's mark** | A later relay of the other workflow would drop it. The first attempt says it every time. |
| **The pr job computing the changelog preview as evidence** | Evidence is untrusted, so the bot would render it in any case. Computing it from the API's title by main's `cliff.toml` keeps one source. |
| **The welcome from the relay** | A first pull request's CI waits for approval, so no `workflow_run` fires to relay. |

## Consequences

**The App writes only where the pull request's author could push**, and only
bytes the pull request's own run computed for its own head.

**`@v0.1` moves only by the App, after a signed release is published.**

**A first pull request learns that CI waits for approval as it opens**, and
every pull request's comment says what each red gate needs.

**The maintainer creates the App and the `bot` environment once**, and
applies three tag rulesets. The `release` environment's tag policy narrows
from `v*` to `v*.*.*`.

## Related

- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): the bot this ADR amends
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): signed release tags and the release line
- [ADR-0018](0018-the-documentation-is-versioned-and-tested-with-the-code.md): the changelog `cliff.toml` writes
