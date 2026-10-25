<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Packages;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

$tree = static fn(string $path, Package $package): Tree => Tree::at(Path::of($path), Undeclared::floor(), $package);

it('places a path in the innermost package around it, and in the root outside every other', function () use ($tree): void {
    $packages = Packages::of(Trees::of(
        $tree('packages/money/plugins/src', Package::at(Path::of('packages/money/plugins'))),
        $tree('packages/money/src', Package::at(Path::of('packages/money'))),
        $tree('src', Package::at(Path::root())),
    ));

    expect($packages->holding(Path::of('packages/money/plugins/src/Stripe.php'))->path()->value())->toBe('packages/money/plugins')
        ->and($packages->holding(Path::of('packages/money/src/Money.php'))->path()->value())->toBe('packages/money')
        ->and($packages->holding(Path::of('packages/moneyed/src/Money.php'))->path()->value())->toBe('.')
        ->and($packages->holding(Path::of('src/Kernel.php'))->path()->value())->toBe('.');
});

it('knows the root even when no tree is in it', function () use ($tree): void {
    $packages = Packages::of(Trees::of($tree('packages/money/src', Package::at(Path::of('packages/money')))));

    expect($packages->holding(Path::of('README.md')))->toEqual(Package::at(Path::root()));
});

it('reaches a package and every package that depends on it, directly or through others', function () use ($tree): void {
    $core = Package::at(Path::of('packages/core'));
    $packages = Packages::of(Trees::of(
        $tree('src', Package::at(Path::root())->dependingOn(Path::of('packages/money'))),
        $tree('packages/core/src', $core),
        $tree('packages/money/src', Package::at(Path::of('packages/money'))->dependingOn(Path::of('packages/core'))),
        $tree('packages/shop/src', Package::at(Path::of('packages/shop'))->dependingOn(Path::of('packages/core'))),
        $tree('packages/ledger/src', Package::at(Path::of('packages/ledger'))->dependingOn(Path::of('packages/money'))),
        $tree('packages/audit/src', Package::at(Path::of('packages/audit'))->dependingOn(Path::of('packages/shop'))),
        $tree('packages/clock/src', Package::at(Path::of('packages/clock'))),
    ));

    expect(array_map(static fn(Path $path): string => $path->value(), [...$packages->withDependents($core)]))->toBe([
        'packages/core',
        'packages/money',
        'packages/shop',
        'packages/audit',
        '.',
        'packages/ledger',
    ]);
});

it('reaches a package that nothing depends on alone', function () use ($tree): void {
    $clock = Package::at(Path::of('packages/clock'));

    expect(Packages::of(Trees::of($tree('packages/clock/src', $clock)))->withDependents($clock))->toEqual(Paths::of(Path::of('packages/clock')));
});

it('reaches each package once where two depend on each other', function () use ($tree): void {
    $money = Package::at(Path::of('packages/money'))->dependingOn(Path::of('packages/core'));
    $packages = Packages::of(Trees::of(
        $tree('packages/money/src', $money),
        $tree('packages/core/src', Package::at(Path::of('packages/core'))->dependingOn(Path::of('packages/money'))),
    ));

    expect($packages->withDependents($money))->toEqual(Paths::of(Path::of('packages/money'), Path::of('packages/core')));
});

it('places a path in, and reaches, a package whatever its directory is named', function () use ($tree): void {
    $path = Package::at(Path::of('path'));
    $paths = Package::at(Path::of('paths'))->dependingOn(Path::of('path'));
    $packages = Packages::of(Trees::of($tree('path/src', $path), $tree('paths/src', $paths)));

    expect($packages->holding(Path::of('path/src/Money.php')))->toEqual($path)
        ->and($packages->withDependents($path))->toEqual(Paths::of(Path::of('path'), Path::of('paths')));
});
