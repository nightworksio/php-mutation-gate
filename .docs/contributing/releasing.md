# Releasing

A release is drafted by the bot and signed by the maintainer, and only a
signed tag publishes one
([`release.yml`](../../.github/workflows/release.yml),
[ADR-0019](../decisions/0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md)).
The workflow never creates a version tag itself.

1. **Drafting.** Running `release.yml` on demand computes the next version
   from the conventional commits since the last tag, or takes the one given,
   writes its `CHANGELOG.md` section with git-cliff, and opens the pull
   request `release/vX.Y.Z` as the App. A dispatch for a version whose branch
   exists is refused, so it never discards edits made there.
2. **Signing.** The maintainer edits and merges that pull request, then signs
   the squash commit's tag, `git tag -s vX.Y.Z`, and pushes it.
3. **Publishing**, on that tag:
   - `verify` checks the tag's signature against `.github/allowed_signers` on
     `main`, and fails closed without it;
   - `gate` runs the package's own gate in full;
   - `build` builds the PHAR, holding no secret;
   - `publish` signs the PHAR with the release key and attests it, once its
     SHA-256 is the one `build` named, and creates the GitHub release with the
     version's changelog section as its notes;
   - `image` pushes `ghcr.io/nightworksio/mutation-gate` with its provenance,
     tagged with the version, its minor and its major;
   - `line` moves the tag of the release line to it: `vMAJOR.MINOR` while the
     major is 0, such as `v0.1`, and `vMAJOR` from 1.0.0.

The release key's fingerprint, and how to verify a release, are in
[security](../../SECURITY.md#verifying-a-release). What a release may change
is in [compatibility](../using/compatibility.md).

## The docs of a release

The docs site keeps a copy of each minor's docs, built from its newest tag, so
a published release adds its copy when `pages.yml` runs again
([the docs](docs.md#versions)).
