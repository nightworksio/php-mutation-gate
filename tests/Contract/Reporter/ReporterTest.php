<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;
use NightWorksIO\MutationGate\Tests\Support\Judged;

// What every reporter answers for a passing, a failing and an empty verdict:
// that it wrote, or why it did not, and never an exception. One line per
// implementation.

$reporters = [
    'the fake' => fn(): Reporter => new ReporterFake(),
];

$verdict = static fn(JudgedMutants $mutants): Verdict => Verdict::of(TreeVerdicts::of(TreeVerdict::judged(
    Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root())),
    Unrecorded::floor(),
    JudgedUnits::none(),
    $mutants,
    Uncovered::Count,
)));

it('reports a verdict of each kind, saying where it wrote or why it could not', function (Reporter $reporter, Verdict $verdict): void {
    $answer = $reporter->report($verdict);

    expect($answer instanceof Written ? $answer->where() : $answer->why())->not->toBe('');
})->with($reporters)->with([
    'passed' => [$verdict(Judged::mutants(MutantJudgement::Killed))],
    'failed' => [$verdict(Judged::mutants(MutantJudgement::Survived))],
    'nothing to mutate' => [$verdict(JudgedMutants::none())],
]);
