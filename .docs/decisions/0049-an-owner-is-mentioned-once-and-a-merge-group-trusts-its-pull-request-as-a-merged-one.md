# ADR-0049: An owner is mentioned once, and a merge group trusts its pull request only as a merged one is trusted

**Status:** Accepted
**Date:** 2026-11-01

## Context

ADR-0022 leaves facts to the first step of the build: whether an edit that
adds a mention notifies, whether `github-actions[bot]` can mention a team,
the suffix of a merge group's branch, and whether a merge group can save
into the default branch's cache. What holds, read at source:

- **An edit notifies.** GitHub's documentation on mentions says: "People
  will also receive a notification if you edit a comment to mention their
  username or team name." A person is notified only where they can read the
  repository, and, in an organisation's repository, belong to it.
- **`GITHUB_TOKEN` cannot mention a team.** Its access stops at the
  repository the workflow runs in, so it cannot read an organisation's
  teams, and a team mention in a comment `github-actions[bot]` writes
  notifies no one. A token of a user or a GitHub App that can read the
  organisation's members can.
- **A merge group's branch names its pull request and its base.** The
  `merge_group` runs of `github/docs` run on
  `gh-readonly-queue/main/pr-46758-85c329847be78b0a7192b7c949beca23d38ca32f`:
  the pull request's number, then the group's base commit, the parent of
  its head. A base branch whose name holds a slash keeps the slash in the
  branch.
- **GitHub lands the group's own commit.** That pull request's
  `merge_commit_sha`, `33b1f4a6fec2d9b6fd3d374afa56bcd9f0875b57`, is the
  head commit its group was judged on, and each check suite the group ran
  there names the group's branch as its `head_branch`.
- **A passed record lives only in its own scope's ledger.** ADR-0007 writes
  `passed`, with `ownScopeProofs`, into the ledger of the scope that ran.
  ADR-0022 item 7 asks for a pull request's `passed` record and also says
  the pull request's own ledger is never read.
- **A merge group saves no ledger into any cache.** It has no proof scope
  (ADR-0022 item 6), and the action and the reusable workflow save a ledger
  only for the run's own scope (ADR-0007). GitHub keeps every cache under the
  ref that saved it, so what else a group's job saves, such as a dependency
  installer's cache, is kept under the group's own branch.
- **ADR-0022 records owner groups, and a person can be in several.** A user
  named by two rules, alone in one and beside a team in another, would be
  mentioned once for each group.

## Decision

### Owners

1. **The record holds owners, one by one.**
   - Under `owners.mention: first`, each user and team is mentioned in the
     first update whose list of survivors on changed lines heads a group
     naming it, and named without a mention in every later update and in
     every later heading of the same one. A group is therefore mentioned at
     most once, as ADR-0022 decides, and so is each person and team in it.
   - The record is a hidden line the sticky comment ends with,
     `<!-- mutation-gate: mentioned @acme/payments @jo -->`. Each write reads
     it from the comment as it stands and writes it back with this update's
     mentions added, the planned and re-checked states included. Where a
     body holds more than one, the last is read.
   - The comment's markdown, which `deliver` writes for a run that held no
     token (ADR-0007), carries a placeholder for each user and team a
     group's heading names, `<!-- mutation-gate: mention @jo -->`, and the
     writer resolves them. A placeholder on any line but a group's heading
     stays as it is, so the project's own text, a diff included, mentions
     and records no one.
   - Owners are matched in any case, as GitHub matches a handle.

2. **An edit mentions, so no separate comment is posted.** The mention is
   added to the sticky comment itself, by the edit that updates it.

3. **A comment `github-actions[bot]` writes mentions no team.**
   - A team is named without a mention there, and is not recorded, so an
     update written with a token that can mention teams mentions it.
   - A comment any other identity writes mentions teams.
   - `doctor` advises, under the slug `teams-not-mentioned`, where
     `owners.mention` is `first` and the CODEOWNERS as it stands names a
     team.

4. **"Without `@`" is the escaped `@` every report prints.** An owner not
   mentioned is printed as CODEOWNERS spells it, with its `@` written as the
   entity `&#64;`, as ADR-0009 escapes every owner today: GitHub shows the
   handle and notifies no one.

### Merge queues

5. **The pull request is read from the group's branch.** The suffix
   `pr-<number>-<base commit>` names it. No API lookup is built, and a
   branch that does not end so applies no shortcut, so the group is judged.

6. **A group reads its pull request's `passed` record only where
   `ci.trustMergedPullRequests` is true.**
   - The record is read from the pull request's own ledger, that record
     alone: none of its proofs is carried, and the group carries from the
     default branch's ledger only.
   - This is the opt-in under which ADR-0005 reads the same record for a
     pushed commit, for the same reason: the pull request's own code could
     have written it.
   - With it off, as by default, every group is judged change-scoped from
     `merge_group.base_sha`.
   - This amends ADR-0022 item 7's "the pull request's own ledger is never
     read".

7. **The push after the queue reads its own commit's check suites.**
   - A pushed commit reaches nothing where the latest check-run GitHub
     Actions completed on it under the name `ci.check` concluded in success
     in a check suite whose `head_branch` is under `gh-readonly-queue/`. The
     group ran on that commit, so its tree is the group's.
   - A group has no scope, so its verdict used no proof of its own.
   - It applies where `ci.trustMergedPullRequests` is true, as ADR-0005's
     rule it extends does.
   - A pushed commit that is not the group's own is judged.
   - This amends ADR-0022 item 8's search for another commit of the same
     tree.

8. **No cache step changes for `merge_group`.** A group saves no ledger into
   any cache, and nothing into the default branch's, so there is nothing for
   the reusable workflow to skip.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **A record of owner groups, as ADR-0022 words it** | A person in two groups is mentioned twice. |
| **The record in the proof store** | On a pull request, `deliver` holds no store's credentials (ADR-0007), and the comment already holds what it wrote. |
| **Resolving the mentions where the markdown is written** | The run that writes it holds no token and cannot read the comment, so `deliver` would post every mention again. |
| **A separate comment for the mentions** | GitHub notifies on the edit already, and a second comment on every pull request is noise. |
| **Owners printed without their `@`** | `@jo` printed as `jo` reads as another word, and differs from how every other report prints owners. |
| **Finding the pull request through the API** | The branch names it, as GitHub's own runs show. |
| **A group's shortcut on the check-run alone** | Any workflow at that commit, the pull request's own included, can make a check-run under any name (ADR-0005). |
| **Never reading the pull request's record** | Every group is judged, even the up-to-date pull request first in the queue; that stays the default. |
| **Searching other commits for the group's tree after a push** | GitHub lands the group's own commit, and any other commit is judged, which costs a run and never a wrong verdict. |

## Consequences

**Each person and team hears about a pull request's survivors once,** from
the update that first gives them one, and a team the comment cannot
mention is still named.

**A merge queue on the default settings judges every group,** from its base,
and trusts nothing a pull request wrote. With merged pull requests trusted,
an up-to-date pull request's group and the push after it cost one planning
job each.

## Related

- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): `ci.trustMergedPullRequests`, and the pushed commit a merged pull request proved
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): `passed`, own-scope proofs, and `deliver`
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the sticky comment, and what it escapes
- [ADR-0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md): owners, mentions and merge queues, which this amends
