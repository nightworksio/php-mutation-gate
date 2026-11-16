import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { titleOf } from './pages.mjs';
import { DOCS, ROOT_PAGES, slugOf } from './where.mjs';

const ROOT = fileURLToPath(new URL(`../${DOCS}/`, import.meta.url));

const INDEX = 'README.md';

/** The order of each section's pages, by their names under `.docs`; a directory is named with its `/`. */
const ORDER = {
    using: [INDEX, 'install.md', 'ci/', 'local.md', 'editors.md', 'proofs.md', 'configuration.md', 'cli.md', 'environment.md', 'files.md', 'reports.md', 'reading-the-report.md', 'troubleshooting.md', 'compatibility.md'],
    'using/ci': [INDEX, 'github-actions.md', 'gitlab.md', 'bitbucket.md', 'azure-devops.md', 'buildkite.md', 'circleci.md', 'jenkins.md', 'other.md'],
    features: [INDEX, 'floors.md', 'reach.md', 'holding-tests.md', 'speed.md', 'trust.md', 'reporting.md', 'mutators.md'],
    extending: [INDEX, 'adapters.md', 'runners.md', 'reporters.md', 'proof-stores.md', 'config-loaders.md', 'mutators.md', 'ports.md'],
    contributing: ['ci.md', 'docs.md', 'releasing.md'],
};

/** Whether a page the order does not list fails the build: for `main`'s docs, and not for a tag's, which keep the pages they were tagged with. */
const STRICT = process.env.SITE_STRICT !== 'false';

/**
 * The pages a directory of `.docs` holds, as the sidebar orders them. Where the order and the pages differ, the
 * build fails for `main`'s docs, and a tag's lists the pages it holds in the order's places, then the rest by name.
 */
function ordered(directory) {
    const order = ORDER[directory] ?? [];
    const present = readdirSync(join(ROOT, directory))
        .filter((name) => name.endsWith('.md') || statSync(join(ROOT, directory, name)).isDirectory())
        .map((name) => (name.endsWith('.md') ? name : `${name}/`))
        .sort();
    const unordered = present.filter((name) => !order.includes(name));
    const missing = order.filter((name) => !present.includes(name));

    if (STRICT && (unordered.length > 0 || missing.length > 0)) {
        throw new Error(`.docs/${directory} holds ${unordered.join(', ') || 'nothing'} the sidebar does not order, and lacks ${missing.join(', ') || 'nothing'} it orders; list each page in sidebar.mjs.`);
    }

    return [...order.filter((name) => present.includes(name)), ...unordered];
}

function items(directory) {
    return ordered(directory).map((name) => {
        if (name === INDEX) {
            return { label: 'Overview', slug: slugOf(`${directory}/${INDEX}`) };
        }

        if (name.endsWith('/')) {
            const path = `${directory}/${name.slice(0, -1)}`;

            return { label: titleOf(readFileSync(join(ROOT, path, INDEX), 'utf8')), collapsed: true, items: items(path) };
        }

        return { slug: slugOf(`${directory}/${name}`) };
    });
}

/** Every page under a directory of `.docs`, its index first, then its pages and directories by name. */
function listed(directory) {
    const names = readdirSync(join(ROOT, directory)).sort();
    const entries = names.includes(INDEX) ? [{ label: 'Overview', slug: slugOf(`${directory}/${INDEX}`) }] : [];

    for (const name of names) {
        const path = `${directory}/${name}`;

        if (statSync(join(ROOT, path)).isDirectory()) {
            const index = join(ROOT, path, INDEX);
            const label = existsSync(index) ? titleOf(readFileSync(index, 'utf8')) : name;

            entries.push({ label, collapsed: true, items: listed(path) });
        } else if (name.endsWith('.md') && name !== INDEX) {
            entries.push({ slug: slugOf(path) });
        }
    }

    return entries;
}

/** A sidebar group of every page under a directory of `.docs`, in the order `ORDER` gives them. */
export function group(label, directory, collapsed = false) {
    return { label, collapsed, items: items(directory) };
}

/** The contributors' group: the root's pages, the pages of `.docs/contributing`, and the ADRs. */
export function contributing() {
    const [overview, architecture, security] = Object.values(ROOT_PAGES);

    return {
        label: 'Contributing',
        collapsed: true,
        items: [
            { label: 'Overview', slug: overview },
            { slug: architecture },
            ...items('contributing'),
            { slug: security },
            { label: 'Design decisions', collapsed: true, items: listed('decisions') },
        ],
    };
}
