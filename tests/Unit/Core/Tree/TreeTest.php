<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;

it('is a path with the floor it declares and the package it belongs to', function (): void {
    $tree = Tree::at(Path::of('app/Domain'), Floor::of(80), Package::at(Path::root()));

    expect($tree->path()->value())->toBe('app/Domain')
        ->and($tree->declared())->toEqual(Floor::of(80))
        ->and($tree->package())->toEqual(Package::at(Path::root()));
});
