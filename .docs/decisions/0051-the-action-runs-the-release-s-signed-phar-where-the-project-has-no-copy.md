# ADR-0051: The action runs the release's signed PHAR where the project has no copy of the gate

**Status:** Accepted
**Date:** 2026-11-01
**Amends:** [0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): its action that stops where the project installs no gate of its line

## Context

ADR-0011 has the action check that the project's installed gate is of the
action's release line, and stop if not. A project that does not require the
gate with Composer cannot use the action at all. That includes a project whose
constraints conflict with the gate's, such as one on a PHPUnit the gate's
`conflict` excludes.

ADR-0022 ships a PHAR beside the Composer package. Each release publishes it
with a detached GPG signature by the release key, and an attestation of the
release workflow. The PHAR runs every runner but Pest, whose plugin only the
Composer package installs.

The action's steps that run after the project's dependencies install hold no
token, since the project's Composer scripts and plugins run in that install
(ADR-0007). Listing a release's assets and checking its attestation take a
token.

GitHub serves the action at the ref the calling workflow pins. The guide pins
it to a full commit, with the tag in a comment. GitHub names that ref in
`GITHUB_ACTION_REF`, and fetches the action into a directory named after it.

## Decision

1. **The action takes `install`: `auto`, `composer` or `phar`.** It defaults
   to `auto`, which runs the project's copy where its `composer.json`
   requires `nightworksio/mutation-gate` in `require` or `require-dev`, or
   is the gate, and the PHAR where it does not. `composer` runs the project's
   copy, held to the action's line as ADR-0011 says. `phar` runs the PHAR.
2. **The PHAR is the release the action's ref names.** That ref is a release's
   tag (`vX.Y.Z`), a release line's tag (`v0.1`), or a commit. The action lists
   `nightworksio/php-mutation-gate`'s tags with `git ls-remote`, and takes the
   highest release tag at the commit the ref names. A ref that names no
   release's commit refuses, saying to pin a release or to require the gate
   with Composer.
3. **The PHAR runs only once both its proofs hold.** Before the project's
   dependencies install, the action downloads the release's
   `mutation-gate.phar` and `mutation-gate.phar.asc` with the job's token. It
   checks:
   - that gpg reports a good signature, not by an expired key, by the release
     key whose fingerprint the action pins, as README.md and SECURITY.md name
     it. The public key ships beside the action's script;
   - that `gh attestation verify` finds an attestation of `release.yml` in
     `nightworksio/php-mutation-gate`, for the release's tag.

   Where either fails, the step refuses and nothing runs. Every gate command
   of the run then runs through the PHAR.
4. **The Pest runner refuses under the PHAR.** It refuses once the effective
   config is read and before anything runs, saying to require the gate with
   Composer.
5. **The reusable workflow keeps the project's copy.** Its plan and verdict
   run the gate's CLI after the setup the action has, so it still needs the
   gate installed with Composer, and its shard jobs run the action with
   `install: composer`, so every job of a run runs one copy.

This amends ADR-0011's action, which stops where the project installs no
gate of its line: that holds for `install: composer` alone.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **Only a release tag as the ref** | The guide pins the action to a commit, so the PHAR would be refused wherever the guide is followed. |
| **Download the PHAR from the release's public URL, with no token** | `gh attestation verify` takes a token anyway, and the step runs before the project installs, where a token is held. |
| **Install the gate with `composer global require`** | It loads the gate's packages unscoped beside the project's own, which the scoped PHAR avoids (ADR-0022), and runs Composer plugins. |
| **Run the image** | A container step runs the project's tests inside the gate's image, which lacks the extensions the project's suite needs. |

## Consequences

- A project on a PHPUnit or a PHP the gate's Composer constraints exclude
  runs the action, as long as its runner is not Pest.
- A release's PHAR, its signature and its attestation are what the action
  runs, so a release missing any of them refuses under `phar`.
- A ref on a branch, or on a commit no release tag points at, refuses under
  `phar`, and runs under `composer` as before.

## Related

- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): no step after the project's install holds a token
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the action, which this amends
- [ADR-0022](0022-survivors-reach-their-owners-and-a-merge-queue-trusts-no-pull-requests-own-proofs.md): the PHAR, its signature and its attestation
