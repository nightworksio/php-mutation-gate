<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Outside;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

$tree = static fn(string $path): Tree => Tree::at(Path::of($path), Undeclared::floor(), Package::at(Path::root()));
$paths = static fn(Trees $trees): array => array_map(static fn(Tree $tree): string => $tree->path()->value(), iterator_to_array($trees, preserve_keys: true));

it('holds nothing to begin with', function (): void {
    expect(Trees::none())->toHaveCount(0);
});

it('keeps trees in the order they came, numbered from nought', function () use ($tree, $paths): void {
    expect($paths(Trees::of(...['second' => $tree('src/B'), 'first' => $tree('src/A')])))->toBe(['src/B', 'src/A']);
});

it('adds a tree without changing the trees it came from', function () use ($tree, $paths): void {
    $trees = Trees::of($tree('src/A'));

    expect($paths($trees->with($tree('src/B'))))->toBe(['src/A', 'src/B'])
        ->and($trees)->toHaveCount(1);
});

it('answers the innermost tree that holds a path, whatever order the trees came in', function (string $path, string $holding) use ($tree): void {
    $outer = $tree('app');
    $inner = $tree('app/Http');
    $other = $tree('src');

    foreach ([Trees::of($outer, $inner, $other), Trees::of($inner, $other, $outer)] as $trees) {
        expect($trees->holding(Path::of($path)))->toBe(['app' => $outer, 'app/Http' => $inner, 'src' => $other][$holding]);
    }
})->with([
    'a file of the outer tree' => ['app/Kernel.php', 'app'],
    'a file of the inner tree' => ['app/Http/Controller.php', 'app/Http'],
    'the inner tree itself' => ['app/Http', 'app/Http'],
    'a file of another tree' => ['src/Money.php', 'src'],
    'a sibling that only starts like the inner tree' => ['app/Https.php', 'app'],
]);

it('answers that no tree holds a path outside them all', function () use ($tree): void {
    expect(Trees::of($tree('app'))->holding(Path::of('lib/Money.php')))->toEqual(Outside::trees())
        ->and(Trees::none()->holding(Path::of('app/Money.php')))->toEqual(Outside::trees());
});

it('lays a declared floor and exclude over the tree of the same path, keeping its package and new-code floor', function (): void {
    $package = Package::at(Path::of('packages/money'));
    $found = Tree::at(Path::of('src'), Floor::of(50), $package)->withNewCodeFloor(Floor::of(90));
    $declared = DeclaredTree::of(Path::of('src'), Floor::of(80), Listed::of(Glob::of('src/Generated/**')));
    $laid = [...Trees::of($found, Tree::at(Path::of('lib'), Floor::of(10), $package))->declaring($declared)];

    expect($laid)->toHaveCount(2)
        ->and($laid[0]->declared())->toEqual(Floor::of(80))
        ->and($laid[0]->package())->toBe($package)
        ->and($laid[0]->newCodeFloor())->toEqual(Floor::of(90))
        ->and($laid[0]->excludes(Path::of('src/Generated/Money.php')))->toBeTrue()
        ->and($laid[1]->declared())->toEqual(Floor::of(10));
});

it('makes a declared path no tree has a tree of the package that holds it, or of the root', function (): void {
    $package = Package::at(Path::of('packages/money'));
    $exempt = Exempt::because('Generated code.');
    $laid = [...Trees::of(Tree::at(Path::of('packages/money'), Floor::of(50), $package))->declaring(
        DeclaredTree::of(Path::of('packages/money/src/Legacy'), $exempt, Listed::of()),
        DeclaredTree::of(Path::of('tools'), Floor::of(30), Listed::of()),
    )];

    expect(array_map(static fn(Tree $tree): string => $tree->path()->value(), $laid))
        ->toBe(['packages/money', 'packages/money/src/Legacy', 'tools'])
        ->and($laid[1]->declared())->toBe($exempt)
        ->and($laid[1]->package())->toBe($package)
        ->and($laid[2]->package())->toEqual(Package::at(Path::root()));
});

it('answers that no tree holds a file a tree excludes', function (): void {
    $trees = Trees::of(Tree::at(Path::of('src'), Floor::of(50), Package::at(Path::root())))->declaring(
        DeclaredTree::of(Path::of('src'), Floor::of(50), Listed::of(Glob::of('src/Generated/**'))),
    );

    expect($trees->holding(Path::of('src/Generated/Money.php')))->toEqual(Outside::trees())
        ->and($trees->holding(Path::of('src/Money.php')))->toBeInstanceOf(Tree::class);
});
