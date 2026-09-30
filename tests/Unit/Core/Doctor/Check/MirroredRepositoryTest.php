<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Doctor\Check\MirroredRepository;
use NightWorksIO\MutationGate\Core\Doctor\ComposerSetup;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

$trees = static fn(string ...$paths): Trees => Trees::of(...array_map(
    static fn(string $path): Tree => Tree::at(Path::of($path), Undeclared::floor(), Package::at(Path::root())),
    $paths,
));

it('finds each tree a path repository copies into the vendor directory', function () use ($trees): void {
    $observed = Observations::none()
        ->withTrees($trees('src', 'packages/money/src', 'packages/clock', 'packages/moneyish/src'))
        ->withFiles(ProjectFiles::none()->withComposer(ComposerSetup::of(Paths::of(Path::of('packages/money'), Path::of('packages/clock'), Path::of('libs/tax')))));

    expect(MirroredRepository::in($observed))->toEqual(Findings::of(Finding::of(
        Slug::MirroredPathRepository,
        Severity::WillFail,
        'composer.json copies packages/money into the vendor directory, "symlink": false, with the tree packages/money/src; '
            . 'composer.json copies packages/clock into the vendor directory, "symlink": false, with the tree packages/clock.',
        'The tests load the copy, so a mutant of the tree changes code no test runs, and every one survives.',
        'Remove "symlink": false from that path repository\'s options, then run composer update for its packages.',
    )));
});

it('finds nothing where no copied repository holds a tree, or either was not observed', function () use ($trees): void {
    $copied = ComposerSetup::of(Paths::of(Path::of('libs/tax')));

    expect(MirroredRepository::in(Observations::none()->withTrees($trees('src'))->withFiles(ProjectFiles::none()->withComposer($copied))))->toEqual(Findings::none())
        ->and(MirroredRepository::in(Observations::none()->withFiles(ProjectFiles::none()->withComposer($copied))))->toEqual(Findings::none())
        ->and(MirroredRepository::in(Observations::none()->withTrees($trees('libs/tax'))))->toEqual(Findings::none());
});
