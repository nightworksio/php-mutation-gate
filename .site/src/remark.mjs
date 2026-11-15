import { existsSync, statSync } from 'node:fs';
import { dirname, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASE, REF, REPOSITORY, pageOf } from './where.mjs';

const ROOT = fileURLToPath(new URL('../', import.meta.url));

const REPOSITORY_ROOT = resolve(ROOT, '..');


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
    return typeof file.path === 'string' && pageOf(posix(relative(REPOSITORY_ROOT, file.path))) !== null;
}

/** Where a relative link of a page points on the site, or on GitHub at the ref the site is built from. */
export function target(from, url) {
    const [path, hash] = url.split(/#(.*)/s, 2);
    const absolute = resolve(dirname(from), decodeURI(path));
    const fragment = hash === undefined ? '' : `#${hash}`;
    const inRepository = relative(REPOSITORY_ROOT, absolute);

    if (inRepository.startsWith('..') || !existsSync(absolute)) {
        throw new Error(`${posix(relative(REPOSITORY_ROOT, from))} links ${url}, which is no file of the repository.`);
    }

    const page = pageOf(posix(inRepository));

    if (page !== null) {
        return `${BASE}/${page}/${fragment}`;
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

/** Points each relative link of a page at its page on the site, or at its file on GitHub. */
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
