<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\ConfigFile;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\FoundTrees;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/** The trees app, and app/Generated exempt. */
function foundTreesOfApp(): FoundTrees
{
    return FoundTrees::of(Trees::of(
        Tree::at(Path::of('app'), Undeclared::floor(), Package::at(Path::root())),
        Tree::at(Path::of('app/Generated'), Exempt::because('generated'), Package::at(Path::root())),
    ));
}

it('lists the trees under a heading, each exempt one with its reason, its path as the config file writes it', function (): void {
    $file = ConfigFile::at(Path::of('/project/ci/gate.yaml'), Path::of('/project'));

    expect(foundTreesOfApp()->lines($file))->toBe([
        'Zero-config finds these trees, so this file names none:',
        '  ../app',
        '  ../app/Generated (exempt: generated)',
    ]);
});

it('says the trees in one sentence, their paths from the project', function (): void {
    expect(foundTreesOfApp()->said())
        ->toBe('Zero-config finds the trees app and app/Generated (exempt: generated), so the config names none.');
});
