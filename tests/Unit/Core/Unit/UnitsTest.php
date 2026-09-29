<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

$paths = static fn(Units $units): array => array_map(static fn(Unit $unit): string => $unit->path()->value(), iterator_to_array($units, preserve_keys: true));

it('holds nothing to begin with', function (): void {
    expect(Units::none())->toHaveCount(0);
});

it('keeps units in the order they came, numbered from nought', function () use ($paths): void {
    expect($paths(Units::of(...['b' => Unit::file(Path::of('src/B.php')), 'a' => Unit::file(Path::of('src/A.php'))])))->toBe(['src/B.php', 'src/A.php']);
});

it('adds a unit without changing the units it came from', function () use ($paths): void {
    $units = Units::of(Unit::file(Path::of('src/A.php')));

    expect($paths($units->with(Unit::file(Path::of('src/B.php')))))->toBe(['src/A.php', 'src/B.php'])
        ->and($units)->toHaveCount(1);
});
