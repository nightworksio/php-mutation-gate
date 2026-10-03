# ADR-0022: Survivors reach their owners and are clustered by cause, a merge queue trusts no pull request's own proofs, and the gate also ships as a signed PHAR and image

**Status:** Accepted
**Date:** 2026-09-30

## Context

Four needs remain once a verdict is right and fast. Each is about who the
verdict reaches and how.

- **Survivors have owners.** A pull request's comment lists survivors
  (ADR-0009 decision 3), but not who is asked to act on them. GitHub's
  CODEOWNERS already says who owns each path. As GitHub documents it:
  - the file is read from `.github/`, the root or `docs/`, the first found,
    and it must be on the pull request's base branch;
  - its patterns follow most of `.gitignore`'s rules, but not `!`
    negation, `[ ]` ranges or an escaped leading `#`;
  - the **last matching pattern wins**, and paths are case-sensitive;
  - owners are `@user`, `@org/team` or an email address, and a pattern with
    no owner leaves the path unowned;
  - an invalid line is skipped, and a file over 3 MB is not loaded.
- **Merge queues build what nobody has judged.** In GitHub's merge queue a
  workflow must run on `merge_group` (activity `checks_requested`) for a
  required check to be reported, and the queue waits for it.
  - `GITHUB_SHA` is the group's commit, and `GITHUB_REF` its ref, a branch
    under `gh-readonly-queue/<base>/`.
  - The payload carries `merge_group.head_sha`, `head_ref`, `base_sha`,
    `base_ref` and `head_commit`, as GitHub's webhook schema gives them.
  - A group holds the base branch, the pull requests ahead of it and its own
    pull request, so it is often a combination no single run judged.
  - GitHub's documentation does not say which cache scopes a `merge_group`
    run can restore or save, what token and secrets it gets, whether the
    base branch lands the group's exact commit, or the exact suffix of the
    group's branch name.
- **A pull request's own scope cannot be trusted by the default branch**
  (ADR-0007 decisions 3 to 5). Its own workflow can write anything there. A
  pull request's passing run is trusted on the default branch only as its
  `passed` record says: through the `ci.check` check-run, and with no proofs
  of its own scope used.
- **Some projects cannot take the gate through Composer.** Their Symfony
  versions conflict, or they want a tool outside their dependencies.
  ADR-0011 rejected a PHAR *instead of* the Composer package, because the
  Pest plugin, `#[Holds]` and extension discovery need the package in the
  project's autoloader. The tools people use for a PHAR read, at source:
  - Box 4.7.0 supports PHP 8.5. Its signature `algorithm` takes `MD5`,
    `SHA1`, `SHA256`, `SHA512` or `OPENSSL`, and its documentation deprecates
    the OpenSSL signature and calls PHP's own PHAR signature a guard against
    accidental corruption, not against modification. Its
    `exclude-composer-files`, on by default, leaves
    `vendor/composer/installed.json` out of the PHAR.
  - Infection and PHPStan publish detached GPG signatures (`.phar.asc`), and
    Phive verifies GPG signatures. Infection's PHAR scopes its dependencies
    under a prefix, leaves its mutator interfaces and `PhpParser` unscoped,
    and then includes the project's `vendor/autoload.php`.
  - PHP-Scoper cannot make a scoped Composer plugin work, because Composer
    does not load the scoped aliases.
  - Pest finds plugins through `extra.pest.plugins` in the project's
    installed packages (ADR-0004 decision 3), which a PHAR is not.
  - GitHub's artifact attestations sign SLSA provenance with Sigstore, are
    checked with `gh attestation verify`, are free for public repositories,
    and can be pushed to a container registry beside an image.
  - GHCR accepts images from `GITHUB_TOKEN` with `packages: write`, and
    Docker's official `php:8.5-cli` image takes pcov through
    `install-php-extensions`.
- **Survivors often share one cause.** Several mutants of one comparison
  survive for want of one boundary test. Mutant subsumption, as Ammann,
  Delamaro and Offutt, and Kurtz and others describe it, orders mutants by
  which tests kill them. Survivors have no killing test, so grouping them
  has to come from where and how they mutate.

## Decision

### Owners

1. **A survivor's owners are GitHub's CODEOWNERS, read as GitHub reads it.**
   - The file is the first of `.github/CODEOWNERS`, `CODEOWNERS` and
     `docs/CODEOWNERS`, **as it is at the base**, read through `ChangeSource`
     (ADR-0001). A pull request cannot reroute its own survivors by editing
     the file.
   - The syntax and limits are GitHub's: gitignore-like patterns without
     `!`, `[ ]` or an escaped `#`, the last match winning, case-sensitive
     paths. An invalid line is skipped and listed as a warning. A file over
     3 MB is ignored, with a warning.
   - GitLab's CODEOWNERS syntax (sections, optional sections and their
     default owners) is read under the GitLab plan when merge request
     comments reach GitLab (ADR-0024).
   - A second ownership map in the gate's config is not offered.

2. **A survivor belongs to one owner group: the exact owner list of the last
   rule matching its file.**
   - A group is written as its owners, such as `@acme/payments @jo`.
   - A file no rule matches, or whose rule names no owner, is *unowned*.
   - Each survivor appears once, so a group's counts add up to the total.

3. **The comment mentions each owner group once per pull request.**
   - `owners.mention`, `first` (default) or `never`. Under `first`, a group
     is mentioned in the update that first gives it a survivor on the pull
     request. The comment keeps a hidden record of groups already mentioned,
     and later updates name them without `@`.
   - The first step of the build proves that a mention added by editing a
     comment notifies, and that `github-actions[bot]` can mention a team. If
     an edit does not notify, `first` posts one short separate comment with
     the mentions and a link to the sticky comment. If the bot cannot
     mention a team, team names are printed without `@` and `doctor` says
     so.
   - Owners are the one place the comment writes a mention. An owner token
     is printed only if it matches `@user` or `@org/team` exactly. An email
     owner is printed as plain text. Everything else the project wrote stays
     escaped (ADR-0009 decision 3).

4. **An owner can have a floor of its own.**
   - `owners.floors` maps an owner, such as `@acme/payments`, to a number
     from 0 to 100. Empty by default.
   - It is a declared minimum, judged over every unit the owner owns across
     the project, with carried results completing it (ADR-0003 decision 4).
     A score below it fails the run with exit code 1, and the message names
     the owner group, its floor and its score.
   - It has no baseline and does not ratchet. Ownership moves often, and a
     baseline keyed by owner would change whenever CODEOWNERS does.

5. **The comment groups survivors on changed lines by owner group.**
   - Within ADR-0009's cap of 20, each group is headed by its owners and its
     count.
   - A line follows for each owner group the change touches: its score and,
     where set, its floor and result.
   - The console and the step summary group the same way. The JSON report
     gains `owners: [{owners: ["@acme/payments"], score, floor, judgement,
     counts, mutants}]`.
   - `owners.mention` and `owners.floors` judge or report only, and are not
     in the proof key (ADR-0007 decision 2.3).
   - `Core` holds the ownership: a pure parser of CODEOWNERS text and a
     matcher, with no I/O, tested over fixtures that include every
     documented exception. The Cli reads the file at the base. No port
     changes.

### Merge queues

6. **A merge group is a run of its own kind.**
   - The CiPlan port's `runOn()` reads a `merge_group` event as a *merge
     group*, from the event payload. It is judged like a pull request from
     `merge_group.base_sha`: change-scoped reach (ADR-0005), and the new-code
     floor over the group's changed lines (ADR-0003 decision 8).
   - It has **no proof scope of its own**. It reads the default branch's
     ledger and writes none, as a run on a detached `HEAD` does (ADR-0006
     decision 5).
   - `baseline.improvement` reports rather than requires, since nothing can
     commit to a group.
   - This amends ADR-0006 decision 5's ref table with a row for merge groups.

7. **A group reuses what a pull request proved only through the tree and the
   check-run.**
   - When the group's head tree equals the head tree of the pull request it
     was built for, and that pull request's `ci.check` check-run passed on
     that head with a `passed` record of `ownScopeProofs: 0` (ADR-0007
     decision 3), the group reaches nothing and passes without mutating.
     That is the case when the pull request was up to date and first in the
     queue.
   - Otherwise the run is change-scoped from `base_sha`, carrying from the
     default branch's ledger, so only the combination is judged.
   - The pull request is found from the group's head ref once the first
     step of the build confirms GitHub's branch suffix, and otherwise
     through the API. With neither, the rule is not applied and the group is
     judged.
   - The pull request's own ledger is never read.

8. **After the queue merges, the push to the default branch reaches nothing
   the queue proved.**
   - ADR-0005 decision 5 extends to groups: a pushed commit whose tree
     equals a merge group's head tree, whose `ci.check` passed with no
     own-scope proofs, reaches nothing.
   - Trees are compared, so the rule holds whether GitHub lands the group's
     exact commit or another commit with the same tree.

9. **The action, the reusable workflow and `init --ci` handle `merge_group`.**
   - `mode: auto` is change-scoped on `merge_group`, from
     `merge_group.base_sha` (ADR-0005 decision 2).
   - `init --ci=github` always writes the `merge_group:` trigger, which
     fires only where a queue is on (ADR-0015 decision 16).
   - The required check stays the one `ci.check` names.
   - A group writes no ledger. An OIDC role that can write the default
     branch's prefix trusts `job_workflow_ref` at the default branch or an
     environment restricted to it (ADR-0019 decision 16), which no
     `gh-readonly-queue/` run matches. The first step of the build proves
     that a `merge_group` run cannot save into the default branch's cache
     scope. If it can, the reusable workflow skips its cache save on
     `merge_group`.
   - GitLab's merge trains are not part of this decision.

### The PHAR and the image

10. **A PHAR ships beside the Composer package, and runs everything but the
    Pest runner.**
    - Pest finds the package's plugin only among the project's installed
      packages, so a Pest project uses the Composer package. The PHAR,
      meeting `runner: pest`, exits 2: *Pest runs the gate's plugin, which
      only the Composer package installs: `composer require --dev
      nightworksio/mutation-gate -W`*.
    - Infection projects, `verdict`, `explain`, `doctor`, `config:*` and
      every report run from the PHAR. `#[Holds]` works, because the gate reads
      it from tokens and PHP loads an attribute's class only when reflection
      instantiates it.
    - This supersedes the row of ADR-0011's *Alternatives considered* that
      rejected "A PHAR instead of a Composer package": the PHAR is added
      beside the package, not instead of it.

11. **The PHAR's dependencies are scoped, and extensions still load.**
    - PHP-Scoper scopes the PHAR's dependencies under a fixed prefix. The
      gate's own namespace, `Psr\Clock` and `PhpParser` stay unscoped,
      because they are the public API extensions and mutator sets implement
      (ADR-0001, ADR-0021).
    - The PHAR loads the project's `vendor/autoload.php` after its own, so
      extensions and mutator sets are found through the project's
      `installed.json` as usual (ADR-0001 decision 4).
    - A project that also has `nightworksio/mutation-gate` installed is exit
      2: *this project installs the gate with Composer; run
      `vendor/bin/mutation-gate`*. Two copies of the same classes cannot
      coexist.
    - Under the PHAR, key item 2 (the gate's version and source reference,
      ADR-0007 decision 2) comes from the version built into the PHAR,
      because `installed.json` is not in it.

12. **The PHAR is signed twice: an attestation and a GPG signature.**
    - A GitHub artifact attestation proves which workflow built the PHAR
      from which commit, checked with `gh attestation verify
      mutation-gate.phar -R nightworksio/php-mutation-gate`.
    - A detached GPG signature, `mutation-gate.phar.asc`, serves Phive
      (`phive install nightworksio/php-mutation-gate`) and `gpg --verify`.
      The release key lives in a `release` environment whose deployment
      policy allows only `v*` tags. Its fingerprint is in the README and
      `SECURITY.md` (ADR-0018).
    - Box's internal hash is `SHA512`, for integrity only. Box's OpenSSL
      signature is not used.
    - There is no `self-update` command. Phive and the image tags replace a
      binary with verification already.

13. **The container image is `ghcr.io/nightworksio/mutation-gate`.**
    - Tags `<version>`, `<major>.<minor>` and `<major>`.
    - It is built `FROM php:8.5-cli` with pcov, git, unzip and Composer, and
      the PHAR at `/usr/local/bin/mutation-gate`. The entrypoint is
      `mutation-gate` and the working directory `/app`.
    - It is multi-arch, `linux/amd64` and `linux/arm64`, and its provenance
      attestation is pushed to the registry beside it, checked with `gh
      attestation verify oci://ghcr.io/nightworksio/mutation-gate:<tag>`.
    - The image runs the project's own tests, so it is made to be extended.
      The README shows `docker run --rm -v "$PWD":/app
      ghcr.io/nightworksio/mutation-gate:1 doctor`, and a `FROM` line adding
      extensions with `install-php-extensions`.

14. **Only the verified release job publishes them, and every pull request
    builds the PHAR.**
    - `release.yml` builds the PHAR and the image after the signed-tag checks
      and the full gate (ADR-0019 decision 15), in one job with
      `contents: write`, `packages: write`, `id-token: write` and
      `attestations: write`, run only for a verified `v*` tag.
    - The build is reproducible: a fixed scoper prefix, and Box's
      `timestamp` set to the tag commit's time. The PHAR carries its own
      version and source reference, which `init --ci` uses for the gate's own
      pin (ADR-0015 decision 14).
    - A required `phar` job builds the PHAR on every pull request and runs a
      smoke suite, publishing nothing: `--version`, `config:schema` equal to
      the committed schema, an Infection fixture's verdict, and the Pest
      refusal.
    - This amends ADR-0011 decisions 6 (the `phar` job), 8 (two more ways to
      get the gate), 9 (what a release publishes) and 10 (the image's PHP),
      and ADR-0019 decision 15.

### Clusters

15. **Survivors with one cause form a cluster, by two rules from the code.**
    - **One expression:** survivors whose original spans overlap within one
      statement, such as `<` → `<=`, `<` → `>` and a negated condition of one
      `if`. One boundary test kills them all.
    - **One gap:** survivors in one function, of one family, judged by
      exactly the same tests, such as three removed calls none of which is
      asserted.
    - Each cluster is labelled with its rule. The second can over-group,
      which is why it is labelled.
    - `Core` computes clusters over the verdict's mutants, their spans
      (columns from tokens, ADR-0009 decision 4), the enclosing function
      and the judging tests. It is always on, and reports only.
    - **As built.**
      - A cluster holds survived and uncovered mutants that the score
        counts, since those are what `stub` writes a test for. It holds two
        at least, and a survivor is in one at most.
      - One expression is applied first, and one gap among the rest, so a
        survivor that both rules take is in its expression.
      - A span is the tokens the mutant's diff changed, found on its lines.
        It lies within one statement when no `;`, `{` or `}` is among them,
        but for a `;` that ends them. A mutant with no such span, such as a
        change that only adds, is in no expression. Overlap is read through:
        where a overlaps b and b overlaps c, all three are one cluster.
      - A gap also needs one judgement. Its function is the innermost named
        one, told apart from another of the same name by the line it begins
        on. A closure is part of the function around it. Code in no
        function, and a mutator of no family, is in no gap.
      - The verdict flow clusters once, after it judges the trees. It reads
        from the project the file of each survivor that asks for a test. A
        file it cannot read clusters nothing. `TreeVerdicts::clustered()` marks each member,
        which `JudgedMutant::cluster()` answers, and every report reads
        `TreeVerdicts::clusters()`.

16. **A cluster offers one test.**
    - `stub` for a cluster (ADR-0015 decision 1) writes one test for the
      cluster's first survivor, by line then id. Its comment lists every
      member's diff, and it carries one assertion scaffold per family
      present.
    - The stub fails until it is filled in (ADR-0015 decision 4).
    - **As built.** `stub <cluster id>` writes the one test, through
      `Core\Stub\StubText`. Every report prints a cluster's `stub` and
      `explain` commands, and `ClusterId::parse` reads its id and refuses a
      mutant id.

17. **Clusters change what is shown, never the score.**
    - Every mutant still counts.
    - **PR comment:** a cluster is one item toward the cap of 20, with its
      member count, its diffs folded, one hint and `stub <cluster id>`. The
      console and step summary show the same.
    - **JSON:** `clusters: [{id, kind, members, representative}]`, and each
      mutant's `cluster`.
    - **SARIF:** every mutant stays a result, so code scanning's fingerprints
      do not move, with `properties.cluster`.
    - **Annotations:** one per cluster, at its first survivor.
    - A cluster's id is `k` followed by 11 hex characters of a SHA-256 over
      its sorted member ids. `stub` and `explain` accept it. The `k` is no
      hex digit, so the id never reads as a mutant id, which is always 12
      hex characters, nor as the prefix of one that `explain` and
      `reproduce` accept.
    - **As built.**
      - A cluster with any member on a changed line is listed among the
        survivors on changed lines, with every member. The section's
        heading still counts the mutants.
      - A cluster's hint says why one test may kill them all, then gives
        its representative's hint.
      - The step summary's last column is `Command`: a mutant's reproduce
        command, or a cluster's stub command.
      - A cluster's annotation is titled like `Mutant cluster: 3 survivors,
        one expression`. It is an error where any member is in a set that
        failed, and it ranks as a changed line where any member is on one.
      - The JSON report always has `clusters`, which is empty where there
        are none. A mutant has `cluster` only where it is a member.
      - The id's SHA-256 is taken over the sorted member ids, one to a
        line.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **An ownership map in the gate's config** | A second ownership file that drifts from CODEOWNERS. |
| **A group per owner, repeating a survivor under each** | Each team sees all it owns, and the totals double-count. |
| **Mentioning on every update** | Every push would ping every owner again, if GitHub notifies on edits. |
| **Naming owners, never mentioning them** | The feature asks for mentions. It stays available as `owners.mention: never`. |
| **Ratcheted owner floors in the baseline** | Every reassignment in CODEOWNERS would move baseline lines, and a pull request that only edits CODEOWNERS could fail. |
| **Owner floors on new code only** | Existing survivors in a team's code would never be its concern. |
| **A table of every owner's score on every pull request** | Long, and mostly about code the change did not touch. |
| **A merge group as a branch push with a scope of its own** | Skips the new-code floor, and every group writes a ledger that is never read again. |
| **A merge group reading its pull request's scope** | A pull request's own workflow can plant anything in its scope, so the default branch would take the pull request's own word. |
| **Passing every group whose pull requests passed** | Passes combinations nobody judged, such as two pull requests that each pass alone. |
| **Judging every push after the queue merges** | Doubles the work of every queued merge. |
| **A `--merge-queue` flag on `init`** | A team that turns the queue on later finds its required check never reported and its queue stuck. |
| **A second Composer package carrying the PHAR, as PHPStan ships** | Full parity for Pest, and two Composer packages holding the same classes that must conflict with each other, with every user choosing between them. |
| **A PHAR for the verdict and report jobs only** | Not a way to run the gate. |
| **No extensions from a PHAR** | Third-party reporters, stores and mutator sets would be lost exactly where a PHAR is chosen. |
| **An attestation only** | No Phive, which verifies GPG or OpenSSL signatures only. |
| **Box's OpenSSL signature** | Deprecated in Box, and it proves integrity only against accidents. |
| **An Alpine-based image** | musl differs from the glibc most CI runners and production use, which is a source of test-only differences. |
| **No image, a documented Dockerfile** | The image is what was asked for. |
| **A cosign signature beside the attestation** | The attestation is already Sigstore-signed, and `gh attestation verify` checks it. |
| **A separate publish workflow on the release event** | A second privileged workflow to keep to ADR-0019's rules. |
| **A `self-update` command** | Downloading and verifying a binary in PHP, which Phive and the image tags already do. |
| **Clusters of one expression only** | Misses the common case where nothing asserts a function's effects. |
| **Clusters proven by running candidate tests** | Needs tests that do not exist yet. |
| **A sentence per cluster instead of a stub** | Loses ADR-0015's scaffold. |
| **A cluster counting as one mutant in the score** | Changes the score's meaning, and a heuristic would decide a verdict. |
| **`stub --cluster=<12 hex>`** | An extra flag, and two 12-hex namespaces that look alike in a log. |

## Consequences

**A reviewer sees who is asked to act,** and each team is pinged once per
pull request.

**A merge queue is judged on what it will merge,** and an up-to-date pull
request's group costs one planning job. Nothing a pull request wrote for
itself reaches the default branch through the queue.

**The gate installs without Composer** for Infection projects and every
command that runs no Pest, from a PHAR whose origin can be checked two
ways, or from an image.

**A survivor list is shorter where one test would fix several,** while the
score and code scanning see every mutant as before.

## Related

- [ADR-0001](0001-a-framework-free-core-behind-nine-ports.md): extension discovery, which the PHAR keeps
- [ADR-0003](0003-a-floor-only-rises.md): carried results, which complete an owner's score
- [ADR-0004](0004-pest-and-infection-behind-one-runner-port.md): Pest's plugin, which only the Composer package installs
- [ADR-0005](0005-what-a-change-reaches-is-what-is-mutated.md): `mode: auto`, and the pushed commit a merged pull request proved
- [ADR-0006](0006-shards-are-cut-by-learned-cost-and-planned-once.md): the ref table and runs with no scope
- [ADR-0007](0007-a-proof-is-keyed-by-everything-its-verdict-reads.md): `passed`, own-scope proofs and the key's gate version
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the comment, the JSON report, SARIF and annotations
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the rejected PHAR row this supersedes, and the CI, distribution and releases
- [ADR-0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): `stub`, and the templates and their pins
- [ADR-0018](0018-the-documentation-is-versioned-and-tested-with-the-code.md): the README and `SECURITY.md`
- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): the release workflow and the OIDC roles
- [ADR-0021](0021-mutators-are-written-once-and-first-party-sets-can-leave.md): the SDK's namespace the PHAR leaves unscoped
- [ADR-0024](0024-the-gate-runs-and-comments-beyond-github-and-aggregates-an-organisation.md): GitLab's comment, where GitLab's CODEOWNERS syntax is read
