# ADR-0102: One comment section shapes every pull request comment

**Status:** Accepted
**Date:** 2026-11-06
**Amends:** [0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): what the pull request comment shows; [0100](0100-a-heatmap-scores-every-file-and-function-against-the-base.md): `heatmap.comment` becomes `comment.heatmap.most`

## Context

The pull request comment showed every section, open, in a fixed order, with
fixed caps on each list. It was written in every state of a run and on every
pull request, and a passing run posted one too. A team that only reads the
verdict and the survivors scrolled past the rest on each push. A team on a
busy repository wanted a quieter comment, and one on a small project wanted
everything. And the GitHub, GitLab and Bitbucket comments could not differ.

## Decision

1. **A top-level `comment` section shapes every `*-comment` reporter's
   comment.** A reporter's `with.comment` takes the same keys and lays them
   over `comment`, key by key, for its comment alone. `heatmap.comment`
   becomes `comment.heatmap.most`; `heatmap.files` and `heatmap.methods`
   stay where they are.

2. **`comment.sections` is an ordered list:** a section listed is shown, in
   that order. The sections are `verdict`, `security`, `trees`, `sets`,
   `heatmap`, `survivors`, `unjudged`, `ignored`, `problems`, `floors`,
   `report` and `costs`. `verdict` is required and first; a list without it
   first exits 2. A run that cannot judge says why whatever the list holds.

3. **Each list has a cap**, `comment.<list>.most`: `survivors`, `security`,
   `unjudged` and `ignored` show 20, `heatmap` 5; `0` leaves the section
   out. `comment.text.most`, 1,500, caps the characters of one diff or hint.
   `comment.survivors.lines` is `changed` or `all`, and
   `comment.survivors.detail` is `full` or `row`. `comment.try` and
   `comment.reproduce` say whether each survivor says what to try and how to
   reproduce it. The whole comment is still fitted to the 65,536 characters
   a channel holds, as it always was.

4. **`comment.open` lists the sections shown open**, `[verdict, trees,
   heatmap]` by default; every other section is folded. Bitbucket cannot
   fold, so it shows everything open. A section with nothing to say is left
   out rather than folded empty. The step summary shows every section open.

5. **`comment.mode` is `sticky`**, one comment updated in place, by default;
   `new` writes a new comment each run, and `new-hiding-old` a new one that
   hides the one before, on GitHub. `comment.states` lists the states that
   write it, `[planned, rechecked, verdict]` by default.

6. **`comment.passing` defaults to `update`:** a passing run updates the
   comment already there and posts no new one. `always` posts as any other
   run does, and `never` writes nothing and says why in the run's output.
   This is the one default that changes: a passing run on a pull request
   that had no comment used to post one, and now posts none.

7. **`comment.on` lists the kinds of run that comment**, `[pull-request]` by
   default, which takes a GitLab merge request too. `comment.drafts`,
   `comment.sampled` and `comment.cutShort` say whether a draft's, a sampled
   and a cut-short run comment; each does by default.

8. **The PHP builder writes every key** with `Comment::sections()`,
   `open()`, `states()`, `on()`, `mode()`, `passing()`, `lines()`,
   `detail()`, `most()`, `with()` and `without()`, over the enums of
   `Core\Comment`.

9. **Azure Repos and Buildkite take the comment too.** `azure-comment` writes
   one thread on the pull request through Azure Repos' REST API, with
   `SYSTEM_ACCESSTOKEN`, and passes over a thread the token cannot edit, as
   `bitbucket-comment` passes over a comment. `buildkite-annotation` writes the
   build's one annotation through `buildkite-agent annotate`, by its context,
   on pull requests and on any other build, since an annotation sits on the
   build rather than on a conversation. `azure-summary` attaches the verdict to
   the build's summary, as `github-summary` writes the step's. A config names
   each under `reports`; none is chosen for a run.

## Alternatives considered

- **A setting per reporter only.** Three reporters would each need the same
  keys written three times; the section with per-reporter overrides keeps
  one place and still lets one channel differ.
- **A list of sections to hide.** A list of those shown also orders them,
  and a new section does not appear in a comment that did not ask for it.
- **Keeping a passing run's comment.** On a repository with many green pull
  requests it is noise; `always` keeps it for a team that wants it.

## Consequences

**A team chooses how much the comment says**, and in what order, once for
every channel or per channel.

**A passing pull request with no comment stays quiet.** A comment already
there is still updated, so a pull request that failed and then passed says
it passed.

**The comment is shorter by default:** what a reviewer acts on is open, and
the rest is one click away.

## Related

- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the comment and the step summary
- [ADR-0048](0048-the-gitlab-and-bitbucket-comments-relay-the-delivery-and-bitbucket-finds-its-own-by-editing.md): the GitLab and Bitbucket comments
- [ADR-0100](0100-a-heatmap-scores-every-file-and-function-against-the-base.md): the heatmap
