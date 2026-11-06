<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Recheck\Recheck;
use NightWorksIO\MutationGate\Core\Recheck\Rechecked;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** A mutant of the fixture judged this way, at this line. */
$judged = static fn(int $line, MutantJudgement $judgement): JudgedMutant => JudgedMutant::of(
    Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::None, ''),
    $judgement,
);

it('counts which still survive, which were killed, which are gone and which were not reached, in the order taken', function () use ($judged): void {
    $surviving = $judged(1, MutantJudgement::Survived);
    $uncovered = $judged(2, MutantJudgement::Uncovered);
    $lost = $judged(3, MutantJudgement::Survived);
    $late = $judged(5, MutantJudgement::Survived);
    $rechecked = Rechecked::of(
        Uncovered::Count,
        Recheck::found($judged(1, MutantJudgement::Survived), $surviving),
        Recheck::found($judged(4, MutantJudgement::Survived), $judged(4, MutantJudgement::Killed)),
        Recheck::gone($lost),
        Recheck::unreached($late),
        Recheck::found($judged(2, MutantJudgement::Uncovered), $uncovered),
    );

    expect($rechecked->surviving())->toBe([$surviving, $uncovered])
        ->and($rechecked->gone())->toBe([$lost])
        ->and($rechecked->unreached())->toBe([$late])
        ->and($rechecked->killed())->toBe(1)
        ->and(count($rechecked))->toBe(5)
        ->and($rechecked->uncovered())->toBe(Uncovered::Count)
        ->and(array_map(static fn(Recheck $recheck): int => $recheck->before()->mutant()->location()->start()->number(), [...$rechecked]))
        ->toBe([1, 4, 3, 5, 2]);
});

it('counts an uncovered mutant as killed where the score leaves uncovered mutants out', function () use ($judged): void {
    $rechecked = Rechecked::of(
        Uncovered::Exclude,
        Recheck::found($judged(2, MutantJudgement::Uncovered), $judged(2, MutantJudgement::Uncovered)),
    );

    expect([$rechecked->surviving(), $rechecked->killed()])->toBe([[], 1]);
});
