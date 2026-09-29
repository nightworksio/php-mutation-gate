<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Invocations;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

$paths = static fn(Invocations $invocations): array => array_map(
    static fn(Units $units): array => array_map(
        static fn(Unit $unit): string => $unit->path()->value(),
        iterator_to_array($units, preserve_keys: false),
    ),
    iterator_to_array($invocations, preserve_keys: true),
);

it('runs each held unit alone, first, then the other units together', function () use ($paths): void {
    $invocations = Invocations::of(Units::of(
        Unit::file(Path::of('src/A.php')),
        Unit::held(Path::of('src/Kernel.php'), Group::named('holds:kernel')),
        Unit::file(Path::of('src/B.php')),
        Unit::held(Path::of('src/Boot.php'), Filter::matching('BootTest')),
    ));

    expect($paths($invocations))->toBe([['src/Kernel.php'], ['src/Boot.php'], ['src/A.php', 'src/B.php']])
        ->and($invocations)->toHaveCount(3);
});

it('makes no invocation for the other units where every unit is held', function () use ($paths): void {
    $invocations = Invocations::of(Units::of(Unit::held(Path::of('src/Kernel.php'), Group::named('holds:kernel'))));

    expect($paths($invocations))->toBe([['src/Kernel.php']]);
});

it('runs a single unit that is not held in one invocation', function () use ($paths): void {
    expect($paths(Invocations::of(Units::of(Unit::file(Path::of('src/A.php'))))))->toBe([['src/A.php']]);
});

it('makes no invocation for no units', function (): void {
    expect(Invocations::of(Units::none()))->toHaveCount(0);
});
