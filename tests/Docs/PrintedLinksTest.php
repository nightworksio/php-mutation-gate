<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Term;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// ADR-0038: every link the gate prints is a page, and a heading on it, that
// the site builds from `.docs`. Here each link is mapped back to its file and
// heading under `.docs`; with SITE_BUILD naming a built site, as `site.yml`
// sets it, to the built page and its anchor.

/** @return list<string> every link the gate prints into main's pages */
function printedLinks(): array
{
    $guide = Guide::unreleased();

    return [
        ...array_map(static fn(Slug $slug): string => $guide->link($slug), Slug::cases()),
        ...array_map(static fn(Term $term): string => $guide->term($term), Term::cases()),
    ];
}

/** @return array{string, string} a printed link's page path on the site, and its anchor */
function pageAndAnchor(string $link): array
{
    expect($link)->toStartWith(sprintf('%s/', ThisPackage::SITE));

    [$page, $anchor] = explode('#', substr($link, strlen(ThisPackage::SITE)), 2) + ['', ''];

    return [$page, $anchor];
}

/** @return list<string> the anchor each heading of a Markdown file gets, on GitHub and on the site */
function headingAnchors(string $markdown): array
{
    preg_match_all('/^#{1,6} (?<heading>.+)$/mu', $markdown, $headed);

    return array_map(
        static fn(string $heading): string => str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N} _-]/u', '', mb_strtolower(str_replace('`', '', $heading)))),
        $headed['heading'],
    );
}

/** The file under `.docs` a page of the site is built from, by the rule the site's loader follows. */
function sourceOf(string $page): string
{
    $path = trim($page, '/');

    return is_file(Tree::at(sprintf('.docs/%s.md', $path))) ? sprintf('.docs/%s.md', $path) : sprintf('.docs/%s/README.md', $path);
}

it('prints links that each name a page with a trailing slash and a heading on it', function (): void {
    $wrong = [];

    foreach (printedLinks() as $link) {
        [$page, $anchor] = pageAndAnchor($link);
        $wrong[] = str_ends_with($page, '/') && $anchor !== '' ? '' : $link;
    }

    expect(array_values(array_filter($wrong, static fn(string $link): bool => $link !== '')))->toBe([], 'A printed link names no page with a trailing slash, or no heading on it.');
});

it('prints links to a file and a heading under .docs, by the page each file is built into', function (): void {
    $wrong = [];

    foreach (printedLinks() as $link) {
        [$page, $anchor] = pageAndAnchor($link);
        $source = sourceOf($page);
        $wrong[] = is_file(Tree::at($source)) && in_array($anchor, headingAnchors((string) file_get_contents(Tree::at($source))), strict: true) ? '' : $link;
    }

    expect(array_values(array_filter($wrong, static fn(string $link): bool => $link !== '')))->toBe([], 'A printed link names no file under .docs, or no heading in it.');
});

it('prints links to a page and an anchor the site build produced', function (): void {
    $built = rtrim((string) getenv('SITE_BUILD'), '/');
    $wrong = [];

    foreach (printedLinks() as $link) {
        [$page, $anchor] = pageAndAnchor($link);
        $file = sprintf('%s%sindex.html', $built, $page);
        $wrong[] = is_file($file) && str_contains((string) file_get_contents($file), sprintf(' id="%s"', $anchor)) ? '' : $link;
    }

    expect(array_values(array_filter($wrong, static fn(string $link): bool => $link !== '')))->toBe([], 'A printed link names a page, or an anchor, the site build did not produce.');
})->skip(fn(): bool => getenv('SITE_BUILD') === false, 'No built site is named: SITE_BUILD is set by site.yml.');
