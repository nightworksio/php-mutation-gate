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
