<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\Reporter;
use NightWorksIO\MutationGate\Tests\Fakes\ReporterFake;

// What every reporter answers for a passing, a failing and an empty verdict:
// that it wrote, or why it did not, and never an exception. One line per
// implementation.

$reporters = [
    'the fake' => fn(): Reporter => new ReporterFake(),
];

$tree = Tree::at(Path::of('src'), Floor::of(80), Package::at(Path::root()));

it('reports a verdict of each kind, saying where it wrote or why it could not', function (Reporter $reporter, Verdict $verdict): void {
    $answer = $reporter->report($verdict);

    expect($answer instanceof Written ? $answer->where() : $answer->why())->not->toBe('');
})->with($reporters)->with([
    'passed' => [Verdict::of(TreeVerdicts::of(TreeVerdict::of($tree, Score::ofHundredths(9_000), Judgement::Passed)), Mutants::none(), Warnings::none())],
    'failed' => [Verdict::of(TreeVerdicts::of(TreeVerdict::of($tree, Score::ofHundredths(7_000), Judgement::Failed)), Mutants::none(), Warnings::none())],
    'nothing to mutate' => [Verdict::of(TreeVerdicts::of(TreeVerdict::of($tree, NothingToMutate::found(), Judgement::Passed)), Mutants::none(), Warnings::none())],
]);
