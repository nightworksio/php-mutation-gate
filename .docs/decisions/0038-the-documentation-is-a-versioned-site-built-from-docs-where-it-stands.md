# ADR-0038: The documentation is a versioned site, built from `.docs` where it stands, and every link the gate prints points into it

**Status:** Accepted
**Date:** 2026-10-31

## Context

ADR-0018 keeps the documentation as plain Markdown under `.docs/`, rendered
by GitHub, with no site. What holds, read at source:

- **The tree is ready for a generator.** ADR-0018's item 1 lays `.docs/` out
  so a generator can read it without a file moving: `guide/`, `reference/`
  and `decisions/`, each with a `README.md` as its index.
- **The package ships no docs.** `.gitattributes` export-ignores `/.docs`, so
  every relative link from `README.md` into `.docs/` reaches no file in
  `vendor/nightworksio/mutation-gate`, which is where an installed package's
  README is read.
- **The gate prints links into GitHub.** `Core\Troubleshooting\Guide` builds
  each troubleshooting slug's link, and each report term's link into the
  guide to reading the report, as
  `https://github.com/nightworksio/php-mutation-gate/blob/<ref>/.docs/guide/<page>.md#<anchor>`.
  The ref is `v<version>` for a release and `main` for anything else.
- **No version is tagged.** A link a release would print names a tag that
  does not exist.
- **Node is a tool here.** markdownlint runs on Node, locked in
  `.github/bot/markdownlint`, and Dependabot updates it.
- **GitHub Pages is off.** Its default URL for this repository is
  `https://nightworksio.github.io/php-mutation-gate/`.
- **Starlight is Astro's documentation theme.** It reads any directory
  through Astro's content-layer glob loader, indexes every page with
  Pagefind, and validates links with a plugin. GitHub renders a `mermaid`
  fenced block as a diagram, and Starlight renders one through an Astro
  integration.

## Decision

This amends ADR-0018's items 1, 2, 3, 6 and 8: the docs stay plain Markdown
under `.docs/`, readable on GitHub, and are also published as a site.

### The site

1. **Starlight renders `.docs/` in place.** A glob loader reads
   `.docs/guide/`, `.docs/reference/` and `.docs/decisions/` where they
   stand, and no file moves or is copied.
   - **Pages.** `.docs/<path>.md` is the page `<path>/`, and a directory's
     `README.md` is the directory's page. A page's title is its first
     heading.
   - **Sections.** The sidebar has the guide, the reference, and the ADRs
     as *Design*.
   - **Links.** A relative link between two files under `.docs/` becomes a
     link between their pages. A link to any other file of the repository
     becomes its GitHub URL at the ref the site is built from.
   - **Anchors.** Headings get their anchors from github-slugger, as on
     GitHub, so an anchor that works on GitHub works on the site.

2. **Search is Pagefind, and diagrams are Mermaid.**
   - Each built copy of the docs has its own Pagefind index, so search finds
     pages of the version being read.
   - A `mermaid` fenced block renders as a diagram in the theme's colours.
     GitHub renders the same block natively, so `.docs/` needs no second
     form of a diagram.

3. **The site is titled php-mutation-gate, and its theme is the pixel-art
   artwork in `.docs/brand/` and its palette.**
   - The logo, `php-mutation-gate-logo.svg`, 576 by 512, heads the README.
     The mark, the logo without the name beneath it, is the site's logo, and
     the mark padded to a square is its icon, as an SVG and as PNG files of
     32, 180 and 512 pixels. The site's Open Graph image is the social card,
     `php-mutation-gate-social.svg`, 1280 by 640, rendered to PNG.
   - Every colour of the theme is one of the logo's fills, and code keeps
     the colours of its highlighter's own themes. The dark scheme sets the
     greens `#39d36b` and `#8dffab` and the cream `#f4f1c9` on the night
     blue `#12101f`. The light scheme sets the night blues `#0b0914` and
     `#12101f` and the deep greens `#1d7a43` and `#124a2c` on the cream
     `#f2eedc`. The slates between them, from `#2b2638` to `#c6c6d6`, are
     the greys of both.

4. **The site project lives in `.site/`, beside `.docs/`, and is
   export-ignored as `.docs/` is.**
   - It holds the Astro configuration, the theme, the home page, the loader
     and the components. Every page of content is a file under `.docs/`.
   - Its npm dependencies are pinned by a committed `package-lock.json`,
     installed with `npm ci`, and updated by Dependabot with the cooldown
     and grouping every other ecosystem here has.
   - `.gitattributes` export-ignores `/.site`, so the Composer package ships
     none of it.

### CI and hosting

5. **A docs change runs hygiene and a site build, and a broken internal link
   fails the build.**
   - typos, lychee and markdownlint run on every change, and read `.docs/`
     and `.site/`.
   - `site.yml` builds the site. `ci.yml` calls it where `scope` says
     `.docs/`, `.site/`, the site's workflows, `README.md` or the code that
     prints a link changed. It is gated by `scope` and an `if:`, never by
     `on.paths`, so it reports on every pull request and can be required.
   - The build fails on a link to a page or an anchor that no page has.

6. **The site is served by GitHub Pages at its default URL,
   `https://nightworksio.github.io/php-mutation-gate/`, with the base path
   `/php-mutation-gate` and no custom domain.**
   - `pages.yml` builds the site in a job that holds `contents: read` only,
     and uploads it with `actions/upload-pages-artifact`.
   - A second job, in the `github-pages` environment, deploys it with
     `actions/deploy-pages` over OIDC: it holds `pages: write` and
     `id-token: write`, runs no npm and no project code, and no secret is
     stored.
   - It runs on a push to `main` that changes `.docs/`, `.site/` or
     `pages.yml`, on a published release, and on demand. It is never
     required, so `on.paths` limits it.

### Versions

7. **While no version is tagged, the site is `main`'s docs at its root, with
   no version picker.**

8. **Where a tag exists, each minor has a copy built from its newest tag,
   and `main` is shown as *next*.**
   - `pages.yml` builds every copy with `main`'s site project, from the
     `.docs/` of the copy's own ref: `main` at `/next/`, each minor `X.Y`
     from its newest tag at `/X.Y/`, and the newest minor that has a
     release, not only a pre-release, at the root too.
   - A copy's pages hold only what its tag's `.docs/` holds, so a later
     commit to `main` can change how a copy looks, never what it says.
   - **A release adds its copy by being published.** The published release
     starts `pages.yml`, which lists the tags again: a new minor adds its
     path and, once released, moves the root to itself, and a patch rebuilds
     its minor's copy. Nothing is committed for it.
   - The version picker is part of the site project, so every copy has it.
     It reads the list of versions `pages.yml` writes at the root, so a copy
     of an older release lists the newer ones too.

### Links into the site

9. **Every link the gate prints points at the site's page for the running
   version.**
   - That is each troubleshooting slug's link, each report term's link, and
     every other pointer into the docs a message, a report or a schema
     description carries.
   - A release `X.Y.Z`, a pre-release included, links `/X.Y/`. Anything else
     links `main`'s pages: the root while no version is tagged, and `/next/`
     from the first tag on.
   - A test in `tests/Docs` holds every printed link to a page the site
     build produces. In the PHP suite it maps each link back to its file and
     heading under `.docs/` by item 1's rule. In `site.yml` it checks each
     link against the pages and anchors just built.

10. **The README links the site with absolute URLs, and shows its images by
    absolute URLs.**
    - Every link from `README.md` into the docs is the page's URL at the
      site's root, so it works on GitHub, on Packagist and in `vendor/`.
    - Every image is an absolute `https://raw.githubusercontent.com/` URL of
      the file on `main`.
    - `site.yml` checks the README's site links against the build, as it
      checks printed links, and lychee leaves the site's URLs to it.

## Alternatives considered

| Option | Why it lost |
|--------|-------------|
| **Plain Markdown on GitHub only, as ADR-0018 has it** | No search, no theme, and every link out of the installed package or Packagist needs GitHub's file view of a directory the package does not ship. |
| **MkDocs Material** | In maintenance mode, and a Python toolchain beside the Node one the repository runs. |
| **Zensical** | Material's successor, with little record to build on. |
| **VitePress** | Comparable. Starlight was chosen for its content loader, which reads a directory where it stands, its Pagefind search and its link validator, which checks anchors. |
| **Docusaurus** | A React application for a set of Markdown pages, and its versions are copies of the docs committed to the repository. |
| **The site project in `docs/`** | Against ADR-0018's one home for writing, `.docs/`, and GitHub reads `docs/` as a Pages source by convention. |
| **The site project inside `.docs/`** | Tooling among the pages, and the glob that reads the pages has to step around it. |
| **The docs moved into the site project** | Every ADR link, the `Spec:` hook's path, `scope`'s rules and the Docs suite's paths change, and the files stop reading as plain Markdown on GitHub. |
| **A custom domain** | DNS and a certificate to keep, for no reader the default URL loses. |
| **Netlify, Cloudflare Pages or Vercel** | Another account, and a stored token in this repository. |
| **A `gh-pages` branch** | A workflow token that writes a branch, where `actions/deploy-pages` needs only OIDC. |
| **Each release's docs committed to `main` as a copy** | `main` carries every release's docs, and a fix to a release's docs lands in two places. |
| **Each release's built site attached to its GitHub release** | A copy built for its own path cannot also serve the root, and a fix to the site's build never reaches it. |
| **Each copy built with its own tag's site project** | A copy older than the picker would have none, and its lock's dependencies would be installed long after their fixes. |
| **Printed links into GitHub, as ADR-0018 has them** | Two homes for the same page, and the one a reader lands on from a message has no search and no version picker. |
| **Printed links to the root** | A message from an older release points at a newer release's text. |
| **The site build on every change** | A Node build on every PHP change, for pages it does not touch. |

## Consequences

**A reader on any version lands on that version's page,** from a link the
gate printed, from the HTML report, or from the version picker.

**The docs stay one set of files.** They read as Markdown on GitHub and as
pages on the site, and a behaviour change carries its docs in the same pull
request.

**The package ships no docs, and loses no link.** Every link the README and
the gate print is absolute.

**A docs change pays for a site build, and a code change does not,** unless
it changes what the gate links.

## Related

- [ADR-0011](0011-the-package-holds-itself-to-the-gate-it-ships.md): releases, which publish a version's copy
- [ADR-0018](0018-the-documentation-is-versioned-and-tested-with-the-code.md): the docs in `.docs/`, tested with the code, which this amends
- [ADR-0019](0019-contributor-automation-runs-no-pull-request-content-where-it-can-write.md): workflows that run no pull request content where they can write
