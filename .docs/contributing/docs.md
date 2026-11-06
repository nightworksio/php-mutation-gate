# The docs

The docs are plain Markdown, readable on GitHub, and published as a versioned
site at <https://nightworksio.github.io/php-mutation-gate/>
([ADR-0018](../decisions/0018-the-documentation-is-versioned-and-tested-with-the-code.md),
[ADR-0038](../decisions/0038-the-documentation-is-a-versioned-site-built-from-docs-where-it-stands.md)).
A behaviour change carries its docs in the same pull request.

## Where they live

| Path | What it holds | Reader |
|------|---------------|--------|
| `.docs/using/` | Install, CI setup, local use, every command, key, variable, file and report, and troubleshooting | someone using the gate |
| `.docs/features/` | What the gate does, and why a verdict can be trusted | someone choosing it |
| `.docs/extending/` | Every extension point and its contract | someone writing an extension |
| `.docs/contributing/`, `CONTRIBUTING.md`, `ARCHITECTURE.md`, `SECURITY.md` | How the package is built, checked and released | a contributor |
| `.docs/decisions/` | The ADRs | a contributor |
| `.docs/brand/` | The artwork | everyone |
| `README.md` | What the gate is and has, and how to start | everyone |

The site project is `.site/`: an Astro Starlight site that reads `.docs/`
and the three root pages where they stand. A file's page is its path, and a
directory's `README.md` is the directory's page; a relative link between two
pages becomes a link between them, and a link to any other file becomes its
GitHub URL. `.site/src/sidebar.mjs` orders each section's pages, and a page it
does not list fails the build. `.gitattributes` export-ignores `.docs/` and
`.site/`, so the Composer package ships neither.

## What checks them

- **The build** fails on a link to a file that is not there, and on a link
  into the site to a page or an anchor it did not build.
- **`tests/Docs`** holds each link the gate prints, and each link the README
  gives into the site, to a file and a heading, and, with a built site, to a
  built page and anchor; and each troubleshooting slug and report term to its
  section.
- **The config tests** hold the configuration reference's keys to the
  config's definition, and its examples to the four formats.
- **Hygiene** runs typos, lychee and markdownlint over every file.

## Versions

The site holds a copy of the docs for each minor, built from its newest tag,
and `main`'s at `/next/`
([`.github/scripts/site_copies.py`](../../.github/scripts/site_copies.py)):

| Path | Built from |
|------|-----------|
| `/` | the newest minor that has a release, not only a pre-release; `main` while there is none |
| `/next/` | `main` |
| `/X.Y/` | the newest tag of the minor `X.Y` |

Every copy is built with `main`'s site project from its own ref's tree, so a
later commit to `main` can change how a copy looks, never what it says. Each
copy has its own search index, and a picker of every copy, which reads the
`versions.json` the root serves.

A release `X.Y.Z`, a pre-release included, prints links into `/X.Y/`, and
anything else into `/next/`. The README links the root.

`pages.yml` builds and deploys every copy on a push to `main` that changes the
docs, and on demand; the release workflow starts it once a release is
published. It is served by GitHub Pages, deployed over OIDC by a job that runs
no npm and no project code.

## Writing a page

Prose states what is true of the code it ships with: no history, no plans.
Every key, variable, command and option a page names is one the code
declares, and every claim about behaviour is one a test or the code holds.
