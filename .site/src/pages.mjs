import { readFile } from 'node:fs/promises';
import { glob } from 'astro/loaders';
import { DOCS, SECTIONS, slugOf } from './where.mjs';

/** A page's title: its first heading, as plain text. */
export function titleOf(markdown) {
    const heading = markdown.match(/^# (.+)$/m);

    if (heading === null) {
        throw new Error('A page has no first heading to take its title from.');
    }

    return heading[1].replaceAll('`', '').trim();
}

/** The pages of `.docs`, read where they stand, each titled by its first heading. */
export function pages() {
    const files = glob({
        pattern: `{${SECTIONS.join(',')}}/**/*.md`,
        base: DOCS,
        generateId: ({ entry }) => slugOf(entry),
    });

    return {
        name: 'mutation-gate-docs',
        load: (context) => files.load({
            ...context,
            parseData: async (entry) => context.parseData({
                ...entry,
                data: { title: titleOf(await readFile(entry.filePath, 'utf8')), ...entry.data },
            }),
        }),
    };
}
