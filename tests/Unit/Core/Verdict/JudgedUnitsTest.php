<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\Origin;

$unit = static fn(string $path): JudgedUnit => JudgedUnit::of(Unit::file(Path::of($path)), Origin::Run);
$paths = static fn(JudgedUnits $units): array => array_map(static fn(JudgedUnit $unit): string => $unit->unit()->path()->value(), iterator_to_array($units, preserve_keys: true));

it('holds nothing to begin with', function (): void {
    expect(JudgedUnits::none())->toHaveCount(0);
});

it('keeps units in the order they were added, numbered from nought', function () use ($unit, $paths): void {
    expect($paths(JudgedUnits::of(...['b' => $unit('b'), 'a' => $unit('a')])))->toBe(['b', 'a']);
});

it('adds a unit, or those of another set, without changing the units it came from', function () use ($unit, $paths): void {
    $units = JudgedUnits::of($unit('a'));

    expect($paths($units->with($unit('b'))))->toBe(['a', 'b'])
        ->and($paths($units->and(JudgedUnits::of($unit('c'), $unit('d')))))->toBe(['a', 'c', 'd'])
        ->and($units)->toHaveCount(1);
});
