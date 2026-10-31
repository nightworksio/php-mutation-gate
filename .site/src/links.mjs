import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const HREF = /<a\s[^>]*?href="([^"]*)"/g;

const ID = /\sid="([^"]*)"/g;

function* htmlFiles(directory) {
    for (const name of readdirSync(directory)) {
        const path = join(directory, name);

        if (statSync(path).isDirectory()) {
            yield* htmlFiles(path);
        } else if (name.endsWith('.html')) {
            yield path;
        }
    }
}

function unescape(value) {
    return value.replaceAll('&amp;', '&').replaceAll('&quot;', '"').replaceAll('&#39;', "'");
}

/** The file a path of the site is served from, or none where it is no page and no asset. */
function served(output, path) {
    const file = join(output, path.endsWith('/') ? `${path}index.html` : path);

    try {
        return statSync(file).isFile() ? file : null;
    } catch {
        return null;
    }
}

/** How many links of a built site point into it, and each that points at a page, an asset or an anchor it does not have. */
export function siteLinks(output, base) {
    const ids = new Map();
    const broken = [];
    let checked = 0;
    const anchorsOf = (file) => {
        if (!ids.has(file)) {
            ids.set(file, new Set([...readFileSync(file, 'utf8').matchAll(ID)].map(([, id]) => unescape(id))));
        }

        return ids.get(file);
    };

    for (const page of htmlFiles(output)) {
        for (const [, raw] of readFileSync(page, 'utf8').matchAll(HREF)) {
            const href = unescape(raw);

            if (!href.startsWith(`${base}/`) && !href.startsWith('#')) {
                continue;
            }

            checked += 1;
            const [path, hash] = href.split('?', 1)[0].split(/#(.*)/s, 2);
            const file = path === '' ? page : served(output, path.slice(base.length));

            if (file === null || (hash !== undefined && hash !== '' && file.endsWith('.html') && !anchorsOf(file).has(decodeURIComponent(hash)))) {
                broken.push(`${relative(output, page)}: ${href}`);
            }
        }
    }

    return { checked, broken };
}

/** Fails the build on a broken link into the site, and on a build that holds no link to check. */
export function links(base) {
    return {
        name: 'mutation-gate-links',
        hooks: {
            'astro:build:done': ({ dir, logger }) => {
                const { checked, broken } = siteLinks(fileURLToPath(dir), base);

                if (checked === 0 || broken.length > 0) {
                    throw new Error([`${broken.length} of ${checked} links into the site are broken:`, ...broken].join('\n  '));
                }

                logger.info(`${checked} links into the site, none broken.`);
            },
        },
    };
}
