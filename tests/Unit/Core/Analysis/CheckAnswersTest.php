<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\CheckAnswers;
use NightWorksIO\MutationGate\Core\Analysis\Findings;
use NightWorksIO\MutationGate\Core\Analysis\MutantCheck;
use NightWorksIO\MutationGate\Core\Analysis\MutantChecks;
use NightWorksIO\MutationGate\Core\Analysis\OutOfScope;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;

it('holds an answer in the place of each check, one that cannot judge where the analyser gave none, and the first of a batch of one', function (): void {
    $answers = CheckAnswers::of(Findings::none(), OutOfScope::of(Path::of('lib/Other.php')));
    $unanswered = CannotJudge::because('The analyser gave no answer to the check.');
    $check = MutantCheck::of(Path::of('src/Money.php'), Path::of('/tmp/mutant.php'));

    expect([...$answers->padded(MutantChecks::of($check, $check, $check))])
        ->toEqual([Findings::none(), OutOfScope::of(Path::of('lib/Other.php')), $unanswered])
        ->and([...$answers->padded(MutantChecks::of($check, $check))])->toEqual([...$answers])
        ->and($answers->first())->toEqual(Findings::none())
        ->and(CheckAnswers::of()->first())->toEqual($unanswered)
        ->and($answers)->toHaveCount(2);
});
