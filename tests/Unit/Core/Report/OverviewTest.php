<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\Overview;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdicts;
use NightWorksIO\MutationGate\Core\Verdict\Verdict;
use NightWorksIO\MutationGate\Tests\Support\Judged;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('scores the whole project over every tree\'s mutants', function (): void {
    expect(Overview::of(Verdicts::failing())->score())->toEqual(Score::ofHundredths(4_444))
        ->and(Overview::of(Verdicts::passing())->score())->toEqual(Score::ofHundredths(10_000))
        ->and(Overview::of(Verdicts::empty())->score())->toEqual(NothingToMutate::found());
});

it('counts uncovered mutants as the trees do', function (Uncovered $uncovered, int $hundredths): void {
    $tree = TreeVerdict::judged(
        Tree::at(Path::of('src'), Floor::of(50), Package::at(Path::root())),
        Unrecorded::floor(),
        JudgedUnits::none(),
        Judged::mutants(MutantJudgement::Killed, MutantJudgement::Uncovered),
        $uncovered,
    );
    $overview = Overview::of(Verdict::of(TreeVerdicts::of($tree)));

    expect($overview->uncovered())->toBe($uncovered)
        ->and($overview->score())->toEqual(Score::ofHundredths($hundredths));
})->with([
    'counted' => [Uncovered::Count, 5_000],
    'left out' => [Uncovered::Exclude, 10_000],
]);

it('counts uncovered mutants against the score where no tree says', function (): void {
    expect(Overview::of(Verdict::of(TreeVerdicts::none()))->uncovered())->toBe(Uncovered::Count);
});

it('lists every mutant counted as not killed, those on changed lines first', function (): void {
    $natives = [];

    foreach (Overview::of(Verdicts::failing())->survivors() as $mutant) {
        $natives[] = $mutant->mutant()->nativeId();
    }

    expect($natives)->toBe(['native-7', 'native-12', 'native-3', 'native-5', 'native-8']);
});

it('knows which mutants are in a set that failed, a tree or new code', function (): void {
    $failing = Verdicts::failing();
    $overview = Overview::of($failing);
    $passing = Overview::of(Verdicts::passing());
    $inNewCodeOnly = Verdicts::failing()->withNewCode(Verdicts::failing()->newCode());

    expect($overview->isFailing(Verdicts::survivor()))->toBeTrue()
        ->and($passing->isFailing(Verdicts::survivor()))->toBeFalse()
        ->and(Overview::of(Verdicts::of(Floor::of(80), Verdicts::survivor()))->isFailing(Verdicts::survivor()))->toBeTrue()
        ->and(Overview::of($inNewCodeOnly)->isFailing(Verdicts::survivor()))->toBeTrue();
});

it('knows a mutant in new code that failed is failing though its tree passed', function (): void {
    $passing = Verdicts::passing()->withNewCode(Verdicts::failing()->newCode());

    expect(Overview::of($passing)->isFailing(Verdicts::survivor()))->toBeTrue()
        ->and(Overview::of(Verdicts::passing())->isFailing(Verdicts::survivor()))->toBeFalse();
});

it('reads no mutant of an empty set as failing', function (): void {
    expect(Overview::of(Verdict::of(TreeVerdicts::none()))->survivors())->toEqual(Survivors::of());
});
