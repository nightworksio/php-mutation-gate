# ADR-0024: The gate runs on Bitbucket, Azure DevOps and Jenkins, comments on GitLab and Bitbucket without exposing a token to merge request code, plugs into Composer and hook managers, and aggregates an organisation

**Status:** Accepted
**Date:** 2026-09-30

## Context

The gate plans, runs and judges on GitHub Actions, GitLab CI, Buildkite,
CircleCI and a JSON plan (ADR-0006). It comments and annotates only on
GitHub (ADR-0009). It is started from the command line, git's hooks
(ADR-0010) or CI. Its default branch publishes a badge, a trend and its
savings to a data branch (ADR-0009 decision 5, ADR-0017 decision 13). Four
gaps remain, and the facts that shape each, read in each platform's
documentation, are these.

- **Three more CIs.** A plan becomes jobs, a job learns its shard, the run
  names its ref, whether it is a pull request and its default branch, and a
  cache is either a trust boundary or not (ADR-0006 decision 5, ADR-0007
  decision 5).

  | | Bitbucket Pipelines | Azure DevOps | Jenkins |
  |---|---|---|---|
  | A job count set at runtime | No: `parallel` is static, at most 100 steps per pipeline; dynamic pipelines, a generated YAML uploaded by a pipe, are a Premium feature | Yes: `strategy: matrix` takes a runtime expression holding a JSON object from an output variable; it always makes at least one job | Yes, in a `script` block: a map of closures handed to `parallel` |
  | The shard | `BITBUCKET_PARALLEL_STEP` (0-based) and `BITBUCKET_PARALLEL_STEP_COUNT` | the matrix leg's variables, or `System.JobPositionInPhase` and `System.TotalJobsInPhase` under `parallel: N` | the closure's own argument |
  | Files between jobs | artifacts, kept 14 days, 1 GB each; a parallel group's artifacts may not reach its siblings | `PublishPipelineArtifact` and `DownloadPipelineArtifact` | `stash` and `unstash` |
  | The verdict after a failed shard | a `final` step, which runs whatever the others did, and which cannot deploy | `condition: succeededOrFailed()` | `post { always { … } }` |
  | Ref, pull request, run | `BITBUCKET_BRANCH`, `BITBUCKET_PR_ID`, `BITBUCKET_BUILD_NUMBER` | `Build.SourceBranch`, `System.PullRequest.PullRequestNumber` (GitHub) or `System.PullRequest.PullRequestId`, `System.PullRequest.TargetBranch`, `Build.BuildId` | `BRANCH_NAME`, `CHANGE_ID`, `BUILD_TAG`, `GIT_COMMIT` |
  | Default branch | not named | not named | not named |
  | Cache | shared by every branch of the repository: no boundary | a pull request build reads its source's, its target's, `main`'s and `master`'s caches and writes only its own; any other run reads its own branch's, `main`'s and `master`'s: a boundary | none built in |
  | Forks | pull requests from forks start no pipeline | fork builds get no secrets by default | depends on the SCM plugin |

  No Azure variable names the YAML file a pipeline runs. Azure's
  `System.AccessToken` reaches a step only when the pipeline maps it into
  that step's environment.

  On Bitbucket, repository and workspace variables, secured ones included,
  reach every branch's pipeline, and each branch's author controls its
  `bitbucket-pipelines.yml`. A deployment environment's variables reach only
  the step that deploys to it, one step per environment in a pipeline, and
  restricting an environment's deployments to a branch takes Premium. While
  one pipeline deploys to an environment, Bitbucket pauses any other at its
  step that deploys there; once the first deployment ends, the paused
  pipeline is resumed or rerun by hand. A step can request an OpenID Connect token, `BITBUCKET_STEP_OIDC_TOKEN`, whose
  subject names a branch only through a deployment environment.
- **Comments beyond GitHub.**
  - **GitLab.** A merge request note is created and updated through the
    notes API, and a job finds its own note by its author. `CI_JOB_TOKEN`
    may only read notes, so posting one needs a project or group access
    token. A note holds up to 1,000,000 characters. A line note is a
    discussion with a position built from the merge request's versions. The
    Code Quality report appears in the merge request widget on every tier,
    and on the diff on Ultimate only. A fork's merge request pipeline runs in
    the fork, with the fork's variables.
  - **Bitbucket.** A pull request comment is created and updated through the
    pull request comments API, with a token that has `pullrequest:write`.
    Code Insights holds a report per commit with up to 1,000 annotations,
    posted at most 100 per request. Inside Pipelines, Code Insights is
    reachable with no credential through the proxy at `localhost:29418`.
    Whether that proxy also authorises comments is not documented.
  - **The bar.** A GitHub comment is posted with the job's own
    `GITHUB_TOKEN`: short-lived, scoped to the job, read-only for forks. A
    GitLab or Bitbucket token is long-lived. A job that runs merge request
    code, as the verdict job does through `composer install` and the gate in
    the merge request's own `vendor`, can read any variable in its
    environment. ADR-0019 keeps pull request content away from anything that
    can write.
- **Composer and hook managers.**
  - A Composer plugin is a package of type `composer-plugin` with a
    `PluginInterface`, and a `CommandProvider` adds `composer <command>`.
    Composer 2.2 and later require it in `allow-plugins`. Non-interactive
    Composer fails on a plugin that is not listed there, unless the plugin
    sets `extra.plugin-optional: true` (Composer 2.5.3 and later), which
    makes Composer skip it. A root `scripts` entry makes `composer <name>`
    work with no plugin, and a script times out after 300 seconds unless
    `Composer\Config::disableProcessTimeout` comes first.
  - CaptainHook hands a hook's stdin to an action as the `{$STDIN}`
    placeholder, shell-escaped.
  - GrumPHP runs only `pre-commit` and `commit-msg`, not `pre-push`. A
    third-party task reaches it through YAML its extension imports, and its
    extensions cannot load PHP from its scoped PHAR.
  - The pre-commit framework has no PHP language. `language: unsupported`
    runs a command from the user's environment. A hook with `stages:
    [pre-push]` receives the refs as `PRE_COMMIT_FROM_REF` and
    `PRE_COMMIT_TO_REF`, not on stdin.
- **An organisation's view.** GitHub Pages deploys from Actions
  (`actions/upload-pages-artifact`, `actions/deploy-pages`, with `pages:
  write` and `id-token: write`). A private Pages site needs Enterprise
  Cloud, and on Free its repository must be public. The REST API allows
  cross-origin requests from any origin. `raw.githubusercontent.com`
  documents no cross-origin policy.

## Decision

### Three more CIs

1. **`ci.plan` gains `bitbucket`, `azure` and `jenkins`, each rendering a plan
   the way its CI allows.**
   - **Azure DevOps.** `plan --ci=azure` sets an output variable holding the
     matrix, `{"s1": {"SHARD": "1"}, …}`, with leg names of letters and
     digits only, which the `mutation` job's `strategy: matrix` reads. A plan
     with no shards emits one leg that passes having run nothing, because
     Azure always makes at least one job. The verdict job runs with
     `condition: succeededOrFailed()`.
   - **Jenkins.** `plan --ci=jenkins` prints the JSON plan. The documented
     Jenkinsfile reads it with the Pipeline Utility Steps plugin's `readJSON`
     and builds the map it hands to `parallel`, one closure per shard, each
     on an agent of its own and naming its shard in `SHARD`, with the plan
     and each shard's results passed by `stash` and `unstash`. It runs the
     verdict in `post { always { … } }`. A pull request is read from
     `CHANGE_ID`, and a tag, `TAG_NAME`, has no scope, as on Bitbucket. Only
     a multibranch project sets these, so the Jenkinsfile runs from one.
   - **Bitbucket Pipelines.** Parallelism is fixed in the pipeline, as on
     CircleCI (ADR-0006 decision 5): `plan --shards=<N>`, and each step's
     shard is `BITBUCKET_PARALLEL_STEP` plus 1. The verdicts of the default
     branch's pipeline and of the custom pipeline `mutation-full`, the full
     run, are each the last ordinary step, deploying to the environment
     that holds the store's keys (decision 4), because a `final` step cannot
     deploy. After a failed shard Bitbucket skips such a verdict, and the
     pipeline is red with no *cannot judge* report, and while one such
     verdict deploys, Bitbucket pauses any other at its own. A pull request's
     verdict is the `final` step. The README also shows the
     dynamic-pipelines form for Premium workspaces.

   This amends ADR-0006 decision 5, whose two tables gain a row for each, and
   ADR-0007 decision 3, whose list of the run a proof names gains three
   entries.

   | CI | Ref | Pull request | Default branch | The run a proof names |
   |----|-----|--------------|----------------|-----------------------|
   | Bitbucket | `BITBUCKET_BRANCH` | `BITBUCKET_PR_ID` is set | `ci.defaultBranch` | `bitbucket:<BITBUCKET_BUILD_NUMBER>` |
   | Azure DevOps | `Build.SourceBranch` | `Build.Reason` is `PullRequest`, with `System.PullRequest.PullRequestNumber` where Azure sets it (a GitHub pull request, whose id is not its number), and `System.PullRequest.PullRequestId` otherwise | `ci.defaultBranch` | `azure:<Build.BuildId>` |
   | Jenkins | `BRANCH_NAME` | `CHANGE_ID` is set | `ci.defaultBranch` | `jenkins:<BUILD_TAG>` |

2. **None of the three names its default branch, so `ci.defaultBranch`
   decides**, and failing that git's `origin/HEAD`, and failing that `main`,
   as for the JSON plan and CircleCI (ADR-0006 decision 5). `init --ci` for
   these three writes `ci.defaultBranch` into the config it writes, and
   where a config is kept that does not set it, says to set it to the branch
   the template is written for.

3. **Each CI's definition file is named for reach and the proof key.**
   `ci.bitbucket.definition` (a path, `bitbucket-pipelines.yml` by default),
   `ci.azure.definition` (`azure-pipelines.yml`) and `ci.jenkins.definition`
   (`Jenkinsfile`) name the file that runs the gate, as
   `ci.buildkite.definition` does. The file each names reaches everything
   and is read by every key through ADR-0005's rule 1 and ADR-0007's item 6,
   so the path **affects results**. The rest of these keys judge or report
   only. Where the named file is a template another pipeline takes in, as
   `init --ci=azure` writes it, that other pipeline is outside the key: a
   change to it, such as its PHP image, reaches nothing, as with Buildkite's
   uploading pipeline. This amends ADR-0005 decision 4.

4. **Each template keeps the ledger where its CI draws a trust boundary.**
   - **Azure DevOps:** the directory store in the `Cache@2` task, keyed by the
     digest of the scope, with the default branch's key restored second
     where Azure lets the run read that branch's caches: a pull request reads
     its target's, and any other run reads only `main`'s and `master`'s
     besides its own. A pull request build cannot write the target branch's
     cache, so this is a boundary, as GitHub's is.
   - **Bitbucket and Jenkins:** an S3-compatible store (or any store whose
     writes need credentials), with credentials only default-branch builds
     hold. Bitbucket's caches are shared by every branch and Jenkins has
     none, so neither is a boundary. This is ADR-0007 decision 5's reasoning
     for Buildkite and CircleCI.
   - **Bitbucket's credentials** are variables of the deployment environment
     `mutation-gate-store`, which only the deploying verdicts of decision 1
     name. Restricted to the default branch, which takes Premium, it is the
     boundary; without that restriction any branch whose pipeline names it
     gets the keys, and the template and README say so. Every other step,
     the default branch's plan included, holds no keys and reads the default
     branch's ledger through `proofs.store.with.publicUrl` (ADR-0013).
   - **Jenkins' credentials** are the username-and-password credentials
     `mutation-gate-store`, which only the default branch's verdict binds. A
     credential reaches every build of the folder that holds it, and a
     branch's author writes its Jenkinsfile, so held in a folder whose
     multibranch pipeline builds the default branch alone it is the
     boundary; held beside every branch, any branch whose Jenkinsfile Jenkins
     runs gets the keys, and the template and README say so. Every other
     step holds no keys and reads the default branch's ledger through
     `proofs.store.with.publicUrl`.

   This amends ADR-0007 decision 5.

5. **What the runners never see grows with these CIs.** Azure's
   `SYSTEM_ACCESSTOKEN`, the `AZURE_DEVOPS_EXT_PAT` that `az devops` reads,
   and Bitbucket's `BITBUCKET_STEP_OIDC_TOKEN` join what every run withholds
   from the runner
   (`CiPlan::withheld`, ADR-0004 decision 3), as do the comment tokens of
   decision 7. Jenkins hands a build no credential of its own, so its plan
   adds none. A project adds any other Bitbucket or Jenkins credential it
   passes to `runner.withhold`.

6. **`init --ci=bitbucket|azure|jenkins` writes a pinned template**, by the
   rules of ADR-0015 decisions 13 to 17. Azure's and Bitbucket's templates
   are validated against each provider's published schema. Azure's is
   MIT-licensed, kept in the repository and read offline. Bitbucket's states
   no licence, so the test fetches it from its URL into the system's
   temporary directory, holds it to a SHA-256 digest, and fails, never skips,
   where the fetch fails or the digest differs.
   Jenkins publishes no schema for a Jenkinsfile, so its template is held by
   its snapshot test alone. This amends ADR-0015 decisions 13 to 17.
   - **Azure DevOps.** `init --ci=azure` writes the gate's jobs as a template,
     `.azure/mutation-gate.yml`, and prints the `- template:` line that takes
     them into the pipeline Azure runs, which it never edits (ADR-0015
     decision 13). A config `init` writes names that template as
     `ci.azure.definition`, as it names Buildkite's pipeline, and where a
     config is kept, `init` says to. `Cache@2` saves only from a job that
     succeeds, so a last job, run whatever the verdict decided, saves the
     ledger the verdict wrote.
   - **Bitbucket Pipelines.** `init --ci=bitbucket` prints the pipelines to
     add to `bitbucket-pipelines.yml`, which it never edits: the default
     branch's, every pull request's and `mutation-full`'s, each a plan step,
     four parallel shard steps and the verdict of decision 1, each step
     cloning the full history. A config `init` writes sets
     `ci.defaultBranch` (decision 2).
   - **Jenkins.** `init --ci=jenkins` prints the declarative pipeline to add
     to the Jenkinsfile `ci.jenkins.definition` names, which it never edits:
     a plan stage, the shards of decision 1 and the verdict, with a `cron`
     trigger for the full run on the default branch. A config `init` writes
     sets `ci.defaultBranch` (decision 2).

### Comments beyond GitHub

7. **A long-lived token never shares a process with merge request code.**
   - A separate job, `mutation-comment`, posts the comment. It runs the
     released PHAR or container image of the gate (ADR-0022), pinned by
     version in the template, and never the merge request's `vendor`.
   - It reads the verdict job's JSON report as untrusted data. Only fields
     valid against `resources/report.schema.json` are rendered, and each is
     escaped as ADR-0009 decision 3 escapes everything the project wrote.
   - The token is a masked CI variable passed to that job alone. The verdict
     job never has it, and the runners withhold it everywhere else.
   - This is ADR-0019 decision 4's relay pattern, serving users' CIs too.
     This amends ADR-0019 decision 4.
   - The token for GitLab is a project access token with the scope `api` and
     the lowest role that can post merge request notes. The build's first
     step proves which role that is, starting with Reporter. For Bitbucket it
     is a repository access token with `pullrequest:write` only.

8. **Each platform's own surface stands for GitHub's line annotations.**
   - **GitLab:** the `gitlab` Code Quality report (ADR-0016 decision 5), in the
     merge request widget on every tier and on the diff on Ultimate. No line
     notes are posted.
   - **Bitbucket:** a Code Insights report, `mutation-gate`, of type `BUG`,
     `FAILED` when the verdict fails. It carries one annotation per mutant
     counted as not killed, at most 1,000, ranked as GitHub's annotations
     are (ADR-0009 decision 3). The verdict job posts it through the
     pipeline's credential-free proxy, which holds no token to leak.

9. **The comment has its planned state here too.** The pipeline runs the
   `mutation-comment` job after `plan`, where it reads the plan's JSON and
   posts the planned state (ADR-0019 decision 11). It runs again after the
   verdict, where it posts the judged state.

10. **Mentions, forks and config.**
    - An `@` in anything the project wrote is escaped: GitLab's `@` and
      Bitbucket's `@{…}`. Code owners' mentions follow ADR-0022. GitLab's
      CODEOWNERS sections (`[Section]`, `^[Optional]`, a section's default
      owners) are parsed on GitLab.
    - A fork's pipeline runs in the fork on GitLab, and not at all on
      Bitbucket, with none of the parent's variables. No comment is posted,
      and the job says why, as GitHub's reporter does (ADR-0009 decision 3).
    - `reports` gains `gitlab-comment` and `bitbucket-comment`, each with
      `with.tokenEnv` (`MUTATION_GATE_GITLAB_TOKEN` and
      `MUTATION_GATE_BITBUCKET_TOKEN` by default), and `bitbucket-insights`.
      A literal token in the config is refused, as a webhook URL is
      (ADR-0016 decision 11). They report only.

    This amends ADR-0009 decisions 1 and 3, and ADR-0016 decision 11.

### Composer and hook managers

11. **`composer mutate` comes from an optional plugin package,
    `nightworksio/mutation-gate-composer`.**
    - It sets `extra.plugin-optional: true`, so a non-interactive install
      without an `allow-plugins` entry skips it quietly instead of failing.
    - `composer mutate [args]` runs `vendor/bin/mutation-gate [args]` with no
      time limit.
    - `init` asks whether to add it, and prints, as the alternative with no
      plugin, the root script `"mutate": ["Composer\\Config::disableProcessTimeout",
      "mutation-gate"]`.
    - The gate itself stays a plain library that needs no `allow-plugins`
      entry (ADR-0001, alternatives).
    - A Composer plugin is installed only as a package of its own, so it
      cannot ship inside the root package. Its code lives in
      `plugins/composer/`, under the boundary rules ADR-0021 sets for
      first-party plugins, and the release workflow publishes that directory
      to a read-only repository of its own by a subtree split.

12. **Hook managers call the CLI, and the pre-commit framework reads one file
    the repository carries.**
    - **CaptainHook:** a `pre-push` action `vendor/bin/mutation-gate pre-push
      --stdin={$STDIN}`, and a `pre-commit` action `vendor/bin/mutation-gate
      pre-commit`.
    - **GrumPHP:** a `shell` task running `vendor/bin/mutation-gate
      pre-commit`, which never blocks (ADR-0015 decision 10). GrumPHP has no
      `pre-push`, and the README says so.
    - **pre-commit:** the repository carries `.pre-commit-hooks.yaml`, with
      the hooks `mutation-gate-pre-push` (`stages: [pre-push]`, `language:
      unsupported`, `entry: vendor/bin/mutation-gate pre-push`,
      `pass_filenames: false`, `always_run: true`) and
      `mutation-gate-pre-commit`.
    - No code of the gate depends on any hook manager's classes.
    - `init --hook=captainhook|grumphp|pre-commit` prints the block to add,
      and writes a file only where none exists (ADR-0010 decision 3).

    This amends ADR-0010 decision 3 and ADR-0017 decision 1.

13. **`pre-push` reads its refs from three places.** Git's hook input on stdin
    (ADR-0010 decision 2), `--stdin=<text>` for CaptainHook's placeholder,
    and, where there is neither, `PRE_COMMIT_FROM_REF` and
    `PRE_COMMIT_TO_REF`. Each is parsed into the same refs. Each line must
    be a local ref, a local SHA, a remote ref and a remote SHA, the SHAs in
    hex, and anything else is refused. The `.pre-commit-hooks.yaml` hook ids
    are public API. This amends ADR-0010 decision 2 and ADR-0011 decision 7.

### An organisation's view

14. **`mutation-gate dashboard` builds a static site on a schedule.**
    - `mutation-gate dashboard --org=<org> --out=<dir>` reads each
      repository's `mutation-gate` data branch through GitHub's API with a
      read-only token, and writes one self-contained HTML page and a
      `dashboard.json`.
    - A workflow in an organisation repository runs it daily and on
      `workflow_dispatch`, and deploys `<dir>` with `actions/deploy-pages`.
      It runs on those two triggers only, and runs no pull request content.
    - The repositories are those with the topic `dashboard.topic` (a
      string, `mutation-gate` by default), or the list
      `dashboard.repositories`. Both report only.
    - The page loads nothing at view time. It shows, per repository, the
      verdict, the score, the trees below their floors and the time saved in
      the last 30 days, with sparklines drawn as SVG by the trend renderer
      (ADR-0009 decision 5), in tables that sort.
    - The command lives in `Cli`, reads through `Adapter\GitHub`, and
      aggregates in `Core`.

15. **Each repository publishes `summary.json` for it.** The default branch's
    verdict writes it beside `badge.json`, `trend.json`, `trend.svg` and
    `savings.json`, and the publish job publishes it with them:

    ```json
    { "format": 1, "commit": "3f9a1c2…", "time": "2026-09-30T10:00:00Z", "verdict": "passed", "score": 87.41, "trees": [ { "path": "src", "floor": 83.41, "score": 87.41 } ], "newCode": { "floor": 100 }, "savings": { "last30Days": 147600 } }
    ```

    `savings.last30Days` is in seconds. Its format is public API. This amends
    ADR-0009 decision 5 and ADR-0011 decision 7.

16. **A public site never shows a private repository.** Private and internal
    repositories are left out unless `--include-private` is given. Then
    `dashboard` refuses to render unless the Pages site is private, which it
    reads from the Pages API. The token reads contents only. This amends
    ADR-0011 decision 8.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **A fixed `--shards=<N>` on all three CIs** | Azure and Jenkins can fan out at runtime, and a fixed count holds N runners for a one-file change and cannot shrink to none. |
| **Each CI's REST API for the default branch** | Needs a token in every job to learn one name that `ci.defaultBranch` gives. |
| **The directory store in each CI's cache everywhere, with a warning** | On Bitbucket any branch could plant proofs the default branch trusts. |
| **The verdict job posting the comment, as on GitHub** | Any merge request author who can push a branch could print a long-lived token from a test. |
| **A discussion per survivor line on GitLab** | Visible on every tier, and one notification per survivor per push, which ADR-0009 rejected for GitHub. |
| **The judged state only, with no planned state** | Loses the early signal the planned state exists for. |
| **A root script only, with no plugin** | The same command with nothing to allow, and not the plugin the feature is. It stays the printed alternative. |
| **The plugin inside the main package** | Every consumer would have to allow it, the cost ADR-0001 rejected for discovery. |
| **The plugin in a separate repository from the start** | A second repository with its own CI, pins and release, against ADR-0011's one repository. |
| **A CaptainHook action class and a GrumPHP extension task** | Ties the gate to each tool's PHP API, and GrumPHP's extensions cannot load PHP from its scoped PHAR. |
| **A page that fetches every repository's files in the browser** | Public repositories only, and it rests on a cross-origin header nobody documents. |
| **Each repository pushing into the dashboard repository** | Every repository would hold a token that can write another repository. |
| **The dashboard reading the baseline and `trend.json` itself** | Two reads per repository, and the floors of trees that are gone. |
| **Every repository the token can read** | One token with too much reach would publish private names and scores. |

## Consequences

**The gate plans and judges on seven CIs,** with each template honest about
where its trust boundary is.

**Merge requests and pull requests get the gate's comment on GitLab and
Bitbucket** without a long-lived token ever sharing a process with the code
under review.

**Hooks and Composer reach the gate from whatever a project already uses,**
and the CLI stays the one interface.

**An organisation sees every repository's verdict, floors and savings on one
page** that needs no server, and that shows a private repository only on a
private site.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-nine-ports.md): discovery with no Composer plugin
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): what the runners withhold
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): the CI definitions that reach everything
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the CiPlan port and its tables
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the key's CI definitions, and where each CI's trust boundary is
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the sticky comment, annotations and the published files
- [ADR-0010](0010-the-gate-runs-while-you-work-and-before-you-push.md): the pre-push hook and its input
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): public API, and the one repository
- [ADR-0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): the CI templates, and the pre-commit hook
- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): the `gitlab` report, and credentials only from the environment
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): `init`'s questions, and the savings the dashboard shows
- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): the relay pattern, and the planned state
- [ADR-0021](0021-mutators-are-written-once-and-first-party-sets-can-leave.md): the boundary rules for first-party plugins
- [ADR-0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md): code owners' mentions, and the PHAR and image the comment job runs
