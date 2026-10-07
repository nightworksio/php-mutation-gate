# Proofs and trust

Each run records what it proved in a proof ledger, keyed by everything its
verdict reads: the code, the tests that judge it, and every setting that
affects results. A later run skips every unit whose key it already holds, so
no run repeats what an earlier one proved. The ledger is kept where
`proofs.store` says: a directory, which CI keeps in its cache, or S3 (and
R2), Google Cloud Storage or Azure Blob Storage
([configuration](../../reference/configuration.md#every-key)).

A ledger is only as trustworthy as whoever may write it. This page says how
to keep a pull request from writing what the default branch then trusts.

## Who may write the ledger

The proof ledger's trust boundary is the store's access control. Withholding a
variable keeps it out of the tests' environment, not out of the project's
reach: a job that runs the project's tests or loads its config hands its code
every secret the job holds. Give write credentials only to the default
branch's runs
([ADR-0007](../../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)).
On GitHub, cache scoping keeps a pull request from writing what the default
branch reads.
On GitLab, separate caches for protected branches do the same, and they also
keep merge requests from reading the default branch's ledger. On Azure DevOps a
pull request build reads the target branch's caches and cannot write them. On
Buildkite and CircleCI a branch's pipeline config picks its cache key,
Bitbucket's caches are shared by every branch, and Jenkins has no cache, so a
cache is no boundary there.
Wherever a pull request must read the default branch's proofs safely, keep the
ledger in S3, Cloud Storage or Azure Blob Storage, with credentials that can
write the default branch's prefix held only by default-branch runs
([ADR-0007](../../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)).
With an AWS OIDC role, make its trust policy match a GitHub environment that
only the default branch may deploy to, or the verdict workflow's
`job_workflow_ref`, never `ref: refs/heads/main` alone: every job a workflow
runs on the default branch carries that `ref`, whatever started it
([ADR-0019](../../decisions/0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md)).

## Cloud Storage and Azure Blob Storage

The `gcs` and `azure` stores take their token from OIDC federation alone, over
plain HTTP, so they need no package beyond the gate. `gcs` exchanges the
external-account file `google-github-actions/auth` writes, and refuses a
service-account key (exit 2). `azure` exchanges GitHub's OIDC token for the
tenant and client `azure/login` reads, and has no option for an account key or
a shared access signature. Bind the identity to a GitHub environment that only
the default branch may deploy to, used only by the verdict job, or to the
verdict workflow's `job_workflow_ref`, never to a bare `ref`. A repository
created after 2026-07-15 issues `sub` as
`repo:<owner>@<owner id>/<repo>@<repo id>:…`, and a credential that matches
`sub` exactly has to use that form
([ADR-0028](../../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md)):

```sh
# Cloud Storage: a provider only the verdict environment's runs pass
gcloud iam workload-identity-pools create github --location=global
gcloud iam workload-identity-pools providers create-oidc github --location=global \
  --workload-identity-pool=github --issuer-uri=https://token.actions.githubusercontent.com \
  --attribute-mapping=google.subject=assertion.sub,attribute.repository_id=assertion.repository_id \
  --attribute-condition="assertion.repository_id == '<repo id>' && assertion.environment == 'mutation-verdict'"
gcloud storage buckets add-iam-policy-binding gs://<bucket> --role=roles/storage.objectUser \
  --member=principalSet://iam.googleapis.com/projects/<project number>/locations/global/workloadIdentityPools/github/attribute.repository_id/<repo id>

# Azure: a federated credential on the verdict environment's subject
az ad app federated-credential create --id <app id> --parameters '{"name": "mutation-verdict",
  "issuer": "https://token.actions.githubusercontent.com", "audiences": ["api://AzureADTokenExchange"],
  "subject": "repo:<owner>/<repo>:environment:mutation-verdict"}'
az role assignment create --assignee <app id> --role "Storage Blob Data Contributor" \
  --scope /subscriptions/<subscription>/resourceGroups/<group>/providers/Microsoft.Storage/storageAccounts/<account>
```

To bind the workflow instead, the Cloud Storage condition tests
`assertion.job_workflow_ref`, and on Azure the `sub` GitHub issues has to
include `job_workflow_ref`, or the credential is a flexible one.

## Pull requests from forks

A fork's pull request runs without credentials. On GitHub's cache it restores
the default branch's ledger read-only, as any pull request does. With S3, set
`proofs.store.with.publicUrl` and give the bucket a policy that allows a public
`GetObject` on `<prefix>/refs/heads/<default branch>/*` and nothing else. A run
without both `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` sends the bucket
no request. It reads the default branch's ledger alone, with an anonymous GET
from `<publicUrl>/<prefix>/refs/heads/<default branch>/ledger.json.gz`, writes
nothing, and says *read-only: no credentials; this run's proofs are not kept*.
A 404 reads as an empty ledger. Any other refusal, a redirect, no answer within
a minute, or a ledger past 11 MB, or past 38 MB decompressed, is not read, and
the verdict warns why. Until the default branch's first run writes its ledger,
S3 answers 403, and the verdict warns of that. Without `publicUrl`, such a run
reads nothing. On Cloud Storage, `publicUrl` is
`https://storage.googleapis.com/<bucket>`, over a managed folder that `allUsers`
may read. On Azure, the store writes the default branch's scope to
`publicContainer`, a container at the `Blob` access level, and every other
scope to `container`, and `publicUrl` is the public container's URL. An account
whose `AllowBlobPublicAccess` is off refuses every anonymous read, and
`doctor --online` reports it ([ADR-0028](../../decisions/0028-proofs-live-in-gcs-or-azure-and-survivors-reach-sonarqube.md)):

```sh
gcloud storage buckets update gs://<bucket> --uniform-bucket-level-access --no-public-access-prevention
gcloud storage managed-folders create gs://<bucket>/mutation-gate/refs/heads/main/
gcloud storage managed-folders add-iam-policy-binding gs://<bucket>/mutation-gate/refs/heads/main/ \
  --member=allUsers --role=roles/storage.objectViewer

az storage account update -n <account> --allow-blob-public-access true
az storage container create -n <public container> --account-name <account> --public-access blob --auth-mode login
```

A fork can plant no proof that another run trusts. It can
influence only its own verdict, which its own workflow file could anyway, so
require approval before outside contributors' workflows run
([ADR-0013](../../decisions/0013-a-run-learns-which-tests-kill-and-how-wide-to-cut.md)).

## Credentials in a job of their own

A job that runs the project's code hands it every secret the job holds. So
the store's keys, the comment's token, the alert URLs and the OpenTelemetry
headers can go to jobs of their own that run none of it. Two commands run in
such jobs, from the gate's own installation, with no config read and no
extension loaded. Each refuses to start through Composer's proxy, or from the
working directory's `vendor`
([ADR-0007](../../decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md)).

- **`deliver`** sends what a run left in `.mutation-gate/delivery`.
  `plan --deliver-later` leaves its comment's planned state in
  `.mutation-gate/delivery/planned`, `survivors --deliver-later` the
  re-checked survivors, which `deliver` writes only over that planned
  state, in `.mutation-gate/delivery/survivors`, and `verdict --deliver-later` its
  ledger, the coverage map it keeps, comment, alerts and export in
  `.mutation-gate/delivery/verdict`;
  each sends nothing that needs a credential and writes no store, and `deliver
  --from=<that directory>` sends it. A delivery directory holds
  `delivery.json`, payloads only: the ledger's scope, the
  comment's markdown, each alert's body (no more to one channel than a
  verdict sends), the OTLP export and the scope each kept object is for.
  Beside it are the ledger, `ledger.json.gz`, and the coverage map,
  `coverage.json.gz`. Each is read within its own byte limits, and a key `deliver` does
  not take, such as a URL, a host or a variable's name, refuses the whole delivery. Every destination and every
  credential comes from `deliver`'s own environment:
  - the comment goes to the pull request its own event names, with
    `GITHUB_TOKEN`;
  - each alert goes to `MUTATION_GATE_SLACK_URL`, `MUTATION_GATE_DISCORD_URL`
    or `MUTATION_GATE_WEBHOOK_URL`, signed with `MUTATION_GATE_WEBHOOK_SECRET`.
    A `with: {urlEnv: …}` or `with: {secretEnv: …}` in the config is not
    read, so set these default variables;
  - the export goes to `OTEL_EXPORTER_OTLP_ENDPOINT` with
    `OTEL_EXPORTER_OTLP_HEADERS`. A `with: {endpoint: …}` is not read;
  - the ledger goes to the store that [the store variables](../../reference/environment.md#the-store-for-deliver-and-fetch) locate. `deliver`
    writes it only on GitHub Actions, only on a push, a schedule or a manual
    run of the default branch, and only where the delivery's scope is that
    branch's. It decides that from its own event and ref before it reads the
    delivery. The default branch is the one the event payload names, else
    `MUTATION_GATE_DEFAULT_BRANCH`. On any other run it writes no ledger and
    fails nothing. The coverage map is kept beside the ledger on the same
    runs, for the same scope.
- **`fetch`** reads the default branch's ledger, and no other scope's, from
  the store that [the store variables](../../reference/environment.md#the-store-for-deliver-and-fetch) locate, with a key that only needs to read.
  It writes the ledger into `.mutation-gate/ledger`, where the `directory`
  store, the default, reads it, so the plan, the shards and the verdict hold
  no credential. It writes nothing to the store. A ledger it cannot read,
  or a job without the key, costs a run, never a verdict: `fetch` says why
  and exits 0, as it does where `MUTATION_GATE_STORE` names no store. A public
  repository can read the default branch's ledger from `publicUrl` instead,
  as a fork's run does.

`deliver` and `fetch` take the store's location and credentials from their
own environment, never from a delivery or a config: the variables are listed
in [environment variables](../../reference/environment.md#the-store-for-deliver-and-fetch).

On GitHub Actions, `gcs` and `azure` federate the job's own OIDC token, so
the jobs that run `deliver` and `fetch` hold no long-lived secret and run no
third-party action:

- `gcs` takes `MUTATION_GATE_GCS_PROVIDER`, the workload identity provider,
  `projects/<number>/locations/global/workloadIdentityPools/<pool>/providers/<id>`,
  and `MUTATION_GATE_GCS_SERVICE_ACCOUNT`, the service account it
  impersonates, `<name>@<project>.iam.gserviceaccount.com`. It asks GitHub for
  the token for the provider's default audience,
  `https://iam.googleapis.com/<provider>`, and sends it only to
  `sts.googleapis.com` and `iamcredentials.googleapis.com`.
- `azure` takes `AZURE_TENANT_ID` and `AZURE_CLIENT_ID`, as `azure/login`
  does.
- Each job that holds them needs `permissions: id-token: write`. A reusable
  workflow can grant it to a job only where the calling workflow grants it
  too, so the caller's own `permissions` holds `id-token: write`. Without it,
  GitHub hands the job no token, and the store says so.
