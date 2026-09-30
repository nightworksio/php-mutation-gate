<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Composer\ComposerTrees;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Port\TreeSource;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;
use NightWorksIO\MutationGate\Tests\Support\Project;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

// What every tree source answers over the fixture: src/Domain declares 100,
// src/Http declares nothing and src/Generated declares 0 with a reason. One
// line per implementation.

$sources = [
    'the fake' => fn(): TreeSource => TreeSourceFake::ofTheFixture(),
    'the manifests' => fn(): TreeSource => ComposerTrees::at(Project::ofTheFixture(), ['composer.json'], []),
];

afterEach(function (): void {
    Scratch::sweep();
});

it('finds every tree once, with the floor each declares', function (TreeSource $source): void {
    $trees = $source->trees();
    $found = [];

    foreach ($trees instanceof Trees ? $trees : [] as $tree) {
        $found[$tree->path()->value()] = $tree->declared();
    }

    expect($trees instanceof Trees ? $trees->count() : 0)->toBe(3)
        ->and($found)->toEqual([
            'src/Domain' => Floor::of(100),
            'src/Http' => Undeclared::floor(),
            'src/Generated' => Exempt::because('Generated on every build'),
        ]);
})->with($sources);

it('places every tree in a package', function (TreeSource $source): void {
    $trees = $source->trees();
    $packages = $trees instanceof Trees ? array_map(static fn(Tree $tree): string => $tree->package()->path()->value(), iterator_to_array($trees, preserve_keys: true)) : [];

    expect($packages)->toBe(['.', '.', '.']);
})->with($sources);
