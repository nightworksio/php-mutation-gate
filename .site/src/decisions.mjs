import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DOCS } from './where.mjs';

const ROOT = fileURLToPath(new URL('../', import.meta.url));

const DECISIONS = resolve(ROOT, DOCS, 'decisions');

const README = resolve(DECISIONS, 'README.md');

const SCRIPT = resolve(ROOT, '..', '.github/scripts/decisions_index.py');

const START = '<!-- index:start -->';

const END = '<!-- index:end -->';

const INLINE = /(\[\d{4}\]\([^)\s]+\.md\)|`[^`]+`)/;

const LINK = /^\[(\d{4})\]\(([^)\s]+\.md)\)$/;

function inline(text) {
    return text.split(INLINE).filter((part) => part !== '').map((part) => {
        const link = part.match(LINK);

        if (link !== null) {
            return { type: 'link', url: link[2], children: [{ type: 'text', value: link[1] }] };
        }

        return part.length > 1 && part.startsWith('`') && part.endsWith('`')
            ? { type: 'inlineCode', value: part.slice(1, -1) }
            : { type: 'text', value: part };
    });
}

function row(cells) {
    return { type: 'tableRow', children: cells.map((children) => ({ type: 'tableCell', children })) };
}

function table() {
    const rows = JSON.parse(execFileSync('python3', [SCRIPT, 'rows', DECISIONS], { encoding: 'utf8' }));

    return {
        type: 'table',
        align: [null, null, null],
        children: [
            row([[{ type: 'text', value: '#' }], [{ type: 'text', value: 'Decision' }], [{ type: 'text', value: 'Status' }]]),
            ...rows.map((adr) => row([
                [{ type: 'link', url: adr.file, children: [{ type: 'text', value: adr.number }] }],
                inline(adr.title),
                inline(adr.status),
            ])),
        ],
    };
}

/** Builds the index of the decisions between the markers of their README, which never holds it committed (ADR-0063). */
export function decisionsIndex() {
    return (tree, file) => {
        if (file.path !== README) {
            return;
        }

        const holds = (node, marker) => node.type === 'html' && node.value.includes(marker);
        const start = tree.children.findIndex((node) => holds(node, START));
        const end = tree.children.findIndex((node) => holds(node, END));

        if (start === -1 || end < start) {
            throw new Error(`${README} has no ${START} and ${END} markers to build the index between.`);
        }

        tree.children.splice(start, end - start + 1, table());
    };
}
