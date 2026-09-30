<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Tree\Package;

it('is the project at a path', function (): void {
    expect(Package::at(Path::of('packages/money'))->path()->value())->toBe('packages/money');
});

it('depends on nothing to begin with', function (): void {
    expect(Package::at(Path::of('packages/money'))->dependencies())->toEqual(Paths::none());
});

it('depends on each package it is said to, and leaves the package it came from as it was', function (): void {
    $package = Package::at(Path::of('packages/money'));
    $depending = $package->dependingOn(Path::of('packages/core'))->dependingOn(Path::of('packages/clock'));

    expect($depending->dependencies())->toEqual(Paths::of(Path::of('packages/core'), Path::of('packages/clock')))
        ->and($depending->path()->value())->toBe('packages/money')
        ->and($package->dependencies())->toEqual(Paths::none());
});

it('finds the innermost package that holds a path, and the root where none other does', function (): void {
    $root = Package::at(Path::root())->dependingOn(Path::of('packages/money'));
    $money = Package::at(Path::of('packages/money'));
    $rates = Package::at(Path::of('packages/money/rates'));

    expect(Package::holding(Path::of('packages/money/rates/src/Rate.php'), $root, $rates, $money))->toBe($rates)
        ->and(Package::holding(Path::of('packages/money/src/Money.php'), $rates, $money, $root))->toBe($money)
        ->and(Package::holding(Path::of('packages/moneybox/src/Box.php'), $money, $root))->toBe($root)
        ->and(Package::holding(Path::of('src/Kernel.php'), $money))->toEqual(Package::at(Path::root()));
});
