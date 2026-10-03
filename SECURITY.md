# Security policy

## Reporting a vulnerability

Report it privately, through GitHub:
[Report a vulnerability](https://github.com/nightworksio/php-mutation-gate/security/advisories/new)
on this repository's *Security* tab. Where you cannot use GitHub, email
<info@nightworks.io>.

Do not open a public issue or pull request for a vulnerability.

## What happens next

- Your report is acknowledged within three working days.
- A fix is published as a GitHub security advisory, with a CVE.

## Supported versions

The latest minor release of the current major version gets security fixes.
Once a new major version is released, the previous major's last minor gets
security fixes for six months.

| Version | Security fixes |
|---------|----------------|
| 0.1.x | yes |

No version is tagged yet. Until 0.1.0 is, a fix lands on `main`, which a
project requiring `dev-main` installs.

## Scope

In scope is what this repository ships:

- the Composer package `nightworksio/mutation-gate`, with its first-party
  plugins under `plugins/`;
- the GitHub Action and the reusable workflow;
- the proof ledger's trust boundaries: a run that may not write the default
  branch's proofs must not be able to plant one that the default branch's run
  trusts
  ([ADR-0007](.docs/decisions/0007-a-proof-is-keyed-by-everything-its-verdict-reads.md),
  decision 5).

A vulnerability in Pest, Infection or another dependency belongs to that
project. Where the gate's use of a dependency is what makes it exploitable,
report it here.
