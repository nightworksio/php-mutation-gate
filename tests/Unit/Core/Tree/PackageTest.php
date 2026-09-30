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
