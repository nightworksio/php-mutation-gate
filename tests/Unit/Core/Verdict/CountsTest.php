<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Verdict\Counts;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Judged;

it('counts the mutants that came to each judgement, and none for the rest', function (): void {
    $counts = Counts::of(Judged::mutants(MutantJudgement::Survived, MutantJudgement::Killed, MutantJudgement::Survived));

    expect($counts->number(MutantJudgement::Survived))->toBe(2)
        ->and($counts->number(MutantJudgement::Killed))->toBe(1)
        ->and($counts->number(MutantJudgement::Flaky))->toBe(0);
});

it('scores killed over counted, truncated to hundredths', function (): void {
    $mutants = Judged::mutants(
        MutantJudgement::Killed,
        MutantJudgement::Errored,
        MutantJudgement::KilledByTimeout,
        MutantJudgement::Survived,
        MutantJudgement::Unjudged,
        MutantJudgement::Flaky,
        MutantJudgement::TooSlowToJudge,
        MutantJudgement::Ignored,
        MutantJudgement::IgnoredByMarker,
    );

    expect(Counts::of($mutants)->score(Uncovered::Count))->toEqual(Score::ofHundredths(4_285));
});

it('counts uncovered mutants as not killed, or leaves them out', function (): void {
    $counts = Counts::of(Judged::mutants(MutantJudgement::Killed, MutantJudgement::Uncovered));

    expect($counts->score(Uncovered::Count))->toEqual(Score::ofHundredths(5_000))
        ->and($counts->score(Uncovered::Exclude))->toEqual(Score::ofHundredths(10_000));
});

it('has no score with nothing left to count', function (JudgedMutants $mutants): void {
    expect(Counts::of($mutants)->score(Uncovered::Exclude))->toEqual(NothingToMutate::found());
})->with([
    'no mutant' => [fn(): JudgedMutants => JudgedMutants::none()],
    'every mutant left out' => [fn(): JudgedMutants => Judged::mutants(MutantJudgement::Ignored, MutantJudgement::IgnoredByMarker, MutantJudgement::Uncovered)],
]);

it('says how many more of the counted mutants must be killed for the score to reach a floor, and none where it does', function (): void {
    $counts = Counts::of(Judged::mutants(
        MutantJudgement::Killed,
        MutantJudgement::Survived,
        MutantJudgement::Survived,
        MutantJudgement::Uncovered,
        MutantJudgement::Ignored,
    ));

    expect($counts->shortOf(Floor::of(80), Uncovered::Count))->toBe(3)
        ->and($counts->shortOf(Floor::of(66.66), Uncovered::Exclude))->toBe(1)
        ->and($counts->shortOf(Floor::of(66.67), Uncovered::Exclude))->toBe(2)
        ->and($counts->shortOf(Floor::of(25), Uncovered::Count))->toBe(0)
        ->and($counts->shortOf(Floor::of(10), Uncovered::Count))->toBe(0);
});
