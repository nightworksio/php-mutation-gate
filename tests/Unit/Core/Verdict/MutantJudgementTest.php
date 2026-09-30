<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Scoring;

it('spells each judgement as the reports write it', function (): void {
    expect(array_map(static fn(MutantJudgement $judgement): string => $judgement->value, MutantJudgement::cases()))->toBe([
        'killed',
        'errored',
        'killed-by-timeout',
        'survived',
        'uncovered',
        'unjudged',
        'flaky',
        'too-slow-to-judge',
        'ignored',
        'ignored-by-marker',
        'equivalent',
    ]);
});

it('takes a reported status as it is, with a timeout a kill until triage says otherwise', function (MutantStatus $status, MutantJudgement $judgement): void {
    expect(MutantJudgement::reported($status))->toBe($judgement);
})->with([
    [MutantStatus::Killed, MutantJudgement::Killed],
    [MutantStatus::Survived, MutantJudgement::Survived],
    [MutantStatus::Uncovered, MutantJudgement::Uncovered],
    [MutantStatus::TimedOut, MutantJudgement::KilledByTimeout],
    [MutantStatus::Errored, MutantJudgement::Errored],
    [MutantStatus::Unjudged, MutantJudgement::Unjudged],
    [MutantStatus::IgnoredByMarker, MutantJudgement::IgnoredByMarker],
    [MutantStatus::Skipped, MutantJudgement::TooSlowToJudge],
]);

it('counts each judgement in the score as the floors decide', function (MutantJudgement $judgement, Scoring $counted, Scoring $excluded): void {
    expect($judgement->scoring(Uncovered::Count))->toBe($counted)
        ->and($judgement->scoring(Uncovered::Exclude))->toBe($excluded);
})->with([
    [MutantJudgement::Killed, Scoring::Killed, Scoring::Killed],
    [MutantJudgement::Errored, Scoring::Killed, Scoring::Killed],
    [MutantJudgement::KilledByTimeout, Scoring::Killed, Scoring::Killed],
    [MutantJudgement::Survived, Scoring::NotKilled, Scoring::NotKilled],
    [MutantJudgement::Uncovered, Scoring::NotKilled, Scoring::LeftOut],
    [MutantJudgement::Unjudged, Scoring::NotKilled, Scoring::NotKilled],
    [MutantJudgement::Flaky, Scoring::NotKilled, Scoring::NotKilled],
    [MutantJudgement::TooSlowToJudge, Scoring::NotKilled, Scoring::NotKilled],
    [MutantJudgement::Ignored, Scoring::LeftOut, Scoring::LeftOut],
    [MutantJudgement::IgnoredByMarker, Scoring::LeftOut, Scoring::LeftOut],
    [MutantJudgement::Equivalent, Scoring::LeftOut, Scoring::LeftOut],
]);
