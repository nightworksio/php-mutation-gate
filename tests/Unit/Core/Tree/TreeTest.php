<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;

it('is a path with the floor it declares and the package it belongs to', function (): void {
    $tree = Tree::at(Path::of('app/Domain'), Floor::of(80), Package::at(Path::root()));

    expect($tree->path()->value())->toBe('app/Domain')
        ->and($tree->declared())->toEqual(Floor::of(80))
        ->and($tree->package())->toEqual(Package::at(Path::root()));
});

it('leaves the floor of its new lines to the config unless its manifest declares one', function (): void {
    $tree = Tree::at(Path::of('app/Domain'), Floor::of(80), Package::at(Path::root()));

    expect($tree->newCodeFloor())->toEqual(Undeclared::floor());
});

it('holds its new lines to the floor its manifest declares for them, and keeps the rest', function (): void {
    $tree = Tree::at(Path::of('app/Domain'), Floor::of(80), Package::at(Path::of('packages/app')));
    $declared = $tree->withNewCodeFloor(Floor::of(95));

    expect($declared->newCodeFloor())->toEqual(Floor::of(95))
        ->and($declared->path()->value())->toBe('app/Domain')
        ->and($declared->declared())->toEqual(Floor::of(80))
        ->and($declared->package())->toEqual(Package::at(Path::of('packages/app')))
        ->and($tree->newCodeFloor())->toEqual(Undeclared::floor());
});
