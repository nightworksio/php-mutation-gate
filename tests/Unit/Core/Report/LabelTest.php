<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Report\Label;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

it('prints each judgement in words', function (MutantJudgement $judgement, string $label): void {
    expect(Label::of($judgement))->toBe($label);
})->with([
    [MutantJudgement::Killed, 'killed'],
    [MutantJudgement::Errored, 'errored'],
    [MutantJudgement::KilledByTimeout, 'killed by timeout'],
    [MutantJudgement::Survived, 'survived'],
    [MutantJudgement::Uncovered, 'uncovered'],
    [MutantJudgement::Unjudged, 'unjudged'],
    [MutantJudgement::Flaky, 'flaky'],
    [MutantJudgement::TooSlowToJudge, 'too slow to judge'],
    [MutantJudgement::Ignored, 'ignored'],
    [MutantJudgement::IgnoredByMarker, 'ignored by a native marker'],
    [MutantJudgement::Equivalent, 'equivalent, proven'],
]);
