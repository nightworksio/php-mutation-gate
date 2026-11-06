<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\InstalledPackages;

$installed = static fn(string ...$packages): Contents => Contents::of(sprintf(
    '{"packages":[%s],"dev":true}',
    implode(',', array_map(static fn(string $package): string => sprintf('{"name":%s,"version":"1.0.0"}', $package), $packages)),
));

it('digests every package Composer installed but the gate\'s own, whose code the key reads by its sets', function () use ($installed): void {
    $base = InstalledPackages::digestOf($installed('"pestphp/pest"', '"nightworksio/mutation-gate"', '"nightworksio/mutation-gate-default"'));

    expect(InstalledPackages::digestOf($installed('"pestphp/pest"')))->toEqual($base)
        ->and(InstalledPackages::digestOf($installed('"pestphp/pest"', '"nightworksio/other"')))->not->toEqual($base)
        ->and(InstalledPackages::digestOf($installed('"phpunit/phpunit"', '"nightworksio/mutation-gate"')))->not->toEqual($base)
        ->and(InstalledPackages::digestOf($installed('"pestphp/pest"', '7')))->not->toEqual($base);
});

it('digests a list it cannot read as its text, and no list as nothing', function (): void {
    expect(InstalledPackages::digestOf(Contents::of('not json')))->toEqual(Digest::sha256Of('not json'))
        ->and(InstalledPackages::digestOf(Missing::at(Path::of('vendor/composer/installed.json'))))->toEqual(Digest::sha256Of(''))
        ->and(InstalledPackages::digestOf(CannotJudge::because('unreadable')))->toEqual(Digest::sha256Of(''));
});
