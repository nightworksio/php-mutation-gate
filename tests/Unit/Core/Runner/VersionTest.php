<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Version;

it('is a package, its exact version and its source reference', function (): void {
    $version = Version::of('pestphp/pest-plugin-mutate', '5.0.2', '7c2e1d4');

    expect($version->package())->toBe('pestphp/pest-plugin-mutate')
        ->and($version->version())->toBe('5.0.2')
        ->and($version->reference())->toBe('7c2e1d4');
});

it('is a release where its version is a tag, and not where Composer installed a branch', function (
    string $version,
    bool $release,
): void {
    expect(Version::of('nightworksio/mutation-gate', $version, '0123abcd')->isRelease())->toBe($release);
})->with([
    'a tag' => ['v1.2.0', true],
    'a tag without its v' => ['1.2.0', true],
    'a branch' => ['dev-main', false],
    'an aliased branch' => ['2.x-dev', false],
    'no version' => ['', false],
]);

it('is spelt by its tag where it is a release, and by its branch and commit where it is not', function (string $version, string $reference, string $spelt): void {
    expect(Version::of('nightworksio/mutation-gate', $version, $reference)->spelt())->toBe($spelt);
})->with([
    'a release' => ['0.1.0', 'a1b2c3d', '0.1.0'],
    'a branch' => ['dev-main', 'a1b2c3d', 'dev-main a1b2c3d'],
    'an aliased branch' => ['0.x-dev', 'a1b2c3d', '0.x-dev a1b2c3d'],
    'a branch with no commit known' => ['dev-main', '', 'dev-main'],
    'nothing known' => ['', '', ''],
]);

it('is a number as a release, its tag without the v', function (string $version, string $release): void {
    expect(Version::of('phpunit/phpunit', $version, 'abc')->release())->toBe($release);
})->with([['v13.3.0', '13.3.0'], ['13.3.0', '13.3.0'], ['dev-main', 'dev-main']]);
