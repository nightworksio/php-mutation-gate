/** Where the site is served, and the ref of the repository it is built from. */
export const SITE = 'https://nightworksio.github.io';

export const BASE = process.env.SITE_BASE ?? '/php-mutation-gate';

export const REF = process.env.SITE_REF ?? 'main';

export const REPOSITORY = 'https://github.com/nightworksio/php-mutation-gate';

/** The directory every page is read from, from this project's root. */
export const DOCS = '../.docs';

/** The directories of `.docs` that are pages: the guide, the reference and the ADRs. */
export const SECTIONS = ['guide', 'reference', 'decisions'];

/** The page a file under `.docs` is, by its path there: `guide/ci/README.md` is `guide/ci`. */
export function slugOf(entry) {
    return entry.replace(/\.md$/, '').replace(/(^|\/)README$/, '');
}

/** Whether a path under `.docs` is a page. */
export function isPage(entry) {
    return entry.endsWith('.md') && SECTIONS.some((section) => entry.startsWith(`${section}/`));
}
