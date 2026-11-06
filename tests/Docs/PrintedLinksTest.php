<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Term;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;
use NightWorksIO\MutationGate\Tests\Support\Tree;

// ADR-0038: every link the gate prints is a page, and a heading on it, that
// the site builds from `.docs`, and so is every link the README gives into the
// site. Here each link is mapped back to its file and heading; with SITE_BUILD
// naming a built site, as `site.yml` sets it, to the built page and its
// anchor. A link into a copy of the site, `/next/` or a minor's `/X.Y/`, names
// the page that copy builds from the same file.

/** @return list<string> every link a gate of this version prints into its copy of the site */
function printedLinks(string $version = 'dev-main'): array
{
    $guide = Guide::ofInstalled($version);

    return [
        ...array_map(static fn(Slug $slug): string => $guide->link($slug), Slug::cases()),
        ...array_map(static fn(Term $term): string => $guide->term($term), Term::cases()),
    ];
}

/** @return array{string, string} a link's page path on the site, below any copy it names, and its anchor */
function pageAndAnchor(string $link): array
{
    expect($link)->toStartWith(sprintf('%s/', ThisPackage::SITE));

    [$page, $anchor] = explode('#', substr($link, strlen(ThisPackage::SITE)), 2) + ['', ''];

    return [(string) preg_replace('#^/(?:next|\d+\.\d+)(?=/)#', '', $page), $anchor];
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

/** The file a page of the site is built from, by the rule the site's loader follows. */
function sourceOf(string $page): string
{
    $path = trim($page, '/');
    $root = ['contributing' => 'CONTRIBUTING.md', 'contributing/architecture' => 'ARCHITECTURE.md', 'contributing/security' => 'SECURITY.md'];

    return match (true) {
        array_key_exists($path, $root) => $root[$path],
        is_file(Tree::at(sprintf('.docs/%s.md', $path))) => sprintf('.docs/%s.md', $path),
        default => sprintf('.docs/%s/README.md', $path),
    };
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

it('prints links to a file and a heading, by the page each file is built into', function (): void {
    expect(refused(printedLinks(), inDocs(...)))->toBe([], 'A printed link names no file, or no heading in it.');
});

it('prints links of a release, a pre-release included, into its minor\'s copy, and of anything else into next', function (string $version, string $copy): void {
    expect(refused(printedLinks($version), static fn(string $link): bool => str_starts_with($link, sprintf('%s%s/', ThisPackage::SITE, $copy))))
        ->toBe([], 'A printed link names no page of the copy of the site its version is documented in.')
        ->and(refused(printedLinks($version), inDocs(...)))->toBe([], 'A printed link names no file, or no heading in it.');
})->with([
    'a release' => ['v0.1.0', '/0.1'],
    'a pre-release' => ['0.2.0-rc.1', '/0.2'],
    'main' => ['dev-main', '/next'],
]);

it('links the README into the site, to a file and a heading under .docs for each page', function (): void {
    expect(readmeLinks())->not->toBe([])
        ->and(refused(readmeLinks(), inDocs(...)))->toBe([], 'A link of the README into the site names no file, or no heading in it.');
});

it('prints links, and links the README, to pages and anchors the site build produced', function (): void {
    expect(refused([...printedLinks(), ...readmeLinks()], inBuild(...)))
        ->toBe([], 'A link names a page, or an anchor, the site build did not produce.');
})->skip(fn(): bool => getenv('SITE_BUILD') === false, 'No built site is named: SITE_BUILD is set by site.yml.');
