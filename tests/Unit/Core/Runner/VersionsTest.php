<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;

$spelt = static fn(Versions $versions): array => array_map(static fn(Version $version): string => sprintf('%s %s', $version->package(), $version->version()), iterator_to_array($versions, preserve_keys: true));

it('holds nothing to begin with', function (): void {
    expect(Versions::none())->toHaveCount(0);
});

it('keeps one version per package, the later replacing the earlier', function () use ($spelt): void {
    $versions = Versions::of(Version::of('pestphp/pest', '5.1.0', 'a'), Version::of('phpunit/phpunit', '13.3.4', 'b'), Version::of('pestphp/pest', '5.2.1', 'c'));

    expect($spelt($versions))->toBe(['pestphp/pest 5.2.1', 'phpunit/phpunit 13.3.4'])
        ->and($versions)->toHaveCount(2);
});

it('adds a version without changing the versions it came from', function (): void {
    $versions = Versions::none();

    expect($versions->with(Version::of('pestphp/pest', '5.2.1', 'c')))->toHaveCount(1)
        ->and($versions)->toHaveCount(0);
});
