<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\LoweredFloor;
use NightWorksIO\MutationGate\Core\Alert\LoweredFloors;
use NightWorksIO\MutationGate\Core\Baseline\Lowered;
use NightWorksIO\MutationGate\Core\Baseline\Unlowered;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\TrendEntry;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;

$tree = static fn(string $path, Floor|Exempt $floor): TreeVerdict => TreeVerdict::judged(
    Tree::at(Path::of($path), $floor, Package::at(Path::root())),
    Unrecorded::floor(),
    JudgedUnits::none(),
    JudgedMutants::none(),
    Uncovered::Count,
);

it('finds each tree held to a floor below the one the entry recorded, with its reason where the baseline gives one', function () use ($tree): void {
    $lowered = Lowered::from(Floor::of(90), 'Legacy code joined the tree');
    $verdict = Verdict::of(TreeVerdicts::of(
        $tree('app', Floor::of(70))->withLowering($lowered),
        $tree('lib', Floor::of(60)),
        $tree('src', Floor::of(80)),
        $tree('new', Floor::of(10)),
        $tree('old', Exempt::because('Going away')),
    ));
    $entry = TrendEntry::none()
        ->withFloor(Path::of('app'), Floor::of(90))
        ->withFloor(Path::of('lib'), Floor::of(65))
        ->withFloor(Path::of('src'), Floor::of(80))
        ->withFloor(Path::of('old'), Floor::of(50));

    expect([...LoweredFloors::between($verdict, $entry)])->toEqual([
        LoweredFloor::of(Path::of('app'), Floor::of(90), Floor::of(70), $lowered),
        LoweredFloor::of(Path::of('lib'), Floor::of(65), Floor::of(60), Unlowered::floor()),
    ])
        ->and(LoweredFloors::between($verdict, TrendEntry::none()))->toHaveCount(0);
});
