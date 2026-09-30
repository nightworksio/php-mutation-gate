<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Doctor\Check\TreesFound;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

$none = static fn(string $found): Findings => Findings::of(Finding::of(
    Slug::NoTree,
    Severity::WillFail,
    $found,
    'A tree is what the gate mutates and holds to a floor, so with none a run judges nothing.',
    'Name one in the config, as trees: [{path: src}], or declare a psr-4 autoload path in composer.json.',
));

it('finds no tree, or says why the tree source found none', function () use ($none): void {
    expect(TreesFound::in(Observations::none()->withTrees(Trees::none())))
        ->toEqual($none('The tree source finds no tree to mutate.'))
        ->and(TreesFound::in(Observations::none()->withTrees(CannotJudge::because('composer.json is not JSON.'))))
        ->toEqual($none('composer.json is not JSON.'))
        ->and(TreesFound::in(Observations::none()->withTrees(Invalid::because(Problem::at('trees', 'src is not there.'), Problem::at('trees[1]', 'lib is not there.')))))
        ->toEqual($none('src is not there. lib is not there.'));
});

it('finds nothing where a tree is found, or nothing was observed', function (): void {
    $found = Trees::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())));

    expect(TreesFound::in(Observations::none()->withTrees($found)))->toEqual(Findings::none())
        ->and(TreesFound::in(Observations::none()))->toEqual(Findings::none());
});
