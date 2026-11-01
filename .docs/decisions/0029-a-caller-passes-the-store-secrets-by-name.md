# ADR-0029: A caller passes the reusable workflow its store's secrets by name, and their values stay in the environments

**Status:** Accepted
**Date:** 2026-10-20
**Supersedes:** [0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): its secrets "never from the caller"

## Context

ADR-0011 says the reusable workflow's secrets "come from two environments,
never from the caller". The jobs that read them, `fetch` and `deliver`, enter
`mutation-gate-read` and `mutation-gate-store`, and no caller passes a secret.

That does not work. In run 39378485071 (2026-10-20), `fetch` entered
`mutation-gate-read`, which holds `MUTATION_GATE_READ_AWS_ACCESS_KEY_ID` and
`MUTATION_GATE_READ_AWS_SECRET_ACCESS_KEY`, yet both read empty. It logged
"The default branch's ledger is not fetched: MUTATION_GATE_STORE names s3,
whose credentials this job does not hold". `deliver` on `main` read its keys
empty the same way, so no ledger was ever written, and every pull request,
every merge and every nightly mutated all 34,035 mutants again.

GitHub resolves an environment's secret in a called workflow only under a
name the caller hands on: by inheriting every secret, or by passing that name
(actions/runner#4453). Where a job of the called workflow enters an
environment, the environment's value wins over the value the caller passed.

## Decision

1. **The reusable workflow declares every secret its jobs read** under
   `on.workflow_call.secrets`, each `required: false`:
   `MUTATION_GATE_READ_AWS_ACCESS_KEY_ID`,
   `MUTATION_GATE_READ_AWS_SECRET_ACCESS_KEY`, `AWS_ACCESS_KEY_ID`,
   `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`, `MUTATION_GATE_SLACK_URL`,
   `MUTATION_GATE_DISCORD_URL`, `MUTATION_GATE_WEBHOOK_URL`,
   `MUTATION_GATE_WEBHOOK_SECRET`, `OTEL_EXPORTER_OTLP_ENDPOINT`,
   `OTEL_EXPORTER_OTLP_HEADERS` and `COMPOSER_AUTH`.
2. **A caller passes each one it uses under its own name**, as
   `NAME: ${{ secrets.NAME }}`, never inheriting them all. The guide, the
   template `init --ci=github` writes, and this repository's own `ci.yml` do
   so.
3. **The values stay in the environments.** Only `fetch` reads the read key,
   in `mutation-gate-read`. Only `deliver` reads the rest, in
   `mutation-gate-store`, on a trusted run (ADR-0007). No job that
   runs the project's code reads any of them, so a shard never holds one,
   though every job of the called workflow sees the names. The one secret
   such a job reads is the install's, under decision 5. The guide keeps saying
   the secrets go in the environments, never in the repository: a repository
   secret of the same name would reach every job's `secrets` context.
4. **`.github/scripts/test_workflows.py` holds it.** Every name the workflow
   reads is declared and optional, and the caller, the guide and the template
   pass each name as itself and nothing undeclared. It and
   `tests/Arch/TheShippedWorkflowsHoldTest.php` hold decision 5 too:
   `COMPOSER_AUTH` reaches the install steps and the shard's action input,
   and nothing else.
5. **One exception: the credentials Composer installs with.** A project whose
   dependencies come from a private repository cannot be installed without
   them, and every job that runs its code installs it. So `COMPOSER_AUTH` is
   declared beside the store's secrets, and the action takes it as the input
   `composer-auth`. Only the step that installs the project's dependencies
   reads it, as that step's `COMPOSER_AUTH`: in `plan`, `survivors` and
   `verdict` the step's own env, in `shard` the action's input, which the
   action hands to its install step alone. No step after the install holds
   it, so the tests, the mutants and the gate's commands run without it.
   What reads it is Composer and the project's own Composer plugins and
   scripts, which the project's own CI hands the same credentials to. A
   caller passes it as a repository secret, since every job installs, and
   only where its install needs one.

This supersedes the bullet of ADR-0011's decision that says the secrets come
"never from the caller". The rest of that bullet stands.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Inheriting every secret** | It hands the called workflow every secret the repository holds, `SONAR_TOKEN` among them, for the sake of four. |
| **Leave the store unset and keep the ledger in the Actions cache** | A pull request then reads no ledger of the default branch, which is the whole saving. |
| **Repository secrets instead of environment secrets** | A repository secret reaches a run on any branch; the write key must reach the default branch alone. |
| **The caller's installed `vendor` handed over as an artifact, so no job of the gate's installs with credentials** | Every shard downloads the whole of `vendor`, and a `vendor` built on the caller's runner holds that runner's architecture and PHP build, which the gate's jobs need not share. |
| **A project with private dependencies writes its own sharded jobs around the action** | Each such project copies the plan, the verdict and the deliver wiring the reusable workflow ships, and keeps it current by hand. |

## Consequences

- A pull request's `fetch` reads the default branch's ledger, and a trusted
  run's `deliver` writes it, so a run mutates only what its change reaches.
- A consumer whose call passes no secrets keeps working as before, without a
  store's ledger. It gains one once it passes the names.
- A project that installs from a private Composer repository runs the
  reusable workflow by passing `COMPOSER_AUTH`. Its plugins and scripts run
  with the credentials during the install, as they do in the project's own
  CI, and nothing after the install does.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): what writes a ledger, and where
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the reusable workflow, whose secrets bullet this supersedes
- [ADR-0016](0016-the-gate-takes-over-from-infection-and-reports-what-a-run-costs.md): the alerts and the trace
