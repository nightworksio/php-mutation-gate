<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Tests\Support\Judged;

it('holds the package, its floor for new code and the mutants on changed lines', function (): void {
    $package = Package::at(Path::of('packages/billing'));
    $floor = Floor::of(100);
    $mutants = Judged::mutants(MutantJudgement::Killed);
    $verdict = NewCodeVerdict::judged($package, $floor, $mutants, Uncovered::Count);

    expect($verdict->package())->toBe($package)
        ->and($verdict->floor())->toBe($floor)
        ->and($verdict->mutants())->toBe($mutants)
        ->and($verdict->counts()->number(MutantJudgement::Killed))->toBe(1);
});

it('scores and judges its mutants against the floor for new code', function (
    Floor $floor,
    Uncovered $uncovered,
    JudgedMutants $mutants,
    Score|NothingToMutate $score,
    Judgement $judgement,
): void {
    $verdict = NewCodeVerdict::judged(Package::at(Path::root()), $floor, $mutants, $uncovered);

    expect($verdict->score())->toEqual($score)
        ->and($verdict->judgement())->toBe($judgement);
})->with([
    'every mutant killed' => [fn(): Floor => Floor::of(100), Uncovered::Count, fn(): JudgedMutants => Judged::mutants(MutantJudgement::Killed), fn(): Score => Score::ofHundredths(10_000), Judgement::Passed],
    'one survivor' => [fn(): Floor => Floor::of(100), Uncovered::Count, fn(): JudgedMutants => Judged::mutants(MutantJudgement::Killed, MutantJudgement::Survived), fn(): Score => Score::ofHundredths(5_000), Judgement::Failed],
    'uncovered left out' => [fn(): Floor => Floor::of(100), Uncovered::Exclude, fn(): JudgedMutants => Judged::mutants(MutantJudgement::Killed, MutantJudgement::Uncovered), fn(): Score => Score::ofHundredths(10_000), Judgement::Passed],
    'no mutable line' => [fn(): Floor => Floor::of(100), Uncovered::Count, fn(): JudgedMutants => JudgedMutants::none(), fn(): NothingToMutate => NothingToMutate::found(), Judgement::NothingToMutate],
]);

it('lists its survivors, counting uncovered ones as it was told', function (): void {
    $mutants = Judged::mutants(MutantJudgement::Uncovered, MutantJudgement::Survived);

    expect(Judged::natives(NewCodeVerdict::judged(Package::at(Path::root()), Floor::of(100), $mutants, Uncovered::Count)->survivors()))
        ->toBe(['0', '1'])
        ->and(Judged::natives(NewCodeVerdict::judged(Package::at(Path::root()), Floor::of(100), $mutants, Uncovered::Exclude)->survivors()))
        ->toBe(['1']);
});
