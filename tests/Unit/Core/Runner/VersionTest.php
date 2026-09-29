<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Version;

it('is a package, its exact version and its source reference', function (): void {
    $version = Version::of('pestphp/pest-plugin-mutate', '5.0.2', '7c2e1d4');

    expect($version->package())->toBe('pestphp/pest-plugin-mutate')
        ->and($version->version())->toBe('5.0.2')
        ->and($version->reference())->toBe('7c2e1d4');
});
