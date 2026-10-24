<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Term;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// ADR-0009: every term the reports explain has a section of the guide to
// reading the report, so no "what is this?" links nowhere.

/** @return list<string> the anchor GitHub gives each section the guide heads with `## `, in order */
function readingSections(): array
{
    preg_match_all('/^## (?<heading>.+)$/mu', (string) file_get_contents(Tree::at('.docs/guide/reading-the-report.md')), $headed);

    return array_map(
        static fn(string $heading): string => str_replace(' ', '-', (string) preg_replace('/[^a-z0-9 -]/', '', mb_strtolower($heading))),
        $headed['heading'],
    );
}

it('has a section for every term the reports explain', function (): void {
    $terms = array_map(static fn(Term $term): string => $term->value, Term::cases());

    expect(array_values(array_diff($terms, readingSections())))->toBe([], 'A term has no section in .docs/guide/reading-the-report.md.');
});

it('has a section on the digest, which the reference links to', function (): void {
    expect(readingSections())->toContain('the-digest-for-agents')
        ->and((string) file_get_contents(Tree::at('.docs/reference/reports.md')))->toContain('reading-the-report.md#the-digest-for-agents');
});
