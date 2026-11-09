<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Modules;

$paths = static fn(string ...$paths): Paths => Paths::of(...array_map(Path::of(...), $paths));
$values = static fn(Paths $paths): array => array_map(static fn(Path $path): string => $path->value(), [...$paths]);

it('finds a module in each directory with a composer.json of its own that is no package and not the root', function () use ($paths, $values): void {
    $listed = $paths(
        'composer.json',
        'src/Money.php',
        'app-modules/billing/composer.json',
        'app-modules/billing/src/Invoice.php',
        'app-modules/billing/composer.json.dist',
        'packages/sdk/composer.json',
        'app-modules/shop/notcomposer.json',
    );

    expect($values(Modules::among($listed, $paths('.', 'packages/sdk'))))->toBe(['app-modules/billing'])
        ->and($values(Modules::among($listed, Paths::none())))->toBe(['app-modules/billing', 'packages/sdk']);
});
