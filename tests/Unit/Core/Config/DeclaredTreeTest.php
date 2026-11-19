<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\Config\ProjectRoot;
use NightWorksIO\MutationGate\Core\File\Glob;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

it('writes itself as the builder\'s Tree::at, with its floor or why it is exempt, and each glob it excludes', function (): void {
    $origin = ProjectRoot::origin();

    expect([
        DeclaredTree::of(Path::of('app/Http'), Floor::of(83.419), Listed::of(Glob::of('app/Http/Legacy/**'), Glob::of('app/Http/Gen/**')))->php($origin),
        DeclaredTree::of(Path::of('app/Generated'), Exempt::because('Generated on every build'), Listed::of())->php($origin),
        DeclaredTree::of(Path::of('app/Legacy'), Undeclared::floor(), Listed::of())->php($origin),
    ])->toBe([
        "Tree::at('app/Http', floor: 83.42)->excluding(Glob::of('app/Http/Legacy/**'), Glob::of('app/Http/Gen/**'))",
        "Tree::at('app/Generated', floor: 0, because: 'Generated on every build')",
        "Tree::at('app/Legacy')",
    ]);
});
