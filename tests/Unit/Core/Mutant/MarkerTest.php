<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\Marker;

it('is where a marker is, what it says, and the entry that replaces it', function (): void {
    $marker = Marker::of('src/Money.php:12', '@infection-ignore-all', '{"mutant": "<id>", "reason": "<why>"}');

    expect($marker->where())->toBe('src/Money.php:12')
        ->and($marker->marker())->toBe('@infection-ignore-all')
        ->and($marker->replacement())->toBe('{"mutant": "<id>", "reason": "<why>"}');
});

it('replaces a marker in source with an entry per mutant it hides', function (): void {
    $marker = Marker::inSource('src/Money.php:12', '@pest-mutate-ignore');

    expect([$marker->where(), $marker->marker(), $marker->replacement()])->toBe([
        'src/Money.php:12',
        '@pest-mutate-ignore',
        '{"mutant": "<the id of each mutant it hides>", "reason": "<why no test can tell>"}',
    ]);
});
