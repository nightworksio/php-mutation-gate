<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
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
