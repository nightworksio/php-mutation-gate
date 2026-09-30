<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unraised;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnit;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Tests\Support\Judged;

$tree = static fn(Floor|Exempt|Undeclared $declared): Tree => Tree::at(Path::of('app/Http'), $declared, Package::at(Path::root()));
$judged = static fn(
    Floor|Exempt|Undeclared $declared,
    Floor|Unrecorded $baseline,
    JudgedMutants $mutants,
): TreeVerdict => TreeVerdict::judged($tree($declared), $baseline, JudgedUnits::none(), $mutants, Uncovered::Count);

// Three killed of four: 75%.
$threeOfFour = Judged::mutants(MutantJudgement::Killed, MutantJudgement::Killed, MutantJudgement::Killed, MutantJudgement::Survived);

it('holds the tree, its baseline floor, its units and its mutants', function () use ($tree): void {
    $declared = $tree(Floor::of(80));
    $baseline = Floor::of(83.41);
    $units = JudgedUnits::of(JudgedUnit::of(Unit::file(Path::of('app/Http/Kernel.php')), Origin::Proved));
    $mutants = Judged::mutants(MutantJudgement::Killed);
    $verdict = TreeVerdict::judged($declared, $baseline, $units, $mutants, Uncovered::Exclude);

    expect($verdict->tree())->toBe($declared)
        ->and($verdict->baseline())->toBe($baseline)
        ->and($verdict->units())->toBe($units)
        ->and($verdict->mutants())->toBe($mutants)
        ->and($verdict->uncovered())->toBe(Uncovered::Exclude)
        ->and($verdict->counts()->number(MutantJudgement::Killed))->toBe(1);
});

it('is held to the higher of its declared floor and its baseline', function (
    Floor|Exempt|Undeclared $declared,
    Floor|Unrecorded $baseline,
    string $held,
) use ($judged): void {
    $floor = $judged($declared, $baseline, JudgedMutants::none())->floor();

    expect($floor)->toBe(['declared' => $declared, 'baseline' => $baseline][$held]);
})->with([
    'the declared floor higher' => [Floor::of(90), Floor::of(80), 'declared'],
    'the baseline higher' => [Floor::of(80), Floor::of(90), 'baseline'],
    'both the same' => [Floor::of(80), Floor::of(80), 'declared'],
    'only a declared floor' => [Floor::of(80), Unrecorded::floor(), 'declared'],
    'only a baseline' => [Undeclared::floor(), Floor::of(80), 'baseline'],
    'no floor anywhere' => [Undeclared::floor(), Unrecorded::floor(), 'declared'],
    'an exempt tree with a baseline' => [Exempt::because('Generated code'), Floor::of(80), 'declared'],
]);

it('scores its mutants, counting uncovered ones as it was told', function (): void {
    $mutants = Judged::mutants(MutantJudgement::Killed, MutantJudgement::Uncovered);
    $tree = Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root()));

    expect(TreeVerdict::judged($tree, Unrecorded::floor(), JudgedUnits::none(), $mutants, Uncovered::Count)->score())
        ->toEqual(Score::ofHundredths(5_000))
        ->and(TreeVerdict::judged($tree, Unrecorded::floor(), JudgedUnits::none(), $mutants, Uncovered::Exclude)->score())
        ->toEqual(Score::ofHundredths(10_000));
});

it('judges its score against the floor it is held to', function (
    Floor|Exempt|Undeclared $declared,
    Floor|Unrecorded $baseline,
    JudgedMutants $mutants,
    Judgement $judgement,
) use ($judged): void {
    expect($judged($declared, $baseline, $mutants)->judgement())->toBe($judgement);
})->with([
    'above the declared floor' => [Floor::of(70), Unrecorded::floor(), $threeOfFour, Judgement::Passed],
    'below a baseline above the declared floor' => [Floor::of(70), Floor::of(80), $threeOfFour, Judgement::Failed],
    'nothing to mutate' => [Floor::of(100), Floor::of(100), JudgedMutants::none(), Judgement::NothingToMutate],
    'exempt' => [Exempt::because('Generated code'), Unrecorded::floor(), JudgedMutants::none(), Judgement::Exempt],
]);

it('lists its survivors, counting uncovered ones as it was told', function () use ($tree): void {
    $mutants = Judged::mutants(MutantJudgement::Survived, MutantJudgement::Killed, MutantJudgement::Uncovered);
    $verdict = TreeVerdict::judged($tree(Floor::of(80)), Unrecorded::floor(), JudgedUnits::none(), $mutants, Uncovered::Exclude);

    expect(Judged::natives($verdict->survivors()))->toBe(['0']);
});

it('raises its baseline to a score above the floor it was held to, and to nothing else', function (
    Floor|Exempt|Undeclared $declared,
    Floor|Unrecorded $baseline,
    JudgedMutants $mutants,
    Floor|Unraised $raised,
) use ($judged): void {
    expect($judged($declared, $baseline, $mutants)->raised())->toEqual($raised);
})->with([
    'above the baseline' => [Floor::of(50), Floor::of(70), $threeOfFour, Floor::of(75)],
    'above a declared floor with no baseline' => [Floor::of(70), Unrecorded::floor(), $threeOfFour, Floor::of(75)],
    'with no floor anywhere' => [Undeclared::floor(), Unrecorded::floor(), $threeOfFour, Floor::of(75)],
    'at the baseline' => [Floor::of(50), Floor::of(75), $threeOfFour, Unraised::floor()],
    'at a declared floor with no baseline' => [Floor::of(75), Unrecorded::floor(), $threeOfFour, Unraised::floor()],
    'above the baseline but below the declared floor' => [Floor::of(80), Floor::of(70), $threeOfFour, Unraised::floor()],
    'with nothing to mutate' => [Undeclared::floor(), Unrecorded::floor(), JudgedMutants::none(), Unraised::floor()],
    'exempt' => [Exempt::because('Generated code'), Unrecorded::floor(), $threeOfFour, Unraised::floor()],
]);
