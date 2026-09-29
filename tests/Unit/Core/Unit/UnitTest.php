<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Unit\Unit;

it('is a source file judged by the whole suite', function (): void {
    $unit = Unit::file(Path::of('src/Money.php'));

    expect($unit->path()->value())->toBe('src/Money.php')
        ->and($unit->judgedBy())->toEqual(WholeSuite::tests())
        ->and($unit->isHeld())->toBeFalse();
});

it('is a path judged by the group that holds it', function (): void {
    $unit = Unit::held(Path::of('src/Kernel.php'), Group::named('holds:src/Kernel.php'));

    expect($unit->path()->value())->toBe('src/Kernel.php')
        ->and($unit->judgedBy())->toEqual(Group::named('holds:src/Kernel.php'))
        ->and($unit->isHeld())->toBeTrue();
});

it('is a path judged by the tests a filter selects', function (): void {
    $unit = Unit::held(Path::of('src/Kernel.php'), Filter::matching('KernelTest'));

    expect($unit->judgedBy())->toEqual(Filter::matching('KernelTest'))
        ->and($unit->isHeld())->toBeTrue();
});
