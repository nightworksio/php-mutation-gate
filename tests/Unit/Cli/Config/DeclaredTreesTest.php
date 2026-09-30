<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Config\DeclaredTrees;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\DeclaredTree;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Fakes\TreeSourceFake;

it('lays the config\'s trees over those the source finds, and leaves them be where it declares none', function (): void {
    $found = Trees::of(Tree::at(Path::of('src'), Floor::of(50), Package::at(Path::root())));
    $declared = Listed::of(DeclaredTree::of(Path::of('src'), Floor::of(100), Listed::of()));

    expect(new DeclaredTrees(new TreeSourceFake($found), $declared)->trees())
        ->toEqual($found->declaring(...$declared))
        ->and(new DeclaredTrees(new TreeSourceFake($found), Absent::setting())->trees())->toBe($found);
});

it('cannot judge where its source cannot find the trees', function (): void {
    $lost = CannotJudge::because('phpunit.xml cannot be read.');

    expect(new DeclaredTrees(new TreeSourceFake($lost), Listed::of())->trees())->toBe($lost);
});
