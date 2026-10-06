<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\Group;
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

it('says whether one of its units is at a path', function (): void {
    $units = Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php')));

    expect($units->has(Path::of('src/B.php')))->toBeTrue()
        ->and($units->has(Path::of('src/C.php')))->toBeFalse()
        ->and($units->has(Path::of('src')))->toBeFalse();
});

it('leaves out the units at the paths of others', function () use ($paths): void {
    $units = Units::of(Unit::file(Path::of('src/A.php')), Unit::file(Path::of('src/B.php')), Unit::file(Path::of('src/C.php')));

    expect($paths($units->except(Units::of(Unit::file(Path::of('src/B.php'))))))->toBe(['src/A.php', 'src/C.php'])
        ->and($paths($units->except(Units::none())))->toBe(['src/A.php', 'src/B.php', 'src/C.php']);
});

it('counts the units their holding tests judge', function (): void {
    $units = Units::of(
        Unit::file(Path::of('src/A.php')),
        Unit::held(Path::of('src/Http'), Group::named('holds:src/Http')),
    );

    expect($units->held())->toBe(1)
        ->and(Units::none()->held())->toBe(0);
});
