# ADR-0018: The documentation lives in `.docs`, is versioned and tested with the code, and the repository carries its contributor, security and release policy

**Status:** Accepted
**Date:** 2026-09-30

## Context

The first release waits for every feature, and for documentation a user can
adopt the gate from. What exists, read at source and through GitHub's API:

- **The README is the design.** The decisions index says: "Together with the
  README, the ADRs are the whole of this package's design." The README holds
  the full configuration reference, the environment variables, the files, and
  CI setups for five CIs. ADR-0002 decision 6 names it as the home of the
  configuration reference.
- **The ADRs live in `.docs/decisions/`,** and `.gitattributes`
  export-ignores `/.docs`, so nothing under it ships in the package.
- **Hygiene covers Markdown everywhere.** typos, lychee (`hidden = true`) and
  markdownlint (`**/*.md`, which reaches dot directories) already read
  `.docs`. `ci.yml`'s `scope` job counts a change under `.docs/`, or to any
  `*.md` but `ARCHITECTURE.md` and `README.md`, as documentation, and skips
  the PHP gates for it. Those two count as code, because tests read them:
  the Guards suite reads `ARCHITECTURE.md`, and the config tests read the
  README's reference and examples.
- **The community profile is at 50%.** There is a README and a licence, and
  nothing else: no CONTRIBUTING, CODE_OF_CONDUCT, SECURITY, templates or
  CHANGELOG.
- **Repository settings.** Private vulnerability reporting is off. The wiki
  is on, and holds nothing. Discussions and Pages are off. The organisation
  has no `.github` repository of shared defaults.
- **Merging.** It is squash only. The squash commit takes the pull request's
  title as its subject and its body as its message, so the body, and any
  `Spec:` trailer in it, lands on `main`.
- **Releases.** ADR-0011 decision 9: each release is a signed tag on `main`
  and a GitHub release with notes drawn from the conventional commits.
- **Docs and code already disagree.** The README and ADR-0002 named two of
  the three PHPUnit config files the code reads (ADR-0017 decision 3).
- **The HTML reporter inlines Stryker's `mutation-testing-elements` viewer**
  (ADR-0009 decision 4). It is Apache-2.0, and every HTML report a user
  publishes redistributes it. Apache-2.0 section 4 asks for a copy of the
  licence, and any NOTICE, with each redistribution.
- **The tooling.** Material for MkDocs entered maintenance mode on
  2025-11-05, with critical fixes promised for at least 12 months, while its
  team builds a new generator, Zensical. VitePress and Docusaurus need a Node
  toolchain.
- **The Contributor Covenant.** Version 3.0 was released on 2025-07-28.

## Decision

### Where the docs live, and in what

1. **The docs are plain Markdown in the repository, rendered by GitHub.**
   There is no site. The tree is laid out so a generator could read it later
   without a file moving.

2. **Everything lives under `.docs/`, and nothing under `docs/`.**

   ```text
   .docs/
     decisions/        the ADRs, unchanged
     guide/            for users
       README.md       the index: where to start, by reader
       getting-started/  laravel.md, symfony.md, library.md, monorepo.md
       ci/             github-actions.md, gitlab.md, buildkite.md, circleci.md, other.md
       concepts/       scores-and-floors.md, reach.md, proofs-and-trust.md,
                       shards-and-costs.md, holding-tests.md, triage.md
       recipes/        adopt-on-legacy-code.md, speed-up-a-framework-app.md,
                       proofs-in-s3-or-r2.md, fork-pull-requests.md,
                       pre-push-and-watch.md, editors.md, sarif-in-code-scanning.md,
                       badge-and-trend.md, equivalent-mutants.md, monorepo-modules.md
       performance.md  drivers, holds, pest.patch, shards, proofs.ignore
       troubleshooting.md  one section per message slug (decision 8)
       faq.md
       migrating/      from-infection.md, from-pest-mutate.md
       extending/      writing-an-adapter.md, writing-a-preset.md
     reference/        configuration.md, cli.md, report-json.md,
                       action-and-workflow.md (generated, decision 5);
                       environment.md, files.md, exit-codes.md (written by hand)
   ```

   - Users read `.docs/guide/` and `.docs/reference/`. Contributors read
     `.docs/decisions/` and `ARCHITECTURE.md`.
   - `/.docs` stays export-ignored. The package ships no docs, and every link
     the gate prints points at GitHub (decision 8).

3. **Path-based handling follows the one directory.**
   - `scope` keeps counting `.docs/` as documentation, so a docs-only change
     skips the PHP gates. `.docs/reference/` is the exception once the
     reference moves there: it counts as code, as `README.md` does today,
     because the config tests move with it (decision 4).
   - The Docs suite of decision 7 runs in a job of its own, `docs`, on every
     change, as `rules` does, and it is required.
   - typos, lychee and markdownlint already read `.docs/`, and their
     configurations stay as they are.
   - The README's links into `.docs/` are relative, so the README at a tag
     links that tag's docs.

4. **The README becomes the front door.**
   - It holds what the gate is, the benchmark's table (ADR-0017), a quick
     start (decision 17), and one screen per CI linking into
     `.docs/guide/ci/`.
   - The configuration reference, the environment variables and the files
     move to `.docs/reference/`. That move is part of the documentation built
     for 1.0.0, and until then the README holds them. The tests that read
     the reference and the config examples from the README (the config
     definitions' test, the README examples' contract test and the builder's
     test) move with them in that same change, so no test ever reads a page
     that no longer holds what it checks.
   - This amends ADR-0002 decision 6, which names the README's configuration
     reference, and the decisions index: the README and `.docs/reference/`
     are, with the ADRs, the whole of the design.

5. **The reference is generated wherever a machine-readable source exists,
   and a test fails when it drifts.**

   | Page | Generated from |
   |------|----------------|
   | `configuration.md` | the config definitions ADR-0002 decision 7 generates the schema from: key, type, default, whether it affects results (ADR-0007), the ADR that decides it, and the version it arrived in. Every definition carries a description, an ADR and a version, and a definition without one fails the build. |
   | `cli.md` | the Symfony Console definitions: commands, arguments, options and exit codes |
   | `report-json.md` | `resources/report.schema.json` (ADR-0009) |
   | `action-and-workflow.md` | `action.yml` and the reusable workflow's `workflow_call` block: inputs, outputs, secrets and jobs |

   - `composer docs:generate` writes them, and a test fails when a committed
     page differs, as the schema's test does.
   - `environment.md`, `files.md` and `exit-codes.md` are written by hand. A
     test fails when `src` reads an environment variable `environment.md`
     does not list, or when the page lists one nothing reads.

6. **`main` documents the next release, and each tag is its own docs.**
   - Every reference entry carries *since*, and a guide says *since 1.x*
     where it matters.
   - A reader on a release reads `tree/v<version>/.docs/`. The README says
     so, and every link the gate prints is pinned to its own version
     (decision 8).

### Keeping the docs true

7. **Every example is tested, or says why it cannot be.**
   - Every fenced block in `README.md` and under `.docs/guide/` and
     `.docs/reference/` is either tested or marked, in its info string, as
     not testable with a reason. A test fails on an unmarked block, as
     `TheRulesAreRealTest` does on an unenforced rule.
   - By kind:
     - **config** blocks are loaded through the real loaders, and blocks
       shown as the same config in several formats must give the same
       `config:show`;
     - **JSON** blocks are validated against the published schemas;
     - **workflow YAML** goes through actionlint, and GitLab, Buildkite and
       CircleCI YAML through each provider's published JSON Schema, offline
       (ADR-0015 decision 17);
     - **shell transcripts** (`$ vendor/bin/mutation-gate …` with its output)
       run in fixture projects (one Laravel, one Symfony, one library), with
       times and other volatile parts normalised;
     - **the getting-started pages** run end to end in those fixtures.
   - Config, JSON and YAML blocks run on every change, in `docs`.
     Transcripts and getting-started run weekly and before a release, because
     they install projects.
   - The markers of ARCHITECTURE.md's K1 apply to the docs' prose too, which
     states what is true now.
   - The tests live in `tests/Docs`.

8. **Every refusal, failure and warning has a stable slug and a link.**
   - Each kind of *cannot judge*, failure and warning has a slug of words:
     `no-floor`, `suite-failed-before-mutating`, `native-markers`,
     `pest-filter-too-long`, and so on.
   - The message ends with
     `https://github.com/nightworksio/php-mutation-gate/blob/v<installed>/.docs/guide/troubleshooting.md#<slug>`,
     pinned to the version that printed it.
   - A test fails when a slug has no section, or a section has no slug.
   - `doctor`'s findings use the same slugs (ADR-0017).
   - Slugs are words, not the requirement IDs ADR-0011 refuses. They are
     public API (ADR-0011 decision 7).

### The changelog and releases

9. **`CHANGELOG.md` has Keep a Changelog's layout, generated from `main`'s
   commits and then edited.**
   - git-cliff reads `main`'s squash commits, one per pull request.
   - `feat` goes under *Added*, `fix` under *Fixed* and `perf` under
     *Changed*.
   - A `!` or `BREAKING CHANGE` goes under *Breaking*, quoting the pull
     request body's *Migration* section.
   - `docs`, `test`, `ci`, `build`, `chore` and `style` are left out.
   - Nobody edits the changelog in a feature's pull request, so no two pull
     requests conflict on it. The maintainer edits the generated section
     before the tag.

10. **Release notes are the version's CHANGELOG section.**
    - They are written, generated then edited, in a pull request before the
      tag. The GitHub release's body is that section.
    - **1.0.0's notes are written by hand:** the feature table, grouped as
      the README groups it, rather than a list of every commit before the
      first release. Generation starts after 1.0.0.
    - The contributor bot drafts that pull request, and the maintainer signs
      the tag (ADR-0019, decision 15).

### The repository's files

11. **CONTRIBUTING says what a contribution needs, and signing is not one of
    them.**
    - It covers:
      - setup: PHP 8.5 with pcov, and `composer install`, which sets
        `core.hooksPath`;
      - the local gates, and which gates only CI runs;
      - a tour of `ARCHITECTURE.md` and the ADRs;
      - how to add an adapter, a preset or an extension.
    - A contribution needs:
      - a conventional pull request title and commits;
      - no assistant credited in a trailer or a line. CONTRIBUTING says so
        plainly, because otherwise a contributor using an assistant meets it
        as a red check.
    - `Spec:` is asked for in the pull request body, which becomes the
      squash commit's message.
    - Contributors are not required to sign commits. `main` receives only
      squash commits, which GitHub signs. ADR-0011 decision 9's "every
      commit is signed" means every commit on `main`.

12. **SECURITY.md sends reports through GitHub.**
    - **Reporting:** GitHub's private vulnerability reporting, which is
      turned on, with `info@nightworks.io` as the fallback.
    - **Supported:** the latest minor of the current major. After a new
      major, the previous major's last minor gets security fixes for six
      months.
    - **In scope:** the package, the action and the reusable workflow,
      including the ledger's trust boundaries (ADR-0007 decision 5).
    - **Response:** acknowledgement within three working days. Fixes are
      published as GitHub security advisories, with a CVE.

13. **The code of conduct is the Contributor Covenant 3.0, verbatim,** with
    `info@nightworks.io` as its contact.

14. **Issues use forms, and pull requests use one template.**
    - **Bug:** asks for `mutation-gate --version`, `config:show`, the runner
      and PHP versions, the CI, and the full message with its slug
      (decision 8).
    - **Feature request:** asks for the problem before the proposal.
    - **Blank issues are off.** `config.yml` sends security reports to the
      private form and questions to the docs. Discussions stay off.
    - **The pull request template:** *What*, *Why*, *Spec:*, *Migration* (for
      a breaking change only), and the local-gates checklist.

15. **There is no GOVERNANCE file.** CONTRIBUTING's *Maintainers and
    decisions* section says:
    - there is one maintainer, as CODEOWNERS says;
    - design is decided by ADR;
    - a proposal becomes an ADR by pull request.

16. **Vendored code carries its notices everywhere it goes.**
    - The viewer's licence, and its NOTICE if upstream ships one, sit beside
      the vendored file under `resources/`, which ships.
    - `THIRD-PARTY-NOTICES.md` at the root lists every vendored part with
      its version, licence and source.
    - **Every generated HTML report** opens its inlined script with a comment
      naming the viewer, its version, Apache-2.0 and the licence's URL.
    - A test fails when the vendored version changes and the notices do not.

    This amends ADR-0009 decision 4, where the licence ships with the
    package only.

17. **The README's front page.**
    - **At most six badges:**
      - the Packagist version;
      - the PHP version;
      - CI;
      - the package's own mutation score, from its `badge.json` on the
        `mutation-gate` branch;
      - the OpenSSF Scorecard;
      - the licence.
    - **The quick start** is three commands, and a tested transcript
      (decision 7):
      - `composer require --dev nightworksio/mutation-gate -W`;
      - `vendor/bin/mutation-gate init`;
      - `vendor/bin/mutation-gate`.

18. **The docs have one home.**
    - The wiki is turned off.
    - The community files live in this repository, not in an organisation
      `.github` repository. The package stands alone (ADR-0011).

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **MkDocs Material on GitHub Pages** | The PHP ecosystem's familiar look, with search and versioning. It is in maintenance mode, with fixes promised only into late 2026, and adds Python and a deploy job with `pages: write`. |
| **VitePress or Docusaurus** | Maintained, with search, and Docusaurus versions built in. A Node toolchain and its updates in a PHP package's repository. |
| **Zensical** | Material's successor, with no record yet to bet 1.0's docs on. |
| **User docs in `docs/`, ADRs in `.docs/decisions/`** | Two homes for one project's writing, against the repository's rule that documentation lives in `.docs/`. |
| **Everything in `docs/`, ADRs moved** | Every ADR link, the `Spec:` hook's path and `scope`'s rule would change, and ADRs would sit among user guides. |
| **The README as the whole reference, with guides beside it** | A 700-line front page, and guides that repeat parts of it. |
| **Only the configuration reference generated** | The CLI and the action's reference drift. |
| **A reference written wholly by hand** | Drift, as the PHPUnit config names already showed. |
| **A docs site with a version switcher** | Needs the site that decision 1 declines. |
| **A `docs` branch following the latest release** | A second branch to keep in step, and a behaviour change could not carry its docs in the same pull request. |
| **Testing config and JSON blocks only** | CLI output and setup pages would rot. |
| **Link checks alone** | Examples rot silently. |
| **Messages with no links, and troubleshooting by message text** | Users search for text that changes between releases. |
| **An *Unreleased* section edited in every pull request** | Every pull request edits the same lines, and with up-to-date checks each merge conflicts the next. |
| **Change fragments per pull request** | A second place for what the conventional title already says. |
| **GitHub's generated release notes** | They need labels, and duplicate the CHANGELOG in another shape. |
| **Contributors required to sign commits** | A barrier that protects nothing on `main`, where GitHub signs every squash commit. |
| **Reports by email only** | Outside GitHub's advisory and CVE workflow. |
| **Every minor of the current major supported** | More backporting than one maintainer can promise. |
| **The Contributor Covenant 2.1, or a code of our own** | 2.1 is superseded. A code of our own is not recognised, and is one more document to defend. |
| **Markdown issue templates** | Required fields cannot be required. |
| **Discussions for questions** | A second inbox for one maintainer. |
| **GOVERNANCE.md and MAINTAINERS.md** | Ceremony with one maintainer. |
| **Notices in the package only** | A published report would carry Apache-2.0 code with no notice. |
| **Download, coverage and Sonar badges** | They say less than the mutation score this package exists to publish. |

## Consequences

**The docs cannot silently rot.** References are generated, and every
example is tested or says why not. Every slug has a section, and every
section a slug.

**A reader on any version reads that version's docs,** from the link the gate
printed or from the tag's README.

**A contributor needs a conventional title and nothing exotic.** Reporting a
vulnerability is one private button.

**Every published HTML report carries its licence notice.**

## Related

- [ADR-0002](0002-one-typed-config-from-several-formats.md): the configuration reference, which moves to `.docs/reference/`
- [ADR-0009](0009-every-verdict-is-readable-by-a-machine-and-a-reviewer.md): the viewer's notice in every HTML report
- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): the `docs` job, slugs as public API, signed commits on `main`, and release notes
- [ADR-0015](0015-a-survivor-reaches-the-editor-the-test-file-and-the-commit.md): the offline schemas the YAML examples are checked against
- [ADR-0017](0017-adopting-the-gate-takes-one-command-and-every-run-says-what-it-saved.md): the benchmark's table, `doctor`'s slugs, and the PHPUnit config names
- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): the release pull request, and the bot that links the slugs
