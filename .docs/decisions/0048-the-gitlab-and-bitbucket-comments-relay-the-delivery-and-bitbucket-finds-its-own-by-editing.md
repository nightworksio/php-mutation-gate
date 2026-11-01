# ADR-0048: The GitLab and Bitbucket comments relay the delivery, and Bitbucket finds its own by editing

**Status:** Accepted
**Date:** 2026-11-01

## Context

ADR-0024 puts the sticky comment, with its planned state, on GitLab merge
requests and Bitbucket pull requests, and the survivors in Bitbucket's Code
Insights. Built against the two APIs and the gate as it stands, these hold:

- **The relay exists.** `deliver` (ADR-0007) sends what a run of the project's
  code left in `.mutation-gate/delivery` under `--deliver-later`: the comment's
  markdown, which that run rendered and escaped as ADR-0009 escapes everything
  the project wrote. It runs from the gate's own installation, reads no config,
  takes no destination from the delivery, and writes the comment to the pull
  request its own environment names. The plan leaves the planned state, a
  re-check the comment over that state, and the verdict the judged state.
- **The pipeline is the branch's.** A GitLab merge request's pipeline is its
  branch's `.gitlab-ci.yml`, and a Bitbucket pull request's its branch's
  `bitbucket-pipelines.yml`. Whoever can push such a branch writes the job
  that holds the token, and any variable the pipeline reaches.
- **The comment is one renderer's.** The comment is rendered from the verdict
  by one renderer, with its folding, its cuts and its escaping. The JSON report
  holds the verdict's fields, not a verdict the renderer reads back.
- **GitLab.** The notes API lists, posts and puts a merge request's notes, with
  a project access token as a bearer token, and `/user` names the token's bot
  user. GitLab finds mentions and references, `@user`, `#12`, `!3` and
  `~label`, in the text of the rendered note, where an entity such as `&#64;`
  is already its character, and in no element of code; a reference never spans
  two elements. GitLab gives a child pipeline none of the merge request's
  variables, and a trigger job hands its own down.
- **Bitbucket comments.** The pull request comments API lists, posts and puts
  a pull request's comments as `{"content": {"raw": …}}`, with a repository
  access token as a bearer token. `/2.0/user` does not answer such a token,
  which belongs to the repository, not to an account, so the token cannot learn
  who it comments as. Bitbucket refuses to let a token edit a comment someone
  else wrote. Bitbucket shows a comment's raw HTML as text: an HTML comment,
  `<details>`, `<summary>` and `<code>` among it. A Markdown link reference
  definition shows nothing.
- **Bitbucket Pipelines.** A pipeline has one `final` step, its last item,
  which cannot deploy. A deployment environment's variables reach one step,
  and a pipeline deploys to an environment once. Repository variables, secured
  ones included, reach every step.
- **Code Insights.** A report is put per commit and a report id, and its
  annotations are posted up to 100 a request, up to 1,000 a report. Putting a
  report again keeps the annotations it had. Inside Pipelines, the proxy at
  `http://localhost:29418` authorises a request to `http://api.bitbucket.org`
  with no credential.

## Decision

1. **The comment jobs relay the delivery.** On GitLab and Bitbucket, as on
   GitHub, the plan and the verdict that holds no keys run with
   `--deliver-later` on a merge request or pull request, and a job running the
   gate's released image, pinned in the template, sends each delivery with
   `deliver`. It posts the markdown the delivery holds, as GitHub's `deliver`
   jobs do, to the merge request or pull request its own variables name, with
   the token in its own environment. A delivery is read as ADR-0007 reads one,
   within its byte limits and refusing a key it does not take, and no
   destination, store or variable comes from it. Whoever could make the
   verdict job write other markdown can edit the comment job itself, so
   rendering the comment again from the JSON report guards nothing more. This
   amends ADR-0024's decisions 7 and 9.
2. **`deliver` reads each forge's default variable.** Under GitLab CI it writes
   a merge request note with `MUTATION_GATE_GITLAB_TOKEN`, under Bitbucket
   Pipelines a pull request comment with `MUTATION_GATE_BITBUCKET_TOKEN`, and
   elsewhere GitHub's comment with `GITHUB_TOKEN`. It reads no config, so a
   `with.tokenEnv` applies where the reporter writes the comment itself, as a
   `with.urlEnv` does for alerts.
3. **GitLab's note.**
   - It is found among the notes of the user `/user` names, oldest first, at
     most 30 pages of 100, holding the marker and no system note, and put in
     place, or posted where there is none.
   - Each `@`, `#`, `!` and `~` the escaping wrote as an entity stands in a
     `<span>` of its own, so GitLab finds no reference in what the project
     wrote.
   - The line that would link the HTML report links the pipeline, which keeps
     the report among its artifacts.
   - The parent pipeline's `mutation-comment` job sends the planned state after
     the plan. The child pipeline's `mutation-gate-comment` job sends the
     verdict after `mutation-gate-verdict`, which keeps its delivery as an
     artifact. Both extend the template's hidden job `.mutation-gate-comment`,
     which names the image. The plan writes the child's comment job only
     where it ran with `--deliver-later`, so a template from before it still
     runs. The trigger job hands the child the merge request's IID in
     `MUTATION_GATE_MERGE_REQUEST_IID`.
   - The token is a masked variable that is not protected, since a merge
     request's pipeline runs on its own branch. Every job that extends
     `.mutation-gate` drops it before `composer install`.
4. **Bitbucket's comment.**
   - The sticky comment is the first comment holding the marker, oldest first,
     none deleted, a reply or on a line, that the token may edit. Each in turn
     is put in place, and one Bitbucket refuses, `403` or `404`, is passed over.
     At most ten are tried, and where none is the token's own, a new comment is
     posted. Written over the planned state alone, a comment no longer in it is
     put back as it was, which tells the token's own from another's and changes
     nothing.
   - The marker is the link reference definition `[//]: # (mutation-gate)`. A
     folded block is its summary in bold, with everything under it shown, and
     inline code is a code span of its text, fenced by more backticks than any
     run inside it. Every other entity stays one, which Bitbucket shows as its
     character and reads as no mention. The line that would link the HTML report
     links the pipeline.
   - A pull request's pipeline sends the planned state from a step after the
     plan, and the verdict from its `final` step, so the verdict is an
     ordinary step before it. After a failed shard, Bitbucket skips that
     verdict, the pipeline is red, and the comment keeps its planned state, as
     ADR-0024 accepts for the default branch's verdict. This amends ADR-0024's
     decision 1.
   - The token is a secured repository variable, since two steps send and a
     `final` step cannot deploy. Every step that runs the project's code drops
     it before `composer install`.
5. **Code Insights.** `bitbucket-insights` deletes the commit's report
   `mutation-gate` before it puts it again, so no annotation of an earlier run
   stays, then posts the annotations. The report holds the project's score and
   how many mutants are not killed. Each annotation's id is
   `mutation-gate-<mutant id>`, at its file and line, `HIGH` in a set that
   failed and `MEDIUM` otherwise, its summary and details those of GitHub's
   annotation, each one plain line.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **The comment job renders the comment again from the JSON report** | A second renderer of the comment, kept in step with the first, guarding nothing the pipeline's own author cannot write. |
| **Bitbucket's comment found by an `identity` option, the bot's account** | The account is known only once the token has commented, and editing tells the token's own comment with nothing to set. |
| **Bitbucket's marker as an HTML comment, and folds as `<details>`** | Bitbucket shows raw HTML as text. |
| **Bitbucket's verdict as the `final` step, writing the comment itself** | The token would share a step with the project's code. |
| **Bitbucket's token in a deployment environment** | Two steps send the comment, a pipeline deploys to an environment once, and a `final` step cannot deploy. |
| **GitLab's `@` as the entity alone** | GitLab decodes the entity before it finds references. |
| **GitLab's `@` behind a backslash** | Inside an HTML block, such as a folded summary, the backslash is shown and the reference still found. |
| **The child pipeline's comment job written on every merge request** | A template from before the comment job has no `.mutation-gate-comment`, and its pipeline would not start. |

## Consequences

**A merge request or pull request on GitLab or Bitbucket gets the same sticky
comment as GitHub's,** written by a job that loads none of the project's code,
with the token's reach the only thing a branch's author can take.

**Bitbucket's comment is found with no account to set,** and its markdown is
the comment's, made to show on a forge that shows no HTML.

**A Bitbucket pull request's failed shard leaves its comment planned,** with
the pipeline red.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): `deliver` and `--deliver-later`
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the sticky comment, its escaping and its annotations
- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): the relay pattern and the planned state
- [ADR-0024](0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md): the comments beyond GitHub, which this amends
