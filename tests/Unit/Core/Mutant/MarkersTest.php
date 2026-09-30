<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;

$places = static fn(Markers $markers): array => array_map(
    static fn(Marker $marker): string => $marker->where(),
    iterator_to_array($markers, preserve_keys: true),
);

it('holds nothing to begin with', function (): void {
    expect(Markers::none())->toHaveCount(0);
});

it('keeps markers in the order they were found, numbered from nought', function () use ($places): void {
    $markers = Markers::of(...['b' => Marker::of('b.php:1', 'm', 'r'), 'a' => Marker::of('a.php:1', 'm', 'r')]);

    expect($places($markers))->toBe(['b.php:1', 'a.php:1']);
});

it('adds a marker, and another\'s markers after its own, without changing either', function () use ($places): void {
    $markers = Markers::of(Marker::of('a.php:1', 'm', 'r'));
    $more = Markers::of(Marker::of('c.php:1', 'm', 'r'));

    expect($places($markers->with(Marker::of('b.php:1', 'm', 'r'))))->toBe(['a.php:1', 'b.php:1'])
        ->and($places($markers->merge($more)))->toBe(['a.php:1', 'c.php:1'])
        ->and($markers)->toHaveCount(1)
        ->and($more)->toHaveCount(1);
});
