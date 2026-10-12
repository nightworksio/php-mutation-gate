# ADR-0028: Proofs can live in Google Cloud Storage or Azure Blob under OIDC bound to an environment or a workflow, and survivors reach SonarQube

**Status:** Accepted
**Date:** 2026-09-30

## Context

Two needs remain at the edges of the gate: where its proofs live, and one more
place its survivors are read.

- **Proof stores.** The ledger lives in a directory, in the GitHub Actions
  cache over that directory, or in S3 and S3-compatible stores (ADR-0007
  decision 4). Teams on Google Cloud or Azure keep their CI's state there.
  The rules a store must keep are settled:
  - a run reads its own scope and the default branch's, and writes only its
    own (ADR-0007 decision 4);
  - only the verdict job of the default branch holds what can write the
    default branch's scope, and an OIDC identity that can write it trusts a
    GitHub environment restricted to the default branch, or the verdict
    workflow's `job_workflow_ref`, never a bare `ref` (ADR-0007 decision 5,
    ADR-0019 decision 16);
  - a run without credentials, such as a fork's, reads the default branch's
    ledger from a public URL and writes nothing (ADR-0013 decisions 13 to
    15).
- **SonarQube.** Teams that gate on SonarQube or SonarQube Cloud want
  survivors in the same issue list as everything else.

What the clouds offer, read in their documentation:

| | Google Cloud Storage | Azure Blob Storage |
|---|---|---|
| Read and write over HTTP | `GET` and `PUT https://storage.googleapis.com/<bucket>/<object>` with a bearer token | `GET` and `PUT https://<account>.blob.core.windows.net/<container>/<blob>`, with `x-ms-blob-type: BlockBlob` and `x-ms-version`, and a bearer token for `https://storage.azure.com/.default` |
| PHP library | `google/cloud-storage` brings `google/cloud-core`, `google/auth`, Guzzle and `google/gax`, which brings gRPC and protobuf | `azure-storage-php` is retired: its community support ended on 17 March 2024 |
| OIDC from GitHub | Workload Identity Federation: GitHub's token is exchanged at `sts.googleapis.com/v1/token`, and optionally for a service account's token through `iamcredentials`' `generateAccessToken`. `google-github-actions/auth` writes an `external_account` credentials file. An attribute condition is required, and may test any claim of GitHub's token | A federated identity credential on an app registration or a user-assigned managed identity. GitHub's token, requested for the audience `api://AzureADTokenExchange`, is exchanged at `login.microsoftonline.com/<tenant>/oauth2/v2.0/token` as a client assertion. A standard credential matches the token's `sub` exactly, such as `…:environment:<name>`. `job_workflow_ref` can be matched only by a flexible credential, which is in preview, or by a `sub` that GitHub is configured to include it in |
| Public read of one prefix | A managed folder at `<prefix>/refs/heads/<default>/`, with `allUsers` as Storage Object Viewer. It needs uniform bucket-level access, and public access prevention off. IAM conditions cannot be used with `allUsers` | None below a container: anonymous read is set per container, at the `Blob` level, and the account's `AllowBlobPublicAccess` must allow it |
| A conditional write | `ifGenerationMatch`, answered by 412 on a mismatch | `If-Match: <ETag>`, answered by 412 on a mismatch |

GitHub's default `sub` claim carries the owner's and the repository's ids for
repositories created after 2026-07-15, as
`repo:octo-org@123456/octo-repo@456789:…`. A credential that matches `sub`
exactly has to use the form the repository issues.

What SonarQube documents:

- **The generic external-issues format**, read from
  `sonar.externalIssuesReportPaths`, holds `rules` and `issues`. SonarQube
  Server changed it in 10.3 and deprecated the older one. From 10.8 only the
  newer format maps an issue's quality correctly, and SonarQube Cloud requires
  its `impacts`.
- **A rule** has `id`, `name`, `description` and `engineId`, and optionally
  a `cleanCodeAttribute` and `impacts: [{softwareQuality, severity}]`.
- **An issue** has a `ruleId`, an optional `effortMinutes`, and a
  `primaryLocation` with `message`, `filePath` and a `textRange` of
  `startLine`, and optionally `endLine`, `startColumn` and `endColumn`.
- **External issues count toward the quality gate**, and each can be
  accepted or marked a false positive.
- **An issue on a file the scanner did not index is dropped**, and the 10.7
  scanner's source only logs how many.
- **SARIF import** turns every issue into a CONVENTIONAL, SECURITY issue,
  whatever it reports.

## Decision

### Two more proof stores

1. **`gcs` and `azure` are `ProofStore` adapters over plain HTTP.**
   `Adapter\Gcs\BucketLedger` and `Adapter\Azure\ContainerLedger` read and
   write one object per scope, `<prefix>/<scope>/ledger.json.gz`, through
   `symfony/http-client`, which the gate already requires, as the `otlp`
   reporter does (ADR-0016 decision 14). Each is a get, a put and the token
   exchange of decision 2. Nothing is added to `require` or `suggest`.
   - **`gcs`** takes `with.bucket` (required), `with.prefix` (`mutation-gate`
     by default) and `with.publicUrl` (none by default).
   - **`azure`** takes `with.account` and `with.container` (both required),
     `with.prefix` (`mutation-gate`), `with.publicContainer` (none) and
     `with.publicUrl` (none).
   - Every option sits under `proofs`, which the proof key leaves out
     (ADR-0007 decision 2.3).
   - This amends ADR-0007 decision 4, whose table gains the two backends.
   - **As built.** `Core\Http\Exchange` is the HTTP edge both stores and
     `PublicLedger` share, and `Adapter\Http\HttpExchange` implements it
     over `symfony/http-client`. It follows no redirect, gives every request
     ADR-0013 decision 13's 60 seconds, and reads a ledger within its byte
     limits. `Core\Proof\ObjectLedger` holds the read and the write a store
     makes of one object per scope, and each store is an
     `Core\Proof\ObjectStore`: where the object is, and the requests that
     read and write it. `gcs` uses Cloud Storage's XML API at
     `https://storage.googleapis.com/<bucket>/<object>`. `azure` sends
     `x-ms-version: 2024-11-04`, and `x-ms-blob-type: BlockBlob` with a
     write.

2. **A store's token comes from OIDC federation, and never from a key.**
   - **GCS** reads the `external_account` credentials file that
     `GOOGLE_APPLICATION_CREDENTIALS` names, as `google-github-actions/auth`
     writes it, makes the STS exchange, and impersonates a service account
     where the file says to. Only the `file` and `url` credential sources are
     read, which are what CI identity providers write. On GitHub Actions it
     builds the same account itself from `MUTATION_GATE_GCS_PROVIDER` and
     `MUTATION_GATE_GCS_SERVICE_ACCOUNT`, with GitHub's
     `ACTIONS_ID_TOKEN_REQUEST_URL`, so the jobs that hold the store's
     identity run no third-party action.
   - **Azure** requests GitHub's token from `ACTIONS_ID_TOKEN_REQUEST_URL`
     for the audience `api://AzureADTokenExchange`, and exchanges it with the
     tenant and client that `AZURE_TENANT_ID` and `AZURE_CLIENT_ID` name, the
     variables `azure/login` uses.
   - **Other CIs**, with a federation of their own, hand the gate a ready
     bearer token in `MUTATION_GATE_GCS_TOKEN` or `MUTATION_GATE_AZURE_TOKEN`.
   - **A key is refused.** A credentials file holding a service-account key
     is exit 2, and the message names the federation to use instead. The
     `azure` store has no option for a storage-account key or a shared access
     signature, so a config that gives one is an unknown key (ADR-0002
     decision 6). A long-lived secret that can write the default branch's
     ledger never sits in CI.
   - A store with no token opens read-only, as ADR-0013 decision 14 decides
     for any store.
   - **As built.**
     - The `gcs` store asks STS for the scope `devstorage.read_write`, or for
       `cloud-platform` where it then impersonates, and asks
       `generateAccessToken` for `devstorage.read_write`. The file's
       `token_url` has to be at `https://sts.googleapis.com`, its
       `service_account_impersonation_url` at
       `https://iamcredentials.googleapis.com`, and a `url` source has to be
       `https://`, so the CI's token is sent nowhere else. The file is read
       and checked even where `MUTATION_GATE_GCS_TOKEN` is set.
     - From the provider and service account, the `gcs` store asks GitHub
       for its token for the provider's default audience,
       `https://iam.googleapis.com/<provider>`, over `https://` alone,
       exchanges it at `https://sts.googleapis.com/v1/token` for the
       audience `//iam.googleapis.com/<provider>`, and impersonates the
       service account at `https://iamcredentials.googleapis.com`. Neither
       host is ever read from the environment. A provider that is not
       `projects/<number>/locations/global/workloadIdentityPools/<pool>/providers/<id>`,
       a service account that is not `<name>@<project>.iam.gserviceaccount.com`,
       or a job GitHub hands no OIDC token, is refused, ready token or not.
       The provider goes before a credentials file where both are set.
     - The `azure` store asks Entra ID for the scope
       `https://storage.azure.com/.default`, with GitHub's token as a
       `jwt-bearer` client assertion.
     - Each store asks for its token once per run, and only when it reads or
       writes with it.
     - A service-account key is refused as *GOOGLE_APPLICATION_CREDENTIALS
       names a service-account key, which the gcs store refuses: use Workload
       Identity Federation instead.*, and any other type of credentials as
       *GOOGLE_APPLICATION_CREDENTIALS names "<type>" credentials; the gcs
       store reads only an external_account file, from federation.*

3. **The identity that can write the default branch's scope is bound to an
   environment, or to the verdict's workflow, never to a bare `ref`.**
   - **The primary binding** is a GitHub environment whose deployment-branch
     policy allows only the default branch, used only by the verdict job. On
     Azure it is a standard federated credential whose subject is
     `repo:<owner>/<repo>:environment:<name>`, in the form the repository
     issues. On GCS it is an attribute condition on `assertion.environment`
     together with `assertion.repository_id`, with the store's role granted
     to that `principalSet` on the bucket.
   - **The alternative binding** pins the verdict's workflow: on GCS a
     condition on `assertion.job_workflow_ref`, and on Azure a `sub` that
     GitHub is configured to include `job_workflow_ref` in, or a flexible
     federated credential.
   - A binding on a bare `ref` is refused in the README, as ADR-0019
     decision 16 refuses it for S3.
   - The README gives each cloud's setup commands. The first step of the
     build proves them against a test project in each cloud, including GCS's
     conditions on `environment` and `job_workflow_ref`, which Google's
     examples do not show. If one does not hold, the README documents only
     the bindings that do.
   - This amends ADR-0007 decision 5 and ADR-0019 decision 16, which gain
     the two clouds.
   - **As built.** The test projects were not set up, so the bindings the
     README gives are proven against the clouds' documentation alone.

4. **A fork reads the default branch's ledger from a public URL, and each
   cloud makes exactly that scope public its own way.**
   - **GCS:** `publicUrl` is `https://storage.googleapis.com/<bucket>`, over a
     managed folder `<prefix>/refs/heads/<default>/` that `allUsers` may
     read. That is what ADR-0013 decision 13's bucket policy is on S3.
   - **Azure:** a second container, `with.publicContainer`, at the `Blob`
     access level. The store writes the default branch's scope there, and
     every other scope to the private container, and `publicUrl` points at the
     public one. `doctor` reports an account whose `AllowBlobPublicAccess` is
     off.
   - A run without credentials asks for no other scope: it reads as an
     empty ledger, which costs a run, never a verdict (ADR-0007 decision 3,
     ADR-0013 decision 13).
   - This amends ADR-0013 decision 13.
   - **As built.** `doctor --online` asks the public container's URL for
     `?restype=container` with no service version, as a fork's run asks,
     and an account whose `AllowBlobPublicAccess` is off answers 409. It
     reports that as *advice*, under the slug `anonymous-reads-refused`.

5. **The last write wins, as on every store.** Two verdicts writing one scope
   at the same time keep the later ledger (ADR-0007 decision 4). Both clouds
   offer conditional writes, and so moving every store to them is a decision
   for all stores at once, not for two.

6. **What the stores' credentials never reach, and where the workflow asks
   for them.**
   - `GOOGLE_APPLICATION_CREDENTIALS`, `MUTATION_GATE_GCS_TOKEN`, `AZURE_*`
     and `MUTATION_GATE_AZURE_TOKEN` join what every run withholds from the
     runners, beside `AWS_*` and `ACTIONS_*` (ADR-0004 decisions 3 and 4).
   - The reusable workflow reads each cloud's identity from the
     repository's variables, `AZURE_TENANT_ID` and `AZURE_CLIENT_ID`, and
     `MUTATION_GATE_GCS_PROVIDER` and `MUTATION_GATE_GCS_SERVICE_ACCOUNT`.
     It asks for `id-token: write` only in `fetch`, which reads, and
     `deliver`, which writes, the two jobs that run none of the project's
     code, as it does for an S3 store behind an OIDC role. A project that
     runs the action grants it only to the job that runs it with
     `deliver: true`. This amends ADR-0011 decision 8.
   - **As built.** Every run also withholds `GOOGLE_GHA_CREDS_PATH` and
     `CLOUDSDK_AUTH_CREDENTIAL_FILE_OVERRIDE`, which
     `google-github-actions/auth` sets to the same credentials file.

### A SonarQube reporter

7. **`sonar` writes the generic external-issues format.** It is a built-in
   file reporter (ADR-0009 decision 1) with a `path`, such as `{"use":
   "sonar", "path": "build/mutation-sonar.json"}`, and it reports only. The
   README gives the line to add to the scanner's properties:
   `sonar.externalIssuesReportPaths=build/mutation-sonar.json`. SonarQube
   Server before 10.3 is not served. This amends ADR-0009 decision 1.

8. **Its rules are SARIF's, with the quality each affects.**
   - Five rules, each with `engineId: "mutation-gate"` and
     `cleanCodeAttribute: TESTED`: `survived`, `uncovered`, `unjudged` and
     `flaky`, whose `impacts` name `RELIABILITY`, and `survived-security`,
     whose `impacts` name `SECURITY`, for a surviving mutant a
     security-tagged mutator made (ADR-0021).
   - The names and descriptions are fixed text in this package, and each
     description links the gate's troubleshooting page for that judgement
     (ADR-0018 decision 8).
   - **As built.** `Core\Report\SonarRule` holds the five rules, their
     names (*Surviving mutant*, *Uncovered mutant*, *Unjudged mutant*,
     *Flaky mutant* and *Surviving security mutant*), their descriptions and
     the quality each affects. A description is SARIF's text for the rule,
     then the section of the guide of the release that wrote it:
     `survived`, `uncovered`, `unjudged` or `flaky`, and `survived` for
     `survived-security`. A surviving security mutant, one its package's
     security set holds (ADR-0021 decision 16), is raised under
     `survived-security`, and every other issue under the rule SARIF reports
     it by.

9. **Each issue is a mutant the score counts as not killed.**
   - The mutants are exactly those SARIF reports (ADR-0009 decision 2). A
     mutant proven equivalent, or killed by static analysis (ADR-0020), is
     none.
   - Its location is the mutant's file, repository-relative, and its lines,
     with columns from the file's tokens (ADR-0009 decision 4).
   - Its message is the hint, the mutator, the gate's id and the reproduce
     command.
   - Its severity is its rule's, and every rule's impact is `MEDIUM`,
     whatever the mutant's set did. SonarQube reads a severity for each
     rule, in its `impacts`, and never for each issue: its scanner refuses
     an issue that carries a `severity` of its own beside the rules. One
     severity for every rule keeps an issue on its rule, with what SonarQube
     has accepted of it, when its set crosses its floor.
   - `effortMinutes` is left out, so Sonar's default of 0 applies.
   - **As built.** SonarQube counts columns from 0 and ends a range before
     the column it names. A mutant whose change its tokens do not show on its
     lines is placed on its lines alone, which SonarQube marks from the start
     of its first line to the end.

10. **Files Sonar would silently drop are named first.** `doctor` compares the
    trees with `sonar.sources` in `sonar-project.properties`, where there is
    one, and reports a tree outside it as *advice*: *Sonar drops the
    survivors in `app/Legacy`, which is outside `sonar.sources`*. The reporter writes, beside its file, how many
    issues it wrote under each top directory. This amends ADR-0017 decision
    10's table.
    - **As built.** The check's slug is `outside-sonar-sources`. It runs
      where a `sonar` report is listed and `sonar-project.properties` sets
      `sonar.sources`, which is read as a Java properties file. The count
      is said on the line that says where the report was written, as
      *Wrote build/mutation-sonar.json. Issues by top directory: 12 under
      src, 3 under app.*, with `.` for files at the root.

11. **The package imports its own.** The package's SonarCloud analysis reads
    its own `sonar` report, so its gate of no open issue (ADR-0011
    decision 3) also holds its survivors.
    - **As built.** The package's CI does not run its own gate, so its
      SonarCloud analysis has no `sonar` report to read.

12. **Where it lives in the code.** `Core\Report` renders the report and
    `Adapter\Filesystem` writes it, as every file report is written. A test
    checks the output against the fields Sonar's documentation lists.
    - **As built.** `Adapter\Filesystem\SonarReportFile` writes it, with
      the mutated files read through `Adapter\Filesystem\MutatedFiles`, as
      the HTML report reads them.
      `tests/Fixtures/sonar-generic-issues.schema.json` is a JSON Schema of
      the fields the documentation lists, and every verdict's report is
      checked against it.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **The official SDKs, in `suggest`** | Google's library brings a dependency tree larger than the gate, gRPC among it. Azure's is retired. |
| **Accepting key files, account keys and shared access signatures** | Works everywhere at once, and puts a long-lived secret that can write the default branch's ledger in CI, which OIDC federation exists to remove. |
| **Binding the writing identity to `ref: refs/heads/<default>`** | Every job that runs on the default branch carries that `ref`, including those `issue_comment` and `workflow_run` start (ADR-0019). |
| **Signed URLs for forks**, made by the default branch's run | No public bucket. A GCS signed URL lives seven days at most, and the URL itself has to reach every fork. |
| **No public read for these two stores** | Simpler. A fork's pull request re-mutates everything it cannot carry. |
| **Conditional writes on the two new stores only** | No lost proofs there. Two stores would behave unlike the other three. |
| **Pointing SonarQube at the SARIF report** | Nothing to build. Sonar would turn every survivor into a security vulnerability. |
| **The deprecated external-issues format beside the current one** | Serves SonarQube Server before 10.3, with a deprecated format to keep. |
| **One Sonar rule per mutator family** | Richer filtering in Sonar. Eleven rules to keep in step with ADR-0009's families, where the gate's own four judgements already say what matters. |
| **A rule for each severity: `HIGH` in a set that failed, `LOW` otherwise** | Severity that follows the floor. An issue would move to another rule each time its set crossed its floor, and lose what SonarQube had accepted of it. |
| **A fixed effort per survivor** | Survivors would show as technical debt, measured in minutes nobody measured. |

## Consequences

**Proofs can live in all three major clouds**, with the same scope rule and the
same trust boundary, and with no long-lived secret in CI.

**Forks read the default branch's proofs on every store** that has a public
URL, and plant nothing.

**Survivors reach SonarQube as reliability issues**, and untested security
defences as security issues, counted by its quality gate like any other
issue.

## Related

- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): what every run withholds from the runners
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): the ProofStore port, scopes and their trust
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): reporters, SARIF's rules and columns
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the reusable workflow, and the package's SonarCloud gate
- [ADR-0013](0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md): public read for runs without credentials
- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): a reporter built over `symfony/http-client`
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): `doctor`'s checks
- [ADR-0018](0018-the-documentation-is-versioned-and-tested-with-the-code.md): troubleshooting slugs
- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): OIDC identities that never trust a bare `ref`
- [ADR-0020](0020-a-change-lists-its-tests-an-analyser-can-kill-and-a-huge-repository-can-be-sampled.md): mutants killed by static analysis
- [ADR-0021](0021-mutators-are-written-once-and-first-party-sets-can-leave.md): security-tagged mutators
