import { existsSync, statSync } from 'node:fs';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASE, DOCS, REF, REPOSITORY, isPage, slugOf } from './where.mjs';

const ROOT = fileURLToPath(new URL('../', import.meta.url));

const REPOSITORY_ROOT = resolve(ROOT, '..');

const DOCS_ROOT = resolve(ROOT, DOCS);

const SCHEME = /^[a-z][a-z0-9+.-]*:/i;

function* nodes(tree) {
    yield tree;

    for (const child of tree.children ?? []) {
        yield* nodes(child);
    }
}

function posix(path) {
    return path.split(sep).join('/');
}

function isDoc(file) {
    return typeof file.path === 'string' && file.path.startsWith(DOCS_ROOT + sep);
}

/** Where a relative link of a page under `.docs` points on the site, or on GitHub at the ref the site is built from. */
export function target(from, url) {
    const [path, hash] = url.split(/#(.*)/s, 2);
    const absolute = resolve(dirname(from), decodeURI(path));
    const fragment = hash === undefined ? '' : `#${hash}`;
    const inRepository = relative(REPOSITORY_ROOT, absolute);

    if (inRepository.startsWith('..') || !existsSync(absolute)) {
        throw new Error(`${posix(relative(REPOSITORY_ROOT, from))} links ${url}, which is no file of the repository.`);
    }

    const inDocs = posix(relative(DOCS_ROOT, absolute));

    if (!inDocs.startsWith('..') && isPage(inDocs)) {
        return `${BASE}/${slugOf(inDocs)}/${fragment}`;
    }

    const kind = statSync(absolute).isDirectory() ? 'tree' : 'blob';

    return `${REPOSITORY}/${kind}/${REF}/${posix(inRepository)}${fragment}`;
}

/** Drops a page's first heading, which Starlight renders as the page's title. */
export function dropTitle() {
    return (tree, file) => {
        if (!isDoc(file)) {
            return;
        }

        const first = tree.children.findIndex((node) => node.type === 'heading' && node.depth === 1);

        if (first !== -1) {
            tree.children.splice(first, 1);
        }
    };
}

/** Points each relative link of a page under `.docs` at its page on the site, or at its file on GitHub. */
export function rewriteLinks() {
    return (tree, file) => {
        if (!isDoc(file)) {
            return;
        }

        for (const node of nodes(tree)) {
            if ((node.type === 'link' || node.type === 'definition') && node.url !== '' && !SCHEME.test(node.url) && !node.url.startsWith('#') && !node.url.startsWith('/')) {
                node.url = target(file.path, node.url);
            }
        }
    };
}
