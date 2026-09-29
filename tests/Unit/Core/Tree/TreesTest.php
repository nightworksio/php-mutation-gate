<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
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
