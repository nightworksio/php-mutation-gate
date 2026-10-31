<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\Term;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

const GUIDE_AT = 'https://nightworksio.github.io/php-mutation-gate%s/guide/troubleshooting/#no-tree';

it('links a slug into the site\'s copy of the minor Composer installed, or into main\'s pages for anything else', function (string $installed, string $copy): void {
    expect(Guide::ofInstalled($installed)->link(Slug::NoTree))->toBe(sprintf(GUIDE_AT, $copy));
})->with([
    'a tag with its v' => ['v1.4.2', '/1.4'],
    'a version without it' => ['1.4.2', '/1.4'],
    'a pre-release' => ['2.0.0-rc.1', '/2.0'],
    'a branch' => ['dev-main', ''],
    'a partial version' => ['1.4', ''],
]);

it('reads the gate\'s version from what Composer installed, and links main\'s pages where it lists no gate', function (): void {
    $installed = static fn(string $json): Installed|CannotJudge => Installed::decode(Contents::of($json), Path::of('vendor/composer/installed.json'));
    $listed = $installed('{"packages": [{"name": "nightworksio/mutation-gate", "version": "1.2.0"}]}');
    $unlisted = $installed('{"packages": [{"name": "pestphp/pest", "version": "4.0.0"}]}');

    expect($listed instanceof Installed ? Guide::installedIn($listed)->link(Slug::NoTree) : '')->toBe(sprintf(GUIDE_AT, '/1.2'))
        ->and($unlisted instanceof Installed ? Guide::installedIn($unlisted)->link(Slug::NoTree) : '')->toBe(sprintf(GUIDE_AT, ''))
        ->and(Guide::unreleased()->link(Slug::NoTree))->toBe(sprintf(GUIDE_AT, ''));
});

it('links a term into the guide to reading the report in the site\'s copy of the minor Composer installed, or in main\'s pages', function (): void {
    expect(Guide::ofInstalled('v1.4.2')->term(Term::TimedOut))
        ->toBe('https://nightworksio.github.io/php-mutation-gate/1.4/guide/reading-the-report/#timed-out')
        ->and(Guide::unreleased()->term(Term::Floor))
        ->toBe('https://nightworksio.github.io/php-mutation-gate/guide/reading-the-report/#floor');
});
