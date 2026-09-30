<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// ADR-0018, decision 8: every slug has a section of the troubleshooting guide,
// and every section a slug, so no printed link lands nowhere.

/** @return list<string> every section the guide heads with `## `, in order */
function troubleshootingSections(): array
{
    preg_match_all('/^## (?<slug>.+)$/mu', (string) file_get_contents(Tree::at('.docs/guide/troubleshooting.md')), $headed);

    return $headed['slug'];
}

it('has a section for every slug', function (): void {
    $slugs = array_map(static fn(Slug $slug): string => $slug->value, Slug::cases());

    expect(array_values(array_diff($slugs, troubleshootingSections())))->toBe([], 'A slug has no section in .docs/guide/troubleshooting.md.');
});

it('has a slug for every section', function (): void {
    $slugs = array_map(static fn(Slug $slug): string => $slug->value, Slug::cases());

    expect(array_values(array_diff(troubleshootingSections(), $slugs)))->toBe([], 'A section of .docs/guide/troubleshooting.md names no slug.');
});
