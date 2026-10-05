<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\SurvivorsFirst;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** A mutant of the fixture judged this way, at this line. */
$judged = static fn(int $line, MutantJudgement $judgement): JudgedMutant => JudgedMutant::of(
    Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::None, ''),
    $judgement,
);

it('re-checks as many survivors as the pull request comment lists by default, and none at 0', function (): void {
    expect(SurvivorsFirst::standard()->most())->toBe(20)
        ->and(SurvivorsFirst::standard()->isOff())->toBeFalse()
        ->and(SurvivorsFirst::atMost(3)->most())->toBe(3)
        ->and(SurvivorsFirst::atMost(1)->isOff())->toBeFalse()
        ->and(SurvivorsFirst::atMost(0)->isOff())->toBeTrue();
});

it('takes the first survivors and uncovered mutants, in their order, never one unjudged, flaky or too slow', function () use ($judged): void {
    $survivors = Survivors::of(
        $judged(1, MutantJudgement::Unjudged),
        $judged(2, MutantJudgement::Survived),
        $judged(3, MutantJudgement::Flaky),
        $judged(4, MutantJudgement::Uncovered),
        $judged(5, MutantJudgement::TooSlowToJudge),
        $judged(6, MutantJudgement::Survived),
    );

    $lines = static fn(Survivors $taken): array => array_map(
        static fn(JudgedMutant $mutant): int => $mutant->mutant()->location()->start()->number(),
        [...$taken],
    );

    expect($lines(SurvivorsFirst::standard()->taken($survivors)))->toBe([2, 4, 6])
        ->and($lines(SurvivorsFirst::atMost(2)->taken($survivors)))->toBe([2, 4])
        ->and($lines(SurvivorsFirst::atMost(0)->taken($survivors)))->toBe([]);
});
