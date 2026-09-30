<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\Holdable;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

$holdable = static fn(): Holdable => Holdable::in(
    Trees::of(
        Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())),
        Tree::at(Path::of('lib/Empty'), Undeclared::floor(), Package::at(Path::root())),
    ),
    Fingerprints::of(
        Fingerprint::of(Path::of('src/Kernel.php'), Digest::of('a1')),
        Fingerprint::of(Path::of('src/Http/Controller.php'), Digest::of('b2')),
        Fingerprint::of(Path::of('app/Kernel.php'), Digest::of('c3')),
    ),
);

it('holds a tree, whether or not anything is in it', function () use ($holdable): void {
    expect($holdable()->has(Path::of('src')))->toBeTrue()
        ->and($holdable()->has(Path::of('lib/Empty')))->toBeTrue();
});

it('holds a file or a directory on disk inside a tree', function () use ($holdable): void {
    expect($holdable()->has(Path::of('src/Kernel.php')))->toBeTrue()
        ->and($holdable()->has(Path::of('src/Http')))->toBeTrue();
});

it('holds nothing that is not on disk inside a tree', function () use ($holdable): void {
    expect($holdable()->has(Path::of('src/Kenrel.php')))->toBeFalse()
        ->and($holdable()->has(Path::of('lib/Empty/Gone.php')))->toBeFalse()
        ->and($holdable()->has(Path::of('app/Kernel.php')))->toBeFalse()
        ->and($holdable()->has(Path::of('app')))->toBeFalse();
});
