<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Loaded;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('holds a file PHP loaded, by its real path where it has one, and by its path as given where it has none', function (): void {
    $root = Scratch::directory();
    Scratch::write($root, 'src/A.php', '<?php');
    $real = sprintf('%s/src/A.php', realpath($root));
    $loaded = Loaded::of([$real, '/nowhere/B.php']);

    expect($loaded->has(sprintf('%s/./src/A.php', $root)))->toBeTrue()
        ->and($loaded->has('/nowhere/B.php'))->toBeTrue()
        ->and($loaded->has('/nowhere/C.php'))->toBeFalse()
        ->and(Loaded::of([])->has($real))->toBeFalse();
});
