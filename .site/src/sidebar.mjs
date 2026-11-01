import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { titleOf } from './pages.mjs';
import { DOCS, slugOf } from './where.mjs';

const ROOT = fileURLToPath(new URL(`../${DOCS}/`, import.meta.url));

const INDEX = 'README.md';

function label(name) {
    const words = name.replaceAll('-', ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

function items(directory) {
    const names = readdirSync(join(ROOT, directory)).sort();
    const entries = names.includes(INDEX) ? [{ label: 'Overview', slug: slugOf(`${directory}/${INDEX}`) }] : [];

    for (const name of names) {
        const path = `${directory}/${name}`;

        if (statSync(join(ROOT, path)).isDirectory()) {
            const index = join(ROOT, path, INDEX);
            const title = existsSync(index) ? titleOf(readFileSync(index, 'utf8')) : label(name);

            entries.push({ label: title, collapsed: true, items: items(path) });
        } else if (name.endsWith('.md') && name !== INDEX) {
            entries.push({ slug: slugOf(path) });
        }
    }

    return entries;
}

/** A sidebar group of every page under a directory of `.docs`, its index first, then its pages and directories by name. */
export function group(name, directory, collapsed = false) {
    return { label: name, collapsed, items: items(directory) };
}
