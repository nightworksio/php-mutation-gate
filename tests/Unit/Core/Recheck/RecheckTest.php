<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Recheck\Gone;
use NightWorksIO\MutationGate\Core\Recheck\Recheck;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

/** A mutant of the fixture judged this way, at this line. */
$judged = static fn(int $line, MutantJudgement $judgement): JudgedMutant => JudgedMutant::of(
    Verdicts::mutant(sprintf('src/Money.php:%d', $line), 'Plus', MutatorFamily::None, ''),
    $judgement,
);

it('keeps the survivor as the last run left it, and what running it again found', function () use ($judged): void {
    $before = $judged(1, MutantJudgement::Survived);
    $now = $judged(1, MutantJudgement::Killed);

    expect(Recheck::found($before, $now)->before())->toBe($before)
        ->and(Recheck::found($before, $now)->now())->toBe($now)
        ->and(Recheck::gone($before)->now())->toEqual(Gone::value());
});

it('says a survivor still survives where it was found again and the score counts it as not killed', function (
    MutantJudgement $now,
    Uncovered $uncovered,
    bool $survives,
) use ($judged): void {
    expect(Recheck::found($judged(1, MutantJudgement::Survived), $judged(1, $now))->stillSurvives($uncovered))
        ->toBe($survives);
})->with([
    'survived' => [MutantJudgement::Survived, Uncovered::Count, true],
    'uncovered, counted' => [MutantJudgement::Uncovered, Uncovered::Count, true],
    'uncovered, left out' => [MutantJudgement::Uncovered, Uncovered::Exclude, false],
    'killed' => [MutantJudgement::Killed, Uncovered::Count, false],
    'killed by timeout' => [MutantJudgement::KilledByTimeout, Uncovered::Count, false],
]);

it('never says a gone survivor still survives', function () use ($judged): void {
    expect(Recheck::gone($judged(1, MutantJudgement::Survived))->stillSurvives(Uncovered::Count))->toBeFalse();
});
