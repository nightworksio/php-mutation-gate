/** Where the site is served, and the ref of the repository it is built from. */
export const SITE = 'https://nightworksio.github.io';

/** The path the whole site is served under: every copy of the docs sits below it. */
export const ROOT_BASE = '/php-mutation-gate';

/** The path of the copy being built: the root, `/next`, or a minor's `/X.Y`, below `ROOT_BASE`. */
export const COPY = process.env.SITE_COPY ?? '';

export const BASE = `${ROOT_BASE}${COPY}`;

export const REF = process.env.SITE_REF ?? 'main';

export const REPOSITORY = 'https://github.com/nightworksio/php-mutation-gate';

/** The directory every page under `.docs` is read from, from this project's root. */
export const DOCS = '../.docs';

/** The directories of `.docs` that are pages, one for each reader, and the ADRs. */
export const SECTIONS = ['using', 'features', 'extending', 'contributing', 'decisions'];

/** The files at the repository's root that are pages too, by the page each is. */
export const ROOT_PAGES = {
    'CONTRIBUTING.md': 'contributing',
    'ARCHITECTURE.md': 'contributing/architecture',
    'SECURITY.md': 'contributing/security',
};

/** The page a file under `.docs` is, by its path there: `using/ci/README.md` is `using/ci`. */
export function slugOf(entry) {
    return entry.replace(/\.md$/, '').replace(/(^|\/)README$/, '');
}

/** Whether a path under `.docs` is a page. */
export function isPage(entry) {
    return entry.endsWith('.md') && SECTIONS.some((section) => entry.startsWith(`${section}/`));
}

/** The page a file of the repository is, by its path from the repository's root; none where it is no page. */
export function pageOf(path) {
    if (Object.hasOwn(ROOT_PAGES, path)) {
        return ROOT_PAGES[path];
    }

    const inDocs = path.startsWith('.docs/') ? path.slice('.docs/'.length) : null;

    return inDocs !== null && isPage(inDocs) ? slugOf(inDocs) : null;
}
