<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Term;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// ADR-0038: every link the gate prints is a page, and a heading on it, that
// the site builds from `.docs`, and so is every link the README gives into the
// site. Here each link is mapped back to its file and heading under `.docs`;
// with SITE_BUILD naming a built site, as `site.yml` sets it, to the built
// page and its anchor.

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

/** @return list<string> every link the README gives into the site */
function readmeLinks(): array
{
    preg_match_all(
        sprintf('#\]\((?<link>%s/[^)\s]*)\)#', preg_quote(ThisPackage::SITE, '#')),
        (string) file_get_contents(Tree::at('README.md')),
        $linked,
    );

    return $linked['link'];
}

/** Whether a link names a file under `.docs`, and the heading its anchor names where it names one. */
function inDocs(string $link): bool
{
    [$page, $anchor] = pageAndAnchor($link);
    $source = Tree::at(sourceOf($page));

    return is_file($source) && ($anchor === '' || in_array($anchor, headingAnchors((string) file_get_contents($source)), strict: true));
}

/** Whether a link names a page the site build produced, and the anchor it names where it names one. */
function inBuild(string $link): bool
{
    [$page, $anchor] = pageAndAnchor($link);
    $file = sprintf('%s%sindex.html', rtrim((string) getenv('SITE_BUILD'), '/'), $page);

    return is_file($file) && ($anchor === '' || str_contains((string) file_get_contents($file), sprintf(' id="%s"', $anchor)));
}

/**
 * The links of a list a check refuses.
 *
 * @param  list<string>           $links
 * @param  Closure(string): bool $holds
 * @return list<string>
 */
function refused(array $links, Closure $holds): array
{
    return array_values(array_filter($links, static fn(string $link): bool => ! $holds($link)));
}

it('prints links that each name a page with a trailing slash and a heading on it', function (): void {
    expect(refused(printedLinks(), static function (string $link): bool {
        [$page, $anchor] = pageAndAnchor($link);

        return str_ends_with($page, '/') && $anchor !== '';
    }))->toBe([], 'A printed link names no page with a trailing slash, or no heading on it.');
});

it('prints links to a file and a heading under .docs, by the page each file is built into', function (): void {
    expect(refused(printedLinks(), inDocs(...)))->toBe([], 'A printed link names no file under .docs, or no heading in it.');
});

it('links the README into the site, to a file and a heading under .docs for each page', function (): void {
    expect(readmeLinks())->not->toBe([])
        ->and(refused(readmeLinks(), inDocs(...)))->toBe([], 'A link of the README into the site names no file under .docs, or no heading in it.');
});

it('prints links, and links the README, to pages and anchors the site build produced', function (): void {
    expect(refused([...printedLinks(), ...readmeLinks()], inBuild(...)))
        ->toBe([], 'A link names a page, or an anchor, the site build did not produce.');
})->skip(fn(): bool => getenv('SITE_BUILD') === false, 'No built site is named: SITE_BUILD is set by site.yml.');
