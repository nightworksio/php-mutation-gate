<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;

it('is where a marker is, what it says, and the entry that replaces it, in no function', function (): void {
    $marker = Marker::of('infection.json5 mutators.global-ignore', 'App\Money::add', '{"path": "<file>", "reason": "<why>"}');

    expect($marker->where())->toBe('infection.json5 mutators.global-ignore')
        ->and($marker->marker())->toBe('App\Money::add')
        ->and($marker->replacement())->toBe('{"path": "<file>", "reason": "<why>"}')
        ->and($marker->enclosing())->toEqual(Nameless::code());
});

it('is at a file and its line in source, in its function, replaced by an entry per mutant it hides', function (): void {
    $in = Enclosing::named(Path::of('src/Money.php'), 'add');
    $marker = Marker::inSource(Path::of('src/Money.php'), Line::of(12), '@pest-mutate-ignore', $in);

    expect([$marker->where(), $marker->marker(), $marker->replacement(), $marker->enclosing()])->toBe([
        'src/Money.php:12',
        '@pest-mutate-ignore',
        '{"mutant": "<the id of each mutant it hides>", "reason": "<why no test can tell>"}',
        $in,
    ]);
});
