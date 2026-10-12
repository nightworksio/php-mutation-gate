<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use NightWorksIO\MutationGate\Cli\InstalledGate;

$holds = [
    'holds:src/Cli/InstalledGate.php',
];

it('is the gate Composer installed, at the version and commit it lists', function (): void {
    $version = InstalledGate::version();

    expect($version->package())->toBe('nightworksio/mutation-gate')
        ->and($version->version())->toBe(InstalledVersions::getPrettyVersion('nightworksio/mutation-gate'))
        ->and($version->reference())->toBe(InstalledVersions::getReference('nightworksio/mutation-gate') ?? '')
        ->and($version->version())->not->toBe('');
})->group(...$holds);
