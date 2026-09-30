<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\Entry;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Doctor\Check\TreeFloors;
use NightWorksIO\MutationGate\Core\Doctor\Finding;
use NightWorksIO\MutationGate\Core\Doctor\Findings;
use NightWorksIO\MutationGate\Core\Doctor\Observations;
use NightWorksIO\MutationGate\Core\Doctor\ProjectFiles;
use NightWorksIO\MutationGate\Core\Doctor\Severity;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

$trees = Trees::of(
    Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())),
    Tree::at(Path::of('lib'), Undeclared::floor(), Package::at(Path::root())),
    Tree::at(Path::of('app'), Floor::of(80), Package::at(Path::root())),
    Tree::at(Path::of('legacy'), Exempt::because('generated'), Package::at(Path::root())),
);
$baseline = Baseline::of(Entry::of(Path::of('lib'), Floor::of(70)));
$observed = static fn(Paths $running, Baseline|CannotJudge $baseline): Observations => Observations::none()
    ->withTrees($trees)
    ->withFiles(ProjectFiles::none()->withRunningTheGate($running)->withBaseline($baseline));
$found = 'Neither the config nor the baseline gives a floor to src.';
$fix = 'Declare a floor for each, as trees: [{path: src, floor: 80}], or commit the baseline a CI run measures.';

it('fails a run where a CI definition runs the gate over a tree with no floor and no baseline', function () use ($observed, $baseline, $found, $fix): void {
    $running = Paths::of(Path::of('.github/workflows/mutation.yml'), Path::of('.gitlab-ci.yml'));

    expect(TreeFloors::in($observed($running, $baseline)))->toEqual(Findings::of(Finding::of(
        Slug::TreeWithoutFloor,
        Severity::WillFail,
        $found,
        'CI measures such a tree and then fails the run, since there is no default floor; .github/workflows/mutation.yml, .gitlab-ci.yml runs the gate.',
        $fix,
    )));
});

it('advises where no CI definition runs the gate, or none was read', function () use ($observed, $trees, $baseline, $found, $fix): void {
    expect(TreeFloors::in($observed(Paths::none(), $baseline)))->toEqual(Findings::of(Finding::of(
        Slug::TreeWithoutFloor,
        Severity::Advice,
        $found,
        'There is no default floor, so once a CI definition runs the gate, such a tree fails the run there.',
        $fix,
    )))->and([...TreeFloors::in(Observations::none()->withTrees($trees)->withFiles(ProjectFiles::none()->withBaseline($baseline)))][0]->severity())
        ->toBe(Severity::Advice);
});

it('finds nothing where every tree has a floor, the baseline cannot be read, or nothing was observed', function () use ($observed): void {
    $floored = Trees::of(Tree::at(Path::of('app'), Floor::of(80), Package::at(Path::root())));

    expect(TreeFloors::in(Observations::none()->withTrees($floored)->withFiles(ProjectFiles::none()->withBaseline(Baseline::none()))))->toEqual(Findings::none())
        ->and(TreeFloors::in($observed(Paths::none(), CannotJudge::because('unreadable'))))->toEqual(Findings::none())
        ->and(TreeFloors::in(Observations::none()))->toEqual(Findings::none());
});
