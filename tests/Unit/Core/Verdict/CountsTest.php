<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Uncovered;
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
    'no mutant' => [JudgedMutants::none()],
    'every mutant left out' => [Judged::mutants(MutantJudgement::Ignored, MutantJudgement::IgnoredByMarker, MutantJudgement::Uncovered)],
]);
