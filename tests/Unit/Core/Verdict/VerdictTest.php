<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Core\Verdict\Warnings;

$judged = static fn(Judgement $judgement): TreeVerdict => TreeVerdict::of(Tree::at(Path::of('src'), Undeclared::floor(), Package::at(Path::root())), NothingToMutate::found(), $judgement);

it('holds the trees, the mutants and the warnings', function () use ($judged): void {
    $trees = TreeVerdicts::of($judged(Judgement::Passed));
    $mutants = Mutants::none();
    $warnings = Warnings::of(Warning::that('src/Kernel.php is run by 412 of 430 tests and nothing holds it.'));
    $verdict = Verdict::of($trees, $mutants, $warnings);

    expect($verdict->trees())->toBe($trees)
        ->and($verdict->mutants())->toBe($mutants)
        ->and($verdict->warnings())->toBe($warnings);
});

it('fails when any tree failed, and passes otherwise', function (array $judgements, Judgement $whole) use ($judged): void {
    $trees = TreeVerdicts::none();

    foreach ($judgements as $judgement) {
        $trees = $judgement instanceof Judgement ? $trees->with($judged($judgement)) : $trees;
    }

    $verdict = Verdict::of($trees, Mutants::none(), Warnings::none());

    expect($verdict->judgement())->toBe($whole);
})->with([
    'no tree' => [[], Judgement::Passed],
    'every tree passed' => [[Judgement::Passed, Judgement::Passed], Judgement::Passed],
    'the last tree failed' => [[Judgement::Passed, Judgement::Failed], Judgement::Failed],
    'the first tree failed' => [[Judgement::Failed, Judgement::Passed], Judgement::Failed],
]);
