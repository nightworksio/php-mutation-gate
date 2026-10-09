<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\CheckAnswers;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;

it('answers each check by its place, and cannot judge one the analyser gave no answer in its place', function (): void {
    $answers = CheckAnswers::of(Findings::none(), OutOfScope::of(Path::of('lib/Other.php')));
    $unanswered = CannotJudge::because('The analyser gave no answer to the check.');

    expect([$answers->first(), $answers->at(1), $answers->at(2)])
        ->toEqual([Findings::none(), OutOfScope::of(Path::of('lib/Other.php')), $unanswered])
        ->and(CheckAnswers::of()->first())->toEqual($unanswered)
        ->and($answers)->toHaveCount(2);
});
